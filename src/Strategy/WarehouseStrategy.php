<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * One delivery per source warehouse (SwagCommercial MultiWarehouse).
 *
 * Mirrors MultiWarehouseStockUpdater::calculateOrderStocks(): the first
 * rule-matching warehouse group with stock, then warehouses by priority,
 * greedily; the remainder goes to the last warehouse with stock, and if all
 * are empty the first warehouse takes everything. The cart is recalculated
 * right before the order is written, so this predicts the allocation
 * MultiWarehouse makes in the same request.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class WarehouseStrategy implements DeliverySplitStrategyInterface
{
    public const NAME = 'warehouse';

    private const LICENSE_TOGGLE = 'MULTI_INVENTORY-3749997';
    private const LICENSE_CLASS = 'Shopware\\Commercial\\Licensing\\License';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isAvailable(SalesChannelContext $context): bool
    {
        if (!class_exists(self::LICENSE_CLASS) || !\call_user_func([self::LICENSE_CLASS, 'get'], self::LICENSE_TOGGLE)) {
            return false;
        }

        try {
            return (bool) $this->connection->fetchOne('SELECT 1 FROM `warehouse` LIMIT 1');
        } catch (\Throwable) {
            return false;
        }
    }

    public function allowsManualMoves(): bool
    {
        return false;
    }

    public function allowsAdjustments(): bool
    {
        // No adjustments: the warehouse decides what ships where.
        return false;
    }

    public function allocate(array $roots, DeliveryPlan $plan, SalesChannelContext $context): Allocation
    {
        $groups = [DeliveryPlan::MAIN_GROUP => new DeliveryGroup(DeliveryPlan::MAIN_GROUP, 'main')];
        $quantities = [];

        // MultiWarehouse allocates per product, summed over all line items.
        $productRoots = [];
        foreach ($roots as $rootId => $root) {
            $productId = $root->lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE ? $root->lineItem->getReferencedId() : null;
            if ($productId === null || !$root->isSplittable()) {
                $quantities[$rootId] = [DeliveryPlan::MAIN_GROUP => $root->getQuantity()];
                continue;
            }
            $productRoots[$productId][] = $root;
        }

        if ($productRoots === [] || !$this->isAvailable($context)) {
            foreach ($productRoots as $list) {
                foreach ($list as $root) {
                    $quantities[$root->getId()] = [DeliveryPlan::MAIN_GROUP => $root->getQuantity()];
                }
            }

            return new Allocation($groups, $quantities);
        }

        $warehouses = $this->loadWarehouseStocks(array_keys($productRoots), $context);
        $names = $this->loadWarehouseNames($warehouses);

        foreach ($productRoots as $productId => $list) {
            $total = array_sum(array_map(static fn (RootItem $root) => $root->getQuantity(), $list));
            $perWarehouse = isset($warehouses[$productId]) ? $this->allocateProduct($total, $warehouses[$productId]) : [];

            // Hand the warehouse quantities out to the product's line items in cart order.
            foreach ($list as $root) {
                $left = $root->getQuantity();
                foreach ($perWarehouse as $warehouseId => $available) {
                    if ($left === 0) {
                        break;
                    }
                    $take = min($left, $available);
                    if ($take <= 0) {
                        continue;
                    }
                    $groupId = 'wh-' . $warehouseId;
                    $groups[$groupId] ??= new DeliveryGroup($groupId, $names[$warehouseId] ?? $warehouseId, true, false, $warehouseId, $names[$warehouseId] ?? null);
                    $quantities[$root->getId()][$groupId] = $take;
                    $perWarehouse[$warehouseId] -= $take;
                    $left -= $take;
                }
                if ($left > 0) {
                    $quantities[$root->getId()][DeliveryPlan::MAIN_GROUP] = ($quantities[$root->getId()][DeliveryPlan::MAIN_GROUP] ?? 0) + $left;
                }
            }
        }

        return new Allocation($groups, $quantities);
    }

    /**
     * @param array<string, int> $warehouses warehouse id => stock, in priority order
     *
     * @return array<string, int> warehouse id => quantity
     */
    private function allocateProduct(int $quantity, array $warehouses): array
    {
        if ($warehouses === []) {
            return [];
        }

        $withStock = array_filter($warehouses, static fn (int $stock) => $stock > 0);
        if ($withStock === []) {
            return [(string) array_key_first($warehouses) => $quantity];
        }

        $result = [];
        $left = $quantity;
        $lastId = array_key_last($withStock);
        foreach ($withStock as $warehouseId => $stock) {
            if ($left <= 0) {
                break;
            }
            $take = $warehouseId === $lastId ? $left : min($left, $stock);
            $result[(string) $warehouseId] = $take;
            $left -= $take;
        }

        return $result;
    }

    /**
     * Same query and group selection as MultiWarehouseStockUpdater.
     *
     * @param list<string> $productIds
     *
     * @return array<string, array<string, int>> product id => [warehouse id => stock]
     */
    private function loadWarehouseStocks(array $productIds, SalesChannelContext $context): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(product.id)) AS product_id,
                       LOWER(HEX(product_warehouse_group.warehouse_group_id)) AS warehouse_group_id,
                       LOWER(HEX(warehouse_group_warehouse.warehouse_id)) AS warehouse_id,
                       COALESCE(product_warehouse.stock, 0) AS stock,
                       (warehouse_group.id IS NOT NULL) AS assigned
                FROM product
                INNER JOIN product_warehouse_group
                    ON product.id = product_warehouse_group.product_id
                    AND product.version_id = product_warehouse_group.product_version_id
                LEFT JOIN warehouse_group
                    ON product_warehouse_group.warehouse_group_id = warehouse_group.id
                    AND warehouse_group.rule_id IN (:ruleIds)
                LEFT JOIN warehouse_group_warehouse
                    ON warehouse_group_warehouse.warehouse_group_id = product_warehouse_group.warehouse_group_id
                LEFT JOIN product_warehouse
                    ON product_warehouse.product_id = product_warehouse_group.product_id
                    AND product_warehouse.product_version_id = product_warehouse_group.product_version_id
                    AND product_warehouse.warehouse_id = warehouse_group_warehouse.warehouse_id
                WHERE product.id IN (:ids) AND product.version_id = :version
                ORDER BY warehouse_group.priority DESC, warehouse_group_warehouse.priority DESC
            SQL,
            [
                'ids' => Uuid::fromHexToBytesList($productIds),
                'ruleIds' => Uuid::fromHexToBytesList($context->getRuleIds()),
                'version' => Uuid::fromHexToBytes($context->getContext()->getVersionId()),
            ],
            ['ids' => ArrayParameterType::STRING, 'ruleIds' => ArrayParameterType::STRING]
        );

        $byProduct = [];
        foreach ($rows as $row) {
            if (!$row['warehouse_group_id'] || !$row['warehouse_id']) {
                continue;
            }
            $byProduct[$row['product_id']][] = $row;
        }

        $result = [];
        foreach ($byProduct as $productId => $productRows) {
            $selectedGroup = null;
            foreach ($productRows as $row) {
                if ((int) $row['stock'] > 0 && $row['assigned']) {
                    $selectedGroup = $row['warehouse_group_id'];
                    break;
                }
            }
            $selectedGroup ??= $productRows[0]['warehouse_group_id'];

            foreach ($productRows as $row) {
                if ($row['warehouse_group_id'] === $selectedGroup) {
                    $result[$productId][$row['warehouse_id']] = (int) $row['stock'];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, int>> $warehouses
     *
     * @return array<string, string>
     */
    private function loadWarehouseNames(array $warehouses): array
    {
        $ids = [];
        foreach ($warehouses as $stocks) {
            foreach (array_keys($stocks) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), name FROM `warehouse` WHERE id IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList(array_keys($ids))],
            ['ids' => ArrayParameterType::STRING]
        );

        return $names;
    }
}

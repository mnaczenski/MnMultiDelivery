<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Service;

use Mn\MultiDelivery\SeparateOrder\SeparateOrderCartOrderRoute;
use Mn\MultiDelivery\Subscriber\OrderConversionSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Registers customFields.mn_multi_delivery of order deliveries as a
 * custom field set, so the field is typed and listed under Settings ›
 * Custom fields. Its value is an object: group, label, warehouseId,
 * warehouseName.
 *
 * @internal
 */
class CustomFieldInstaller
{
    public const SET_NAME = 'mn_multi_delivery';

    /** Orders placed together (deliveries to other countries), on the order. */
    public const ORDER_SET_NAME = 'mn_multi_delivery_order';

    /**
     * @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $customFieldSetRepository
     */
    public function __construct(private readonly EntityRepository $customFieldSetRepository)
    {
    }

    public function install(Context $context): void
    {
        $this->customFieldSetRepository->upsert([[
            'id' => Uuid::fromStringToHex(self::SET_NAME . '.set'),
            'name' => self::SET_NAME,
            'config' => ['label' => ['en-GB' => 'Multi Delivery', 'de-DE' => 'Mehrfachlieferung']],
            'active' => true,
            'relations' => [[
                'id' => Uuid::fromStringToHex(self::SET_NAME . '.relation'),
                'entityName' => 'order_delivery',
            ]],
            'customFields' => [[
                'id' => Uuid::fromStringToHex(self::SET_NAME . '.field'),
                'name' => OrderConversionSubscriber::CUSTOM_FIELD,
                'type' => CustomFieldTypes::JSON,
                'config' => [
                    'label' => ['en-GB' => 'Delivery group (group, label, warehouseId, warehouseName)', 'de-DE' => 'Lieferungsgruppe (group, label, warehouseId, warehouseName)'],
                    'componentName' => 'sw-code-editor',
                ],
            ]],
        ], [
            'id' => Uuid::fromStringToHex(self::ORDER_SET_NAME . '.set'),
            'name' => self::ORDER_SET_NAME,
            'config' => ['label' => ['en-GB' => 'Multi Delivery – orders placed together', 'de-DE' => 'Mehrfachlieferung – gemeinsam aufgegebene Bestellungen']],
            'active' => true,
            'relations' => [[
                'id' => Uuid::fromStringToHex(self::ORDER_SET_NAME . '.relation'),
                'entityName' => 'order',
            ]],
            'customFields' => [[
                'id' => Uuid::fromStringToHex(self::ORDER_SET_NAME . '.field'),
                'name' => SeparateOrderCartOrderRoute::CUSTOM_FIELD,
                'type' => CustomFieldTypes::JSON,
                'config' => [
                    'label' => ['en-GB' => 'Orders of the same checkout (group, orders)', 'de-DE' => 'Bestellungen desselben Checkouts (group, orders)'],
                    'componentName' => 'sw-code-editor',
                ],
            ]],
        ]], $context);
    }

    public function uninstall(Context $context): void
    {
        $this->customFieldSetRepository->delete([
            ['id' => Uuid::fromStringToHex(self::SET_NAME . '.set')],
            ['id' => Uuid::fromStringToHex(self::ORDER_SET_NAME . '.set')],
        ], $context);
    }
}

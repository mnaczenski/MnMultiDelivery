<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Rule;

use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Content\Flow\Rule\FlowRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * Rule Builder condition "Number of deliveries": matches carts (and, in
 * flows, orders) by how many deliveries they have. "Greater than 1" means
 * the order is split. Usable for shipping prices, promotions, payment
 * availability and flows.
 *
 * Shopware evaluates cart rules after calculating the cart, so the split
 * deliveries are counted.
 *
 * Public API: stable within a major version.
 */
class DeliveryCountRule extends Rule
{
    final public const RULE_NAME = 'mnMultiDeliveryCount';

    protected int $count = 1;

    protected string $operator = self::OPERATOR_GT;

    public function getName(): string
    {
        return self::RULE_NAME;
    }

    public function match(RuleScope $scope): bool
    {
        if ($scope instanceof FlowRuleScope) {
            $deliveries = $scope->getOrder()->getDeliveries()?->count() ?? 0;
        } elseif ($scope instanceof CartRuleScope) {
            $deliveries = $scope->getCart()->getDeliveries()->count();
        } else {
            return false;
        }

        return RuleComparison::numeric((float) $deliveries, (float) $this->count, $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'count' => RuleConstraints::int(),
            'operator' => RuleConstraints::numericOperators(false),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_NUMBER)
            ->intField('count');
    }
}

<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The registered strategies. The merchant's "Rules customers can choose"
 * setting limits the built-in ones; strategies from other plugins decide
 * with isAvailable(). Unknown or unavailable names fall back to the default
 * strategy.
 *
 * @internal
 */
class StrategyRegistry
{
    /** Names the "Rules customers can choose" setting applies to. */
    public const BUILT_IN = [ManualStrategy::NAME, AvailabilityStrategy::NAME, DeliveryTimeStrategy::NAME, WarehouseStrategy::NAME];

    /** @var array<string, DeliverySplitStrategyInterface> */
    private array $strategies = [];

    /**
     * @param iterable<DeliverySplitStrategyInterface> $strategies
     */
    public function __construct(
        iterable $strategies,
        private readonly SplitSettings $settings,
    ) {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->getName()] = $strategy;
        }
    }

    public function get(string $name, SalesChannelContext $context): DeliverySplitStrategyInterface
    {
        $available = $this->availableNames($context);
        foreach ([$name, $this->settings->defaultStrategy($context), $available[0] ?? null] as $candidate) {
            if ($candidate !== null && \in_array($candidate, $available, true)) {
                return $this->strategies[$candidate];
            }
        }

        return $this->strategies[ManualStrategy::NAME];
    }

    /**
     * @return list<string>
     */
    public function availableNames(SalesChannelContext $context): array
    {
        $allowed = $this->settings->allowedStrategies($context);
        $names = [];
        foreach ($this->strategies as $name => $strategy) {
            $offered = $allowed === null || !\in_array($name, self::BUILT_IN, true) || \in_array($name, $allowed, true);
            if ($offered && $strategy->isAvailable($context)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

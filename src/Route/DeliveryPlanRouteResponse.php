<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Route;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * Response of AbstractDeliveryPlanRoute::load(); decorators return it too.
 *
 * Public API: stable within a major version.
 *
 * @extends StoreApiResponse<ArrayStruct<array<string, mixed>>>
 */
class DeliveryPlanRouteResponse extends StoreApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        parent::__construct(new ArrayStruct($data, 'mn_multi_delivery'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        /** @var ArrayStruct<array<string, mixed>> $struct */
        $struct = $this->getObject();

        return $struct->all();
    }
}

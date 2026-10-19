<?php

namespace Tests\Unit\Resources\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Resources\Order\OrderResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    private function createOrderDomainObject(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(1)
            ->setEventId(10)
            ->setShortId('o_abc123')
            ->setPublicId('ORD-123')
            ->setCurrency('USD')
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setTotalGross(25.0)
            ->setCreatedAt('2026-01-01 10:00:00')
            ->setUpdatedAt('2026-01-02 11:30:00');
    }

    public function test_order_resource_includes_created_at_and_updated_at(): void
    {
        $resource = (new OrderResource($this->createOrderDomainObject()))->toArray(Request::create('/'));

        $this->assertSame('2026-01-01 10:00:00', $resource['created_at']);
        $this->assertArrayHasKey('updated_at', $resource);
        $this->assertSame('2026-01-02 11:30:00', $resource['updated_at']);
    }

    public function test_order_resource_returns_null_updated_at_when_not_set(): void
    {
        $order = $this->createOrderDomainObject()->setUpdatedAt(null);

        $resource = (new OrderResource($order))->toArray(Request::create('/'));

        $this->assertArrayHasKey('updated_at', $resource);
        $this->assertNull($resource['updated_at']);
    }
}

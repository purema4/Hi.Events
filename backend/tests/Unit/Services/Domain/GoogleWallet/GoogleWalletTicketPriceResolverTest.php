<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletTicketPriceResolver;
use Mockery;
use Tests\TestCase;

class GoogleWalletTicketPriceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)->setId(1)->setCurrency('USD');
    }

    public function test_it_formats_the_price_paid_for_the_ticket(): void
    {
        $attendee = (new AttendeeDomainObject)
            ->setOrderId(7)
            ->setProductPriceId(3)
            ->setEventOccurrenceId(9);

        $repository = Mockery::mock(OrderItemRepositoryInterface::class);
        $repository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['order_id' => 7, 'product_price_id' => 3, 'event_occurrence_id' => 9])
            ->andReturn((new OrderItemDomainObject)->setPrice(25));

        $resolver = new GoogleWalletTicketPriceResolver($repository, $this->app->make('translator'));

        $this->assertSame('$25.00', $resolver->resolve($attendee, $this->event()));
    }

    public function test_a_single_event_ticket_is_matched_without_an_occurrence(): void
    {
        $attendee = (new AttendeeDomainObject)
            ->setOrderId(7)
            ->setProductPriceId(3)
            ->setEventOccurrenceId(null);

        $repository = Mockery::mock(OrderItemRepositoryInterface::class);
        $repository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['order_id' => 7, 'product_price_id' => 3])
            ->andReturn((new OrderItemDomainObject)->setPrice(0));

        $resolver = new GoogleWalletTicketPriceResolver($repository, $this->app->make('translator'));

        $this->assertSame('$0.00', $resolver->resolve($attendee, $this->event()));
    }

    public function test_there_is_no_price_when_the_order_line_is_missing(): void
    {
        $attendee = (new AttendeeDomainObject)->setOrderId(7)->setProductPriceId(3)->setEventOccurrenceId(null);

        $repository = Mockery::mock(OrderItemRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn(null);

        $resolver = new GoogleWalletTicketPriceResolver($repository, $this->app->make('translator'));

        $this->assertNull($resolver->resolve($attendee, $this->event()));
    }
}

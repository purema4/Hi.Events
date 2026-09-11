<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicOrganizerEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventsHandler;
use HiEvents\Services\Domain\Product\ProductFilterService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class GetPublicEventsHandlerTest extends TestCase
{
    private const ORGANIZER_ID = 7;

    private const OWNER_ACCOUNT_ID = 42;

    private EventRepositoryInterface $eventRepository;

    private OrganizerRepositoryInterface $organizerRepository;

    private ProductFilterService $productFilterService;

    private GetPublicEventsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $this->productFilterService = m::mock(ProductFilterService::class);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->handler = new GetPublicEventsHandler(
            $this->eventRepository,
            $this->organizerRepository,
            $this->productFilterService,
        );
    }

    public function test_handle_applies_tax_and_fee_totals_to_public_event_listing(): void
    {
        $event = $this->makeEventWithProductPrice();
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEvents')
            ->once()
            ->andReturn($this->paginate(collect([$event])));

        $this->productFilterService
            ->shouldReceive('filter')
            ->once()
            ->andReturnUsing(fn (Collection $categories) => $this->withCalculatedTaxAndFees($categories));

        $result = $this->handler->handle($this->makeDto());

        $price = $this->firstPriceOf($result->getCollection()->first());

        $this->assertSame(5.0, $price->getTaxTotal());
        $this->assertSame(2.5, $price->getFeeTotal());
        $this->assertSame(100.0, $price->getPrice());
    }

    public function test_handle_queries_live_events_for_unauthenticated_visitors(): void
    {
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEvents')
            ->once()
            ->with(
                m::on(static fn (array $where) => $where['organizer_id'] === self::ORGANIZER_ID
                    && $where['status'] === EventStatus::LIVE->name),
                m::type(QueryParamsDTO::class),
            )
            ->andReturn($this->paginate(collect()));

        $this->productFilterService->shouldNotReceive('filter');

        $this->handler->handle($this->makeDto());
    }

    public function test_handle_applies_tax_and_fee_totals_for_the_owning_organizer(): void
    {
        $event = $this->makeEventWithProductPrice();
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEventsForOrganizer')
            ->once()
            ->with(self::ORGANIZER_ID, self::OWNER_ACCOUNT_ID, m::type(QueryParamsDTO::class))
            ->andReturn($this->paginate(collect([$event])));
        $this->eventRepository->shouldNotReceive('findEvents');

        $this->productFilterService
            ->shouldReceive('filter')
            ->once()
            ->andReturnUsing(fn (Collection $categories) => $this->withCalculatedTaxAndFees($categories));

        $result = $this->handler->handle($this->makeDto(self::OWNER_ACCOUNT_ID));

        $this->assertSame(5.0, $this->firstPriceOf($result->getCollection()->first())->getTaxTotal());
    }

    public function test_handle_filters_every_event_in_the_page(): void
    {
        $events = collect([
            $this->makeEventWithProductPrice(1),
            $this->makeEventWithProductPrice(2),
            $this->makeEventWithProductPrice(3),
        ]);
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEvents')
            ->once()
            ->andReturn($this->paginate($events));

        $this->productFilterService
            ->shouldReceive('filter')
            ->times(3)
            ->andReturnUsing(fn (Collection $categories) => $this->withCalculatedTaxAndFees($categories));

        $result = $this->handler->handle($this->makeDto());

        $result->getCollection()->each(function (EventDomainObject $event): void {
            $this->assertSame(5.0, $this->firstPriceOf($event)->getTaxTotal());
        });
    }

    public function test_handle_passes_the_events_own_categories_to_the_filter_service(): void
    {
        $event = $this->makeEventWithProductPrice();
        $categories = $event->getProductCategories();
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEvents')
            ->once()
            ->andReturn($this->paginate(collect([$event])));

        $this->productFilterService
            ->shouldReceive('filter')
            ->once()
            ->with(m::on(static fn (Collection $passed) => $passed === $categories))
            ->andReturn($categories);

        $this->handler->handle($this->makeDto());
    }

    public function test_handle_skips_events_without_loaded_product_categories(): void
    {
        $event = new EventDomainObject;
        $this->setupOrganizer();
        $this->eventRepository
            ->shouldReceive('findEvents')
            ->once()
            ->andReturn($this->paginate(collect([$event])));

        $this->productFilterService->shouldNotReceive('filter');

        $result = $this->handler->handle($this->makeDto());

        $this->assertNull($result->getCollection()->first()->getProductCategories());
    }

    public function test_handle_returns_the_paginator_with_its_metadata_intact(): void
    {
        $this->setupOrganizer();
        $paginator = new LengthAwarePaginator(collect([$this->makeEventWithProductPrice()]), 31, 25, 2);
        $this->eventRepository->shouldReceive('findEvents')->once()->andReturn($paginator);
        $this->productFilterService
            ->shouldReceive('filter')
            ->once()
            ->andReturnUsing(fn (Collection $categories) => $categories);

        $result = $this->handler->handle($this->makeDto());

        $this->assertSame(31, $result->total());
        $this->assertSame(25, $result->perPage());
        $this->assertSame(2, $result->currentPage());
    }

    private function setupOrganizer(): void
    {
        $organizer = (new OrganizerDomainObject)
            ->setId(self::ORGANIZER_ID)
            ->setAccountId(self::OWNER_ACCOUNT_ID);

        $this->organizerRepository
            ->shouldReceive('findById')
            ->with(self::ORGANIZER_ID)
            ->andReturn($organizer);
    }

    private function makeDto(?int $authenticatedAccountId = null): GetPublicOrganizerEventsDTO
    {
        return new GetPublicOrganizerEventsDTO(
            organizerId: self::ORGANIZER_ID,
            queryParams: new QueryParamsDTO,
            authenticatedAccountId: $authenticatedAccountId,
        );
    }

    private function makeEventWithProductPrice(int $eventId = 1): EventDomainObject
    {
        $price = (new ProductPriceDomainObject)
            ->setId($eventId * 100)
            ->setProductId($eventId * 10)
            ->setPrice(100.0);

        $product = (new ProductDomainObject)
            ->setId($eventId * 10)
            ->setEventId($eventId)
            ->setProductCategoryId($eventId)
            ->setProductPrices(collect([$price]));

        $category = (new ProductCategoryDomainObject)->setId($eventId);
        $category->setProducts(collect([$product]));

        return (new EventDomainObject)
            ->setId($eventId)
            ->setProductCategories(collect([$category]));
    }

    private function withCalculatedTaxAndFees(Collection $categories): Collection
    {
        $categories->each(
            static fn (ProductCategoryDomainObject $category) => $category->getProducts()->each(
                static fn (ProductDomainObject $product) => $product->getProductPrices()->each(
                    static fn (ProductPriceDomainObject $price) => $price->setTaxTotal(5.0)->setFeeTotal(2.5)
                )
            )
        );

        return $categories;
    }

    private function firstPriceOf(EventDomainObject $event): ProductPriceDomainObject
    {
        return $event->getProductCategories()->first()->getProducts()->first()->getProductPrices()->first();
    }

    private function paginate(Collection $events): LengthAwarePaginator
    {
        return new LengthAwarePaginator($events, $events->count(), 25, 1);
    }
}

<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassImageBuilder;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassJsonBuilder;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassPackager;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AppleWalletPassServiceTest extends TestCase
{
    private const WHERE = ['order_id' => 20];

    private AppleWalletPassSettingsResolver|MockInterface $passSettingsResolver;

    private AppleWalletPassJsonBuilder|MockInterface $passJsonBuilder;

    private AppleWalletPassImageBuilder|MockInterface $imageBuilder;

    private AppleWalletPassPackager|MockInterface $packager;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private AppleWalletPassService $service;

    private EventDomainObject $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passSettingsResolver = Mockery::mock(AppleWalletPassSettingsResolver::class);
        $this->passJsonBuilder = Mockery::mock(AppleWalletPassJsonBuilder::class);
        $this->imageBuilder = Mockery::mock(AppleWalletPassImageBuilder::class);
        $this->packager = Mockery::mock(AppleWalletPassPackager::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);

        $this->service = new AppleWalletPassService(
            $this->passSettingsResolver,
            new AppleWalletSerialNumberService(new Repository(['apple-wallet' => ['serial_prefix' => 'hievents']])),
            $this->passJsonBuilder,
            $this->imageBuilder,
            $this->packager,
            $this->attendeeRepository,
            $this->eventRepository,
        );

        $this->event = (new EventDomainObject)
            ->setId(10)
            ->setOrganizerId(5)
            ->setOrganizer((new OrganizerDomainObject)->setId(5))
            ->setEventOccurrences(collect([(new EventOccurrenceDomainObject)->setId(3)]));

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findFirst')->with(10)->andReturn($this->event)->byDefault();
        $this->passSettingsResolver
            ->shouldReceive('resolveForOrganizer')
            ->with(5)
            ->andReturn(new WalletPassBrandingDTO(logoUrl: null, bannerImageUrl: null, appleStripImageUrl: null, backgroundColor: null, themeAccentColor: null))
            ->byDefault();
        $this->imageBuilder->shouldReceive('build')->andReturn(['icon.png' => 'icon'])->byDefault();
        $this->passJsonBuilder->shouldReceive('build')->andReturn(['formatVersion' => 1])->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function attendees(int ...$attendeeIds): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with(self::WHERE)
            ->andReturn(collect(array_map(
                fn (int $attendeeId) => $this->attendee($attendeeId),
                $attendeeIds,
            )));
    }

    private function attendee(int $attendeeId, ?string $passUpdatedAt = null, string $updatedAt = '2026-09-01 10:00:00'): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($attendeeId)
            ->setEventId(10)
            ->setLocale('en')
            ->setUpdatedAt($updatedAt)
            ->setAppleWalletPassUpdatedAt($passUpdatedAt);
    }

    public function test_one_ticket_is_downloaded_as_a_single_pass(): void
    {
        $this->attendees(31);
        $this->packager->shouldReceive('package')->once()->with(['formatVersion' => 1], ['icon.png' => 'icon'])->andReturn('pkpass-31');

        $pass = $this->service->generate(self::WHERE);

        $this->assertSame('pkpass-31', $pass->contents);
        $this->assertSame('application/vnd.apple.pkpass', $pass->mimeType);
        $this->assertSame('hievents-attendee-31.pkpass', $pass->filename);
    }

    public function test_several_tickets_are_downloaded_as_one_bundle_sharing_the_same_images(): void
    {
        $this->attendees(31, 32);
        $this->imageBuilder->shouldReceive('build')->once()->andReturn(['icon.png' => 'icon']);
        $this->packager->shouldReceive('package')->twice()->andReturn('pkpass-31', 'pkpass-32');
        $this->packager
            ->shouldReceive('bundle')
            ->once()
            ->with(['hievents-attendee-31.pkpass' => 'pkpass-31', 'hievents-attendee-32.pkpass' => 'pkpass-32'])
            ->andReturn('bundle');

        $pass = $this->service->generate(self::WHERE);

        $this->assertSame('bundle', $pass->contents);
        $this->assertSame('application/vnd.apple.pkpasses', $pass->mimeType);
        $this->assertSame('tickets.pkpasses', $pass->filename);
    }

    public function test_the_pass_is_last_modified_when_its_latest_ticket_changed(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')->with(self::WHERE)->andReturn(collect([
            $this->attendee(31, passUpdatedAt: '2026-09-12 08:30:00'),
            $this->attendee(32, updatedAt: '2026-09-10 09:00:00'),
        ]));
        $this->packager->shouldReceive('package')->andReturn('pkpass');
        $this->packager->shouldReceive('bundle')->andReturn('bundle');

        $pass = $this->service->generate(self::WHERE);

        $this->assertSame('2026-09-12 08:30:00', $pass->lastModified->format('Y-m-d H:i:s'));
    }

    public function test_a_ticket_for_a_single_date_event_is_shown_with_that_date(): void
    {
        $this->attendees(31);
        $this->packager->shouldReceive('package')->andReturn('pkpass');

        $this->passJsonBuilder
            ->shouldReceive('build')
            ->once()
            ->withArgs(fn (AttendeeDomainObject $attendee, EventDomainObject $event, ?EventOccurrenceDomainObject $occurrence) => $occurrence?->getId() === 3)
            ->andReturn(['formatVersion' => 1]);

        $this->service->generate(self::WHERE);
    }

    public function test_no_pass_is_built_when_the_organizer_has_not_enabled_apple_wallet(): void
    {
        $this->attendees(31);
        $this->passSettingsResolver->shouldReceive('resolveForOrganizer')->with(5)->andReturn(null);
        $this->packager->shouldNotReceive('package');

        $this->assertNull($this->service->generate(self::WHERE));
    }

    public function test_no_pass_is_built_without_matching_tickets(): void
    {
        $this->attendees();
        $this->eventRepository->shouldNotReceive('findFirst');

        $this->assertNull($this->service->generate(self::WHERE));
    }
}

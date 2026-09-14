<?php

namespace Tests\Unit\Console\Commands;

use HiEvents\Console\Commands\PreviewGoogleWalletPassCommand;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlService;
use HiEvents\Services\Domain\GoogleWallet\SyncGoogleWalletPassesService;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PreviewGoogleWalletPassCommandTest extends TestCase
{
    private const ATTENDEE_ID = 31;

    private const ORDER_ID = 20;

    private const EVENT_ID = 10;

    private const ORGANIZER_ID = 5;

    private const SAVE_URL = 'https://pay.google.com/gp/v/save/token';

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private GoogleWalletPassSettingsResolver|MockInterface $passSettingsResolver;

    private SyncGoogleWalletPassesService|MockInterface $syncService;

    private GoogleWalletSaveUrlService|MockInterface $saveUrlService;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->passSettingsResolver = Mockery::mock(GoogleWalletPassSettingsResolver::class);
        $this->syncService = Mockery::mock(SyncGoogleWalletPassesService::class);
        $this->saveUrlService = Mockery::mock(GoogleWalletSaveUrlService::class);
        $this->output = new BufferedOutput;

        $this->passSettingsResolver->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $this->eventRepository
            ->shouldReceive('findById')
            ->with(self::EVENT_ID)
            ->andReturn((new EventDomainObject)->setId(self::EVENT_ID)->setOrganizerId(self::ORGANIZER_ID))
            ->byDefault();
        $this->passSettingsResolver
            ->shouldReceive('resolveForOrganizer')
            ->with(self::ORGANIZER_ID)
            ->andReturn(new WalletPassBrandingDTO(logoUrl: null, bannerImageUrl: null, appleStripImageUrl: null, backgroundColor: null, themeAccentColor: null))
            ->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function runCommand(string $code): int
    {
        $command = new PreviewGoogleWalletPassCommand(
            $this->attendeeRepository,
            $this->orderRepository,
            $this->eventRepository,
            $this->passSettingsResolver,
            $this->syncService,
            $this->saveUrlService,
        );
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput(['code' => $code]), $this->output);
    }

    private function attendee(int $id = self::ATTENDEE_ID, ?string $objectId = null): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setEventId(self::EVENT_ID)
            ->setOrderId(self::ORDER_ID)
            ->setGoogleWalletObjectId($objectId);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID);
    }

    public function test_a_ticket_code_syncs_that_pass_and_prints_its_save_link(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->with(['public_id' => 'A-SMOKE31'])
            ->andReturn($this->attendee());
        $this->syncService->shouldReceive('syncAttendee')->once()->with(self::ATTENDEE_ID);
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with(['id' => self::ATTENDEE_ID])
            ->andReturn(new Collection([$this->attendee(objectId: 'issuer.dev_attendee_31')]));
        $this->saveUrlService
            ->shouldReceive('buildForObjectIds')
            ->once()
            ->with(['issuer.dev_attendee_31'])
            ->andReturn(self::SAVE_URL);

        $this->assertSame(0, $this->runCommand('a-smoke31'));
        $this->assertStringContainsString(self::SAVE_URL, $this->output->fetch());
    }

    public function test_an_order_number_syncs_every_pass_on_the_order_into_one_save_link(): void
    {
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->with(['public_id' => 'O-SMOKE20'])
            ->andReturn($this->order());
        $this->syncService->shouldReceive('syncOrder')->once()->with(self::ORDER_ID);
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with(['order_id' => self::ORDER_ID])
            ->andReturn(new Collection([
                $this->attendee(31, 'issuer.dev_attendee_31'),
                $this->attendee(32, 'issuer.dev_attendee_32'),
            ]));
        $this->saveUrlService
            ->shouldReceive('buildForObjectIds')
            ->once()
            ->with(['issuer.dev_attendee_31', 'issuer.dev_attendee_32'])
            ->andReturn(self::SAVE_URL);

        $this->assertSame(0, $this->runCommand('O-SMOKE20'));

        $output = $this->output->fetch();
        $this->assertStringContainsString('2 pass(es) up to date', $output);
        $this->assertStringContainsString(self::SAVE_URL, $output);
    }

    public function test_an_email_is_rejected_without_looking_anything_up(): void
    {
        $this->attendeeRepository->shouldNotReceive('findFirstWhere');
        $this->orderRepository->shouldNotReceive('findFirstWhere');

        $this->assertSame(1, $this->runCommand('someone@example.com'));
        $this->assertStringContainsString('is not a ticket code', $this->output->fetch());
    }

    public function test_it_fails_when_google_wallet_is_not_configured(): void
    {
        $this->passSettingsResolver->shouldReceive('isConfigured')->andReturn(false);
        $this->attendeeRepository->shouldNotReceive('findFirstWhere');

        $this->assertSame(1, $this->runCommand('A-SMOKE31'));
        $this->assertStringContainsString('not configured', $this->output->fetch());
    }

    public function test_it_fails_when_the_ticket_code_does_not_exist(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand('A-NOPE123'));
        $this->assertStringContainsString('No ticket found', $this->output->fetch());
    }

    public function test_it_fails_when_the_order_number_does_not_exist(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->syncService->shouldNotReceive('syncOrder');

        $this->assertSame(1, $this->runCommand('O-NOPE123'));
        $this->assertStringContainsString('No order found', $this->output->fetch());
    }

    public function test_it_fails_when_google_wallet_is_disabled_for_the_organizer(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($this->attendee());
        $this->passSettingsResolver->shouldReceive('resolveForOrganizer')->with(self::ORGANIZER_ID)->andReturn(null);
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand('A-SMOKE31'));
        $this->assertStringContainsString('disabled for organizer', $this->output->fetch());
    }

    public function test_it_fails_when_no_pass_could_be_created(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($this->order());
        $this->syncService->shouldReceive('syncOrder')->once();
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection([$this->attendee()]));
        $this->saveUrlService->shouldNotReceive('buildForObjectIds');

        $this->assertSame(1, $this->runCommand('O-SMOKE20'));
        $this->assertStringContainsString('No pass was created', $this->output->fetch());
    }

    public function test_it_reports_google_api_errors(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($this->attendee());
        $this->syncService
            ->shouldReceive('syncAttendee')
            ->andThrow(new GoogleWalletApiException('Invalid hero image'));
        $this->saveUrlService->shouldNotReceive('buildForObjectIds');

        $this->assertSame(1, $this->runCommand('A-SMOKE31'));
        $this->assertStringContainsString('Invalid hero image', $this->output->fetch());
    }
}

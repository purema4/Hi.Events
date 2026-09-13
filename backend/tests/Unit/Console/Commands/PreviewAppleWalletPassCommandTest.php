<?php

namespace Tests\Unit\Console\Commands;

use HiEvents\Console\Commands\PreviewAppleWalletPassCommand;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletUrlGenerator;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassSettingsDTO;
use HiEvents\Services\Domain\AppleWallet\SyncAppleWalletPassesService;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PreviewAppleWalletPassCommandTest extends TestCase
{
    private const ATTENDEE_ID = 31;

    private const ORDER_ID = 20;

    private const EVENT_ID = 10;

    private const ORGANIZER_ID = 5;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private AppleWalletPassSettingsResolver|MockInterface $passSettingsResolver;

    private SyncAppleWalletPassesService|MockInterface $syncService;

    private AppleWalletPassService|MockInterface $passService;

    private Filesystem|MockInterface $filesystem;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->passSettingsResolver = Mockery::mock(AppleWalletPassSettingsResolver::class);
        $this->syncService = Mockery::mock(SyncAppleWalletPassesService::class);
        $this->passService = Mockery::mock(AppleWalletPassService::class);
        $this->filesystem = Mockery::mock(Filesystem::class)->shouldIgnoreMissing();
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
            ->andReturn(new AppleWalletPassSettingsDTO(logoUrl: null, stripImageUrl: null, backgroundColor: null))
            ->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function runCommand(string $code): int
    {
        $command = new PreviewAppleWalletPassCommand(
            $this->attendeeRepository,
            $this->orderRepository,
            $this->eventRepository,
            $this->passSettingsResolver,
            $this->syncService,
            $this->passService,
            new AppleWalletUrlGenerator(new Repository(['apple-wallet' => ['api_url' => 'https://tickets.example.com/api']])),
            $this->filesystem,
        );
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput(['code' => $code]), $this->output);
    }

    private function attendee(): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId(self::ATTENDEE_ID)
            ->setEventId(self::EVENT_ID)
            ->setShortId('a_short31')
            ->setPublicId('A-TICKET31');
    }

    public function test_it_refuses_to_run_when_apple_wallet_is_not_configured(): void
    {
        $this->passSettingsResolver->shouldReceive('isConfigured')->andReturn(false);

        $this->assertSame(1, $this->runCommand('A-TICKET31'));
        $this->assertStringContainsString('Apple Wallet is not configured', $this->output->fetch());
    }

    public function test_it_rejects_a_code_that_is_neither_a_ticket_nor_an_order(): void
    {
        $this->assertSame(1, $this->runCommand('X-123'));
        $this->assertStringContainsString('is not a ticket code', $this->output->fetch());
    }

    public function test_it_reports_an_unknown_ticket(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->with(['public_id' => 'A-MISSING'])->andReturn(null);

        $this->assertSame(1, $this->runCommand('a-missing'));
        $this->assertStringContainsString('No ticket found with code A-MISSING', $this->output->fetch());
    }

    public function test_it_explains_when_the_organizer_has_not_enabled_apple_wallet(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($this->attendee());
        $this->passSettingsResolver->shouldReceive('resolveForOrganizer')->with(self::ORGANIZER_ID)->andReturn(null);
        $this->passService->shouldNotReceive('generate');

        $this->assertSame(1, $this->runCommand('A-TICKET31'));
        $this->assertStringContainsString('Apple Wallet is disabled for organizer 5', $this->output->fetch());
    }

    public function test_a_ticket_pass_is_pushed_saved_and_linked(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->with(['public_id' => 'A-TICKET31'])->andReturn($this->attendee());
        $this->attendeeRepository->shouldReceive('findWhere')->with(['id' => self::ATTENDEE_ID])->andReturn(collect([$this->attendee()]));
        $this->syncService->shouldReceive('syncAttendee')->once()->with(self::ATTENDEE_ID);
        $this->passService
            ->shouldReceive('generate')
            ->once()
            ->with(['id' => self::ATTENDEE_ID])
            ->andReturn(new AppleWalletPassFileDTO(contents: 'pkpass', mimeType: 'application/vnd.apple.pkpass', filename: 'hievents-attendee-31.pkpass'));

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $path, string $contents) => str_ends_with($path, 'apple-wallet-previews/A-TICKET31-hievents-attendee-31.pkpass')
                && $contents === 'pkpass');

        $this->assertSame(0, $this->runCommand('A-TICKET31'));

        $output = $this->output->fetch();
        $this->assertStringContainsString('Apple Wallet pass saved to', $output);
        $this->assertStringContainsString('https://tickets.example.com/api/public/events/10/apple-wallet-passes?attendees=a_short31', $output);
    }

    public function test_an_order_previews_every_ticket_in_it(): void
    {
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->with(['public_id' => 'O-ORDER20'])
            ->andReturn((new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID));
        $this->attendeeRepository->shouldReceive('findWhere')->with(['order_id' => self::ORDER_ID])->andReturn(collect([$this->attendee()]));
        $this->syncService->shouldReceive('syncOrder')->once()->with(self::ORDER_ID);
        $this->passService
            ->shouldReceive('generate')
            ->once()
            ->with(['order_id' => self::ORDER_ID])
            ->andReturn(new AppleWalletPassFileDTO(contents: 'bundle', mimeType: 'application/vnd.apple.pkpasses', filename: 'tickets.pkpasses'));

        $this->assertSame(0, $this->runCommand('O-ORDER20'));
    }

    public function test_a_configuration_problem_is_reported(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($this->attendee());
        $this->syncService->shouldReceive('syncAttendee');
        $this->passService->shouldReceive('generate')->andThrow(new AppleWalletConfigurationException('No Apple Wallet pass certificate is configured.'));

        $this->assertSame(1, $this->runCommand('A-TICKET31'));
        $this->assertStringContainsString('No Apple Wallet pass certificate is configured.', $this->output->fetch());
    }
}

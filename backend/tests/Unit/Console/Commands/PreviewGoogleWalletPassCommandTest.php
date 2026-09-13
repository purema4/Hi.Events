<?php

namespace Tests\Unit\Console\Commands;

use HiEvents\Console\Commands\PreviewGoogleWalletPassCommand;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlService;
use HiEvents\Services\Domain\GoogleWallet\SyncGoogleWalletPassesService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PreviewGoogleWalletPassCommandTest extends TestCase
{
    private const ATTENDEE_ID = 31;

    private const EVENT_ID = 10;

    private const ORGANIZER_ID = 5;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private GoogleWalletPassSettingsResolver|MockInterface $passSettingsResolver;

    private SyncGoogleWalletPassesService|MockInterface $syncService;

    private GoogleWalletSaveUrlService|MockInterface $saveUrlService;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
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
            ->andReturn(new GoogleWalletPassSettingsDTO(logoUrl: null, heroImageUrl: null, backgroundColor: null))
            ->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function runCommand(string $attendee = '31'): int
    {
        $command = new PreviewGoogleWalletPassCommand(
            $this->attendeeRepository,
            $this->eventRepository,
            $this->passSettingsResolver,
            $this->syncService,
            $this->saveUrlService,
        );
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput(['attendee' => $attendee]), $this->output);
    }

    private function attendee(?string $objectId = null, int $id = self::ATTENDEE_ID): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setEventId(self::EVENT_ID)
            ->setPublicId('A-SMOKE'.$id)
            ->setFirstName('Smoke')
            ->setLastName('Test')
            ->setStatus('ACTIVE')
            ->setGoogleWalletObjectId($objectId);
    }

    private function expectPassIsSynced(): void
    {
        $this->syncService->shouldReceive('syncAttendee')->once()->with(self::ATTENDEE_ID);

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->with(['id' => self::ATTENDEE_ID])
            ->andReturn($this->attendee('issuer.dev_attendee_31'));

        $this->saveUrlService
            ->shouldReceive('buildForObjectIds')
            ->once()
            ->with(['issuer.dev_attendee_31'])
            ->andReturn('https://pay.google.com/gp/v/save/token');
    }

    public function test_it_syncs_the_pass_and_prints_its_save_link(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with(['id' => self::ATTENDEE_ID])
            ->andReturn(new Collection([$this->attendee()]));
        $this->expectPassIsSynced();

        $this->assertSame(0, $this->runCommand());
        $this->assertStringContainsString('https://pay.google.com/gp/v/save/token', $this->output->fetch());
    }

    public function test_it_finds_the_attendee_by_ticket_code_in_any_case(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with(['public_id' => 'A-SMOKE31'])
            ->andReturn(new Collection([$this->attendee()]));
        $this->expectPassIsSynced();

        $this->assertSame(0, $this->runCommand('a-smoke31'));
    }

    public function test_it_finds_the_attendee_by_email(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->with([['email', 'ilike', 'Smoke@Dev.test']])
            ->andReturn(new Collection([$this->attendee()]));
        $this->expectPassIsSynced();

        $this->assertSame(0, $this->runCommand('Smoke@Dev.test'));
    }

    public function test_it_lists_the_matches_when_an_email_belongs_to_several_attendees(): void
    {
        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->andReturn(new Collection([$this->attendee(), $this->attendee(id: 32)]));
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand('smoke@dev.test'));

        $output = $this->output->fetch();
        $this->assertStringContainsString('matches 2 attendees', $output);
        $this->assertStringContainsString('A-SMOKE31', $output);
        $this->assertStringContainsString('A-SMOKE32', $output);
    }

    public function test_it_fails_when_google_wallet_is_not_configured(): void
    {
        $this->passSettingsResolver->shouldReceive('isConfigured')->andReturn(false);
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand());
        $this->assertStringContainsString('not configured', $this->output->fetch());
    }

    public function test_it_fails_when_the_attendee_does_not_exist(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand());
        $this->assertStringContainsString('No attendee found', $this->output->fetch());
    }

    public function test_it_fails_when_google_wallet_is_disabled_for_the_organizer(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection([$this->attendee()]));
        $this->passSettingsResolver->shouldReceive('resolveForOrganizer')->with(self::ORGANIZER_ID)->andReturn(null);
        $this->syncService->shouldNotReceive('syncAttendee');

        $this->assertSame(1, $this->runCommand());
        $this->assertStringContainsString('disabled for organizer', $this->output->fetch());
    }

    public function test_it_reports_google_api_errors(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection([$this->attendee()]));
        $this->syncService
            ->shouldReceive('syncAttendee')
            ->andThrow(new GoogleWalletApiException('Invalid hero image'));
        $this->saveUrlService->shouldNotReceive('buildForObjectIds');

        $this->assertSame(1, $this->runCommand());
        $this->assertStringContainsString('Invalid hero image', $this->output->fetch());
    }
}

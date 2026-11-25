<?php

namespace Tests\Unit\Services\Domain\Tracking;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Tracking\MetaPurchaseConversionService;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Illuminate\Encryption\Encrypter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\TestCase;

class MetaPurchaseConversionServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const PIXEL_ID = '1588480782483786';

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private OrganizerSettingsRepositoryInterface|MockInterface $organizerSettingsRepository;

    private QuestionRepositoryInterface|MockInterface $questionRepository;

    private QuestionAnswerRepositoryInterface|MockInterface $questionAnswerRepository;

    private MetaConversionsApiClient|MockInterface $client;

    private Encrypter $encrypter;

    private MetaPurchaseConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->organizerSettingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $this->questionAnswerRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);
        $this->questionRepository->shouldReceive('findWhere')->andReturn(collect())->byDefault();
        $this->client = Mockery::mock(MetaConversionsApiClient::class);
        $this->encrypter = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');

        $this->service = new MetaPurchaseConversionService(
            orderRepository: $this->orderRepository,
            eventRepository: $this->eventRepository,
            organizerSettingsRepository: $this->organizerSettingsRepository,
            questionRepository: $this->questionRepository,
            questionAnswerRepository: $this->questionAnswerRepository,
            client: $this->client,
            encrypter: $this->encrypter,
            logger: new NullLogger,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sends_purchase_with_hashed_customer_details_and_browser_context(): void
    {
        $this->givenOrder($this->makeOrder());
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());

        $sentEvents = null;
        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(function (array $events) use (&$sentEvents) {
                $sentEvents = $events;

                return true;
            }));

        $this->service->sendPurchase(1);

        $event = $sentEvents[0];
        $this->assertSame('Purchase', $event['event_name']);
        $this->assertSame(Carbon::now()->getTimestamp(), $event['event_time']);
        $this->assertSame('ABC123', $event['event_id']);
        $this->assertSame('website', $event['action_source']);
        $this->assertSame('https://outkzt.events/checkout/6/ABC123/details', $event['event_source_url']);

        $this->assertSame([
            'currency' => 'CAD',
            'value' => 45.5,
            'content_ids' => ['6'],
            'content_name' => 'Halloween Blood Rave',
            'content_type' => 'product',
            'order_id' => 'ABC123',
        ], $event['custom_data']);

        $userData = $event['user_data'];
        $this->assertSame([hash('sha256', 'jane@example.com')], $userData['em']);
        $this->assertSame([hash('sha256', 'jane')], $userData['fn']);
        $this->assertSame([hash('sha256', 'doe')], $userData['ln']);
        $this->assertSame([hash('sha256', 'montréal')], $userData['ct']);
        $this->assertSame([hash('sha256', 'h2x1y4')], $userData['zp']);
        $this->assertSame([hash('sha256', 'ca')], $userData['country']);
        $this->assertArrayNotHasKey('st', $userData);
        $this->assertSame('203.0.113.7', $userData['client_ip_address']);
        $this->assertSame('Mozilla/5.0', $userData['client_user_agent']);
        $this->assertSame('fb.1.1700000000000.123456', $userData['fbp']);
        $this->assertArrayNotHasKey('fbc', $userData);
    }

    public function test_normalizes_names_and_us_addresses_the_way_meta_requires(): void
    {
        $this->givenOrder($this->makeOrder()
            ->setFirstName('Mary-Jo')
            ->setLastName("O'Brien Smith")
            ->setAddress([
                'city' => 'New York',
                'state_or_region' => 'New York',
                'zip_or_postal_code' => '10001-1234',
                'country' => 'US',
            ]));
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());

        $sentEvents = null;
        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(function (array $events) use (&$sentEvents) {
                $sentEvents = $events;

                return true;
            }));

        $this->service->sendPurchase(1);

        $userData = $sentEvents[0]['user_data'];
        $this->assertSame([hash('sha256', 'maryjo')], $userData['fn']);
        $this->assertSame([hash('sha256', 'obriensmith')], $userData['ln']);
        $this->assertSame([hash('sha256', 'newyork')], $userData['ct']);
        $this->assertSame([hash('sha256', 'ny')], $userData['st']);
        $this->assertSame([hash('sha256', '10001')], $userData['zp']);
        $this->assertSame([hash('sha256', 'us')], $userData['country']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canadianProvinceProvider(): array
    {
        return [
            'french name with accent' => ['Québec', 'qc'],
            'english name without accent' => ['Quebec', 'qc'],
            'code' => ['QC', 'qc'],
            'multi-word name' => ['British Columbia', 'bc'],
            'hyphenated french name' => ['Nouvelle-Écosse', 'ns'],
        ];
    }

    #[DataProvider('canadianProvinceProvider')]
    public function test_sends_canadian_provinces_as_their_two_letter_code(string $typed, string $expected): void
    {
        $this->givenOrder($this->makeOrder()->setAddress([
            'city' => 'Montréal',
            'state_or_region' => $typed,
            'zip_or_postal_code' => 'H2X 1Y4',
            'country' => 'CA',
        ]));
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());

        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(
                fn (array $events) => $events[0]['user_data']['st'] === [hash('sha256', $expected)]
                    && $events[0]['user_data']['zp'] === [hash('sha256', 'h2x1y4')]
            ));

        $this->service->sendPurchase(1);
    }

    public function test_keeps_other_countries_regions_as_lowercase_text_without_punctuation(): void
    {
        $this->givenOrder($this->makeOrder()->setAddress([
            'city' => 'Paris',
            'state_or_region' => 'Île-de-France',
            'zip_or_postal_code' => '75018',
            'country' => 'FR',
        ]));
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());

        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(
                fn (array $events) => $events[0]['user_data']['st'] === [hash('sha256', 'îledefrance')]
            ));

        $this->service->sendPurchase(1);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function phoneProvider(): array
    {
        return [
            'international format' => ['+1 (514) 555-1234', 'CA', 'cad', '15145551234'],
            'local canadian number' => ['514-555-1234', 'CA', 'cad', '15145551234'],
            'local number with leading 1' => ['1 514 555 1234', 'US', 'usd', '15145551234'],
            'no address, canadian dollars' => ['(514) 555-1234', '', 'cad', '15145551234'],
            'international 00 prefix' => ['0033 6 12 34 56 78', 'FR', 'eur', '33612345678'],
            'local number outside north america' => ['06 12 34 56 78', 'FR', 'eur', null],
            'no address, other currency' => ['514-555-1234', '', 'eur', null],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_sends_the_buyers_phone_number_with_its_country_code(
        string $typed,
        string $country,
        string $currency,
        ?string $expected,
    ): void {
        $this->givenOrder($this->makeOrder()->setCurrency($currency)->setAddress(
            $country === '' ? null : ['city' => 'Montréal', 'country' => $country],
        ));
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());
        $this->givenPhoneAnswer($typed);

        $sentEvents = null;
        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(function (array $events) use (&$sentEvents) {
                $sentEvents = $events;

                return true;
            }));

        $this->service->sendPurchase(1);

        $userData = $sentEvents[0]['user_data'];
        if ($expected === null) {
            $this->assertArrayNotHasKey('ph', $userData);
        } else {
            $this->assertSame([hash('sha256', $expected)], $userData['ph']);
        }
    }

    public function test_sends_complete_registration_for_free_orders(): void
    {
        $this->givenOrder($this->makeOrder()->setTotalGross(0));
        $this->givenEvent();
        $this->givenSettings($this->makeSettings());

        $this->client
            ->shouldReceive('sendEvents')
            ->once()
            ->with(self::PIXEL_ID, 'EAAtoken', Mockery::on(
                fn (array $events) => $events[0]['event_name'] === 'CompleteRegistration'
                    && $events[0]['event_id'] === 'ABC123'
            ));

        $this->service->sendPurchase(1);
    }

    public function test_does_not_send_when_order_has_no_tracking_context(): void
    {
        $this->givenOrder($this->makeOrder()->setAdTrackingContext(null));

        $this->client->shouldNotReceive('sendEvents');

        $this->service->sendPurchase(1);
    }

    public function test_does_not_send_when_order_is_not_completed(): void
    {
        $this->givenOrder($this->makeOrder()->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name));

        $this->client->shouldNotReceive('sendEvents');

        $this->service->sendPurchase(1);
    }

    public function test_does_not_send_without_an_access_token(): void
    {
        $this->givenOrder($this->makeOrder());
        $this->givenEvent();
        $this->givenSettings($this->makeSettings()->setMetaConversionsApiAccessToken(null));

        $this->client->shouldNotReceive('sendEvents');

        $this->service->sendPurchase(1);
    }

    public function test_does_not_send_when_the_facebook_pixel_is_disabled(): void
    {
        $this->givenOrder($this->makeOrder());
        $this->givenEvent();
        $this->givenSettings($this->makeSettings()->setTrackingPixels([
            ['provider' => 'facebook_pixel', 'pixel_id' => self::PIXEL_ID, 'enabled' => false],
        ]));

        $this->client->shouldNotReceive('sendEvents');

        $this->service->sendPurchase(1);
    }

    public function test_does_not_send_when_the_access_token_cannot_be_decrypted(): void
    {
        $this->givenOrder($this->makeOrder());
        $this->givenEvent();
        $this->givenSettings($this->makeSettings()->setMetaConversionsApiAccessToken('not-encrypted'));

        $this->client->shouldNotReceive('sendEvents');

        $this->service->sendPurchase(1);
    }

    private function makeOrder(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(1)
            ->setEventId(6)
            ->setShortId('ABC123')
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setCurrency('cad')
            ->setTotalGross(45.5)
            ->setEmail(' Jane@Example.com ')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setAddress([
                'city' => 'Montréal',
                'state_or_region' => null,
                'zip_or_postal_code' => 'H2X 1Y4',
                'country' => 'CA',
            ])
            ->setAdTrackingContext([
                'fbp' => 'fb.1.1700000000000.123456',
                'fbc' => null,
                'event_source_url' => 'https://outkzt.events/checkout/6/ABC123/details',
                'client_ip_address' => '203.0.113.7',
                'client_user_agent' => 'Mozilla/5.0',
            ]);
    }

    private function makeSettings(): OrganizerSettingDomainObject
    {
        return (new OrganizerSettingDomainObject)
            ->setId(3)
            ->setOrganizerId(9)
            ->setTrackingPixels([
                ['provider' => 'google_analytics_4', 'pixel_id' => 'G-1HKL0EDK3Z', 'enabled' => true],
                ['provider' => 'facebook_pixel', 'pixel_id' => self::PIXEL_ID, 'enabled' => true],
            ])
            ->setMetaConversionsApiAccessToken($this->encrypter->encryptString('EAAtoken'));
    }

    private function givenPhoneAnswer(string $answer): void
    {
        $this->questionRepository
            ->shouldReceive('findWhere')
            ->with([
                'event_id' => 6,
                'type' => 'PHONE',
                'belongs_to' => 'ORDER',
            ])
            ->andReturn(collect([(new QuestionDomainObject)->setId(40)]));

        $this->questionAnswerRepository
            ->shouldReceive('findWhereIn')
            ->with('question_id', [40], ['order_id' => 1])
            ->andReturn(collect([(new QuestionAnswerDomainObject)->setQuestionId(40)->setAnswer($answer)]));
    }

    private function givenOrder(OrderDomainObject $order): void
    {
        $this->orderRepository->shouldReceive('findById')->with(1)->andReturn($order);
    }

    private function givenEvent(): void
    {
        $event = (new EventDomainObject)
            ->setId(6)
            ->setOrganizerId(9)
            ->setTitle('Halloween Blood Rave');

        $this->eventRepository->shouldReceive('findById')->with(6)->andReturn($event);
    }

    private function givenSettings(OrganizerSettingDomainObject $settings): void
    {
        $this->organizerSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 9])
            ->andReturn($settings);
    }
}

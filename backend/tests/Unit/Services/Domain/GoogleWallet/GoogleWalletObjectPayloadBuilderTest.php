<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletObjectPayloadBuilder;
use Illuminate\Config\Repository;
use Tests\TestCase;

class GoogleWalletObjectPayloadBuilderTest extends TestCase
{
    private function builder(?string $redemptionIssuerId = null): GoogleWalletObjectPayloadBuilder
    {
        return new GoogleWalletObjectPayloadBuilder(
            new Repository(['google-wallet' => ['redemption_issuer_id' => $redemptionIssuerId]]),
            $this->app->make('translator'),
        );
    }

    private function event(): EventDomainObject
    {
        $event = new EventDomainObject;
        $event->setId(1)->setTitle('Synth Night');

        return $event;
    }

    private function attendee(string $status = 'ACTIVE'): AttendeeDomainObject
    {
        $product = new ProductDomainObject;
        $product->setId(4)->setTitle('General Admission');

        $attendee = new AttendeeDomainObject;
        $attendee->setId(11)
            ->setPublicId('att_public_123')
            ->setShortId('att_short_123')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setStatus($status)
            ->setProduct($product);

        return $attendee;
    }

    public function test_the_barcode_matches_the_id_the_check_in_scanner_reads(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame('QR_CODE', $payload['barcode']['type']);
        $this->assertSame('att_public_123', $payload['barcode']['value']);
    }

    public function test_it_links_the_object_to_its_class(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame('issuer.obj_1', $payload['id']);
        $this->assertSame('issuer.class_1', $payload['classId']);
    }

    public function test_it_carries_the_ticket_holder_and_product(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame('Ada Lovelace', $payload['ticketHolderName']);
        $this->assertSame('att_public_123', $payload['ticketNumber']);
        $this->assertSame('General Admission', $payload['ticketType']['defaultValue']['value']);
    }

    public function test_an_active_attendee_gets_an_active_pass(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame('ACTIVE', $payload['state']);
    }

    public function test_an_unpaid_attendee_gets_an_inactive_pass(): void
    {
        $payload = $this->builder()->build(
            'issuer.obj_1',
            'issuer.class_1',
            $this->attendee(AttendeeStatus::AWAITING_PAYMENT->name),
            $this->event(),
            null,
        );

        $this->assertSame('INACTIVE', $payload['state']);
    }

    public function test_a_cancelled_attendee_gets_an_expired_pass(): void
    {
        $payload = $this->builder()->build(
            'issuer.obj_1',
            'issuer.class_1',
            $this->attendee(AttendeeStatus::CANCELLED->name),
            $this->event(),
            null,
        );

        $this->assertSame('EXPIRED', $payload['state']);
    }

    public function test_an_attendee_without_a_name_is_sent_without_a_holder(): void
    {
        $attendee = $this->attendee();
        $attendee->setFirstName('')->setLastName('');

        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $attendee, $this->event(), null);

        $this->assertArrayNotHasKey('ticketHolderName', $payload);
    }

    public function test_the_ticket_type_and_price_are_shown_on_the_card(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), '$25.00');

        $modules = collect($payload['textModulesData'])->keyBy('id');

        $this->assertSame('Ticket', $modules['ticket']['header']);
        $this->assertSame('General Admission', $modules['ticket']['body']);
        $this->assertSame('Price', $modules['price']['header']);
        $this->assertSame('$25.00', $modules['price']['body']);
    }

    public function test_a_ticket_without_a_known_price_only_shows_the_ticket_type(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame(['ticket'], collect($payload['textModulesData'])->pluck('id')->all());
    }

    public function test_nfc_is_off_when_no_redemption_issuer_is_configured(): void
    {
        $payload = $this->builder()->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertArrayNotHasKey('smartTapRedemptionValue', $payload);
    }

    public function test_nfc_taps_transmit_the_same_value_as_the_barcode(): void
    {
        $payload = $this->builder('1234567890')
            ->build('issuer.obj_1', 'issuer.class_1', $this->attendee(), $this->event(), null);

        $this->assertSame($payload['barcode']['value'], $payload['smartTapRedemptionValue']);
    }
}

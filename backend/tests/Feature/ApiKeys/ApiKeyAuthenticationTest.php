<?php

namespace Tests\Feature\ApiKeys;

use HiEvents\DomainObjects\Enums\AccountDeletionInitiator;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\AccountDeletionRequestStatus;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyAuthenticationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithApiKeys;

    private User $admin;

    private int $accountId;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccountConfiguration();

        [$this->admin, $this->accountId] = $this->makeAccountWithAdmin();
        $this->eventId = $this->makeEvent($this->accountId, $this->admin->id, $this->makeOrganizer($this->accountId));
    }

    public function test_a_read_key_reads_orders_but_cannot_refund_or_edit_them(): void
    {
        $key = $this->makeApiKey($this->admin, $this->accountId, ['orders:read']);

        $this->getJson("/events/{$this->eventId}/orders", $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->postJson("/events/{$this->eventId}/orders/1/refund", ['amount' => 10], $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED');

        $this->putJson("/events/{$this->eventId}/orders/1", ['first_name' => 'X'], $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED');
    }

    public function test_a_key_without_the_ability_is_refused_with_the_missing_ability_named(): void
    {
        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);

        $this->getJson("/events/{$this->eventId}/orders", $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED')
            ->assertJsonPath('ability', 'orders:read');
    }

    public function test_a_key_cannot_read_an_event_of_another_account(): void
    {
        [$otherAdmin, $otherAccountId] = $this->makeAccountWithAdmin();
        $otherEventId = $this->makeEvent($otherAccountId, $otherAdmin->id, $this->makeOrganizer($otherAccountId));

        $key = $this->makeApiKey($this->admin, $this->accountId, ['orders:read']);

        $response = $this->getJson("/events/{$otherEventId}/orders", $this->bearer($key));

        $response->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $this->assertNull($response->json('error_code'));
    }

    public function test_a_revoked_expired_or_malformed_key_is_unauthenticated(): void
    {
        $revoked = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);
        DB::table('personal_access_tokens')->where('id', (int) explode('|', $revoked)[0])->delete();

        $expired = $this->makeApiKey($this->admin, $this->accountId, ['events:read'], now()->subMinute());

        $valid = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);
        [$validId] = explode('|', $valid);

        foreach ([$revoked, $expired, 'not-a-key', $validId.'|hie_wrongsecret', '|'] as $token) {
            $this->getJson('/organizers', $this->bearer($token))
                ->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
        }

        $this->getJson('/organizers', $this->bearer($valid))->assertStatus(ResponseCodes::HTTP_OK);
    }

    public function test_a_key_whose_creator_was_deactivated_is_unauthenticated(): void
    {
        $creator = $this->addUserToAccount($this->accountId, Role::ADMIN);
        $key = $this->makeApiKey($creator, $this->accountId, ['events:read']);

        $this->getJson('/api-key', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_OK);

        DB::table('account_users')
            ->where(['account_id' => $this->accountId, 'user_id' => $creator->id])
            ->update(['status' => UserStatus::INACTIVE->name]);

        $this->getJson('/api-key', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_a_key_whose_creator_was_removed_from_the_account_is_unauthenticated(): void
    {
        $creator = $this->addUserToAccount($this->accountId, Role::ADMIN);
        $key = $this->makeApiKey($creator, $this->accountId, ['events:read']);

        DB::table('account_users')
            ->where(['account_id' => $this->accountId, 'user_id' => $creator->id])
            ->update(['deleted_at' => now()]);

        $this->getJson('/api-key', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_a_key_never_reaches_logout_or_token_refresh(): void
    {
        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read', 'orders:read']);

        $this->getJson('/auth/logout', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $this->postJson('/auth/refresh', [], $this->bearer($key))->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
    }

    public function test_the_admin_routes_only_accept_a_session(): void
    {
        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read', 'orders:read']);

        $this->getJson('/admin/system-info', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_settings_and_taxes_are_readable_with_their_abilities(): void
    {
        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventsKey = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);
        $this->getJson("/events/{$this->eventId}/settings", $this->bearer($eventsKey))
            ->assertStatus(ResponseCodes::HTTP_OK);

        $productsKey = $this->makeApiKey($this->admin, $this->accountId, ['products:read']);
        $this->getJson("/accounts/{$this->accountId}/taxes-and-fees", $this->bearer($productsKey))
            ->assertStatus(ResponseCodes::HTTP_OK);
    }

    public function test_a_key_and_its_creator_have_separate_rate_limits(): void
    {
        config(['app.api_rate_limit_per_minute' => 2]);

        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);
        $jwt = $this->jwtFor($this->admin, $this->accountId);

        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_OK);
        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_OK);
        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_TOO_MANY_REQUESTS);

        $this->getJson('/organizers', $this->bearer($jwt))->assertStatus(ResponseCodes::HTTP_OK);
        $this->getJson('/organizers', $this->bearer($jwt))->assertStatus(ResponseCodes::HTTP_OK);
        $this->getJson('/organizers', $this->bearer($jwt))->assertStatus(ResponseCodes::HTTP_TOO_MANY_REQUESTS);
    }

    public function test_a_key_of_an_account_pending_deletion_is_blocked(): void
    {
        DB::table('account_deletion_requests')->insert([
            'account_id' => $this->accountId,
            'requested_by_user_id' => $this->admin->id,
            'initiated_by' => AccountDeletionInitiator::cases()[0]->name,
            'status' => AccountDeletionRequestStatus::REQUESTED->name,
            'scheduled_deletion_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);

        $this->getJson('/organizers', $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'ACCOUNT_PENDING_DELETION');
    }

    public function test_jwt_sessions_are_not_restricted_by_api_key_scopes(): void
    {
        $jwt = $this->jwtFor($this->admin, $this->accountId);

        $this->getJson("/events/{$this->eventId}/orders", $this->bearer($jwt))
            ->assertStatus(ResponseCodes::HTTP_OK);
        $this->getJson("/accounts/{$this->accountId}/api-keys", $this->bearer($jwt))
            ->assertStatus(ResponseCodes::HTTP_OK);
    }

    public function test_a_key_never_receives_the_short_ids_that_open_public_routes(): void
    {
        $listShortId = 'cil_'.uniqid();
        $listId = DB::table('check_in_lists')->insertGetId([
            'short_id' => $listShortId,
            'name' => 'Door',
            'event_id' => $this->eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$orderId, $orderShortId, $attendeeId, $attendeeShortId] = $this->makeOrderWithAttendee();

        $key = $this->makeApiKey($this->admin, $this->accountId, ['check-in-lists:read', 'orders:read', 'attendees:read']);

        $list = $this->getJson("/events/{$this->eventId}/check-in-lists/{$listId}", $this->bearer($key));
        $list->assertStatus(ResponseCodes::HTTP_OK);
        $list->assertJsonPath('data.id', $listId);
        $list->assertJsonPath('data.short_id', null);

        $order = $this->getJson("/events/{$this->eventId}/orders/{$orderId}", $this->bearer($key));
        $order->assertStatus(ResponseCodes::HTTP_OK);
        $order->assertJsonPath('data.id', $orderId);
        $order->assertJsonPath('data.short_id', null);

        $attendee = $this->getJson("/events/{$this->eventId}/attendees/{$attendeeId}", $this->bearer($key));
        $attendee->assertStatus(ResponseCodes::HTTP_OK);
        $attendee->assertJsonPath('data.id', $attendeeId);
        $attendee->assertJsonPath('data.short_id', null);

        foreach ([$list, $order, $attendee] as $response) {
            foreach ([$listShortId, $orderShortId, $attendeeShortId] as $shortId) {
                $this->assertStringNotContainsString($shortId, $response->getContent());
            }
        }

        $jwt = $this->jwtFor($this->admin, $this->accountId);
        $this->getJson("/events/{$this->eventId}/check-in-lists/{$listId}", $this->bearer($jwt))
            ->assertJsonPath('data.short_id', $listShortId);
        $this->getJson("/events/{$this->eventId}/orders/{$orderId}", $this->bearer($jwt))
            ->assertJsonPath('data.short_id', $orderShortId);
        $this->getJson("/events/{$this->eventId}/attendees/{$attendeeId}", $this->bearer($jwt))
            ->assertJsonPath('data.short_id', $attendeeShortId);
    }

    public function test_a_key_reads_webhook_logs_without_their_payloads(): void
    {
        $webhookId = DB::table('webhooks')->insertGetId([
            'url' => 'https://hooks.example.com/hi-events',
            'event_types' => json_encode(['order.created']),
            'status' => 'ENABLED',
            'secret' => 'test-secret',
            'user_id' => $this->admin->id,
            'event_id' => $this->eventId,
            'account_id' => $this->accountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('webhook_logs')->insert([
            'webhook_id' => $webhookId,
            'event_type' => 'order.created',
            'payload' => json_encode(['payload' => ['email' => 'buyer@example.test']]),
            'response_code' => 200,
            'response_body' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $this->makeApiKey($this->admin, $this->accountId, ['webhooks:read']);

        $response = $this->getJson("/events/{$this->eventId}/webhooks/{$webhookId}/logs", $this->bearer($key));

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $response->assertJsonPath('data.0.response_code', 200);
        $response->assertJsonPath('data.0.event_type', 'order.created');
        $response->assertJsonMissingPath('data.0.payload');
        $response->assertJsonMissingPath('data.0.response_body');
        $this->assertStringNotContainsString('buyer@example.test', $response->getContent());

        $this->getJson("/events/{$this->eventId}/webhooks/{$webhookId}/logs", $this->bearer($this->jwtFor($this->admin, $this->accountId)))
            ->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonPath('data.0.response_body', 'ok');
    }

    public function test_a_key_reads_webhooks_without_their_last_response_body(): void
    {
        [, $orderShortId] = $this->makeOrderWithAttendee();
        $responseBody = json_encode(['received' => ['payload' => ['short_id' => $orderShortId.'-echo']]]);
        $webhookId = DB::table('webhooks')->insertGetId([
            'url' => 'https://hooks.example.com/hi-events',
            'event_types' => json_encode(['order.created']),
            'status' => 'ENABLED',
            'secret' => 'test-secret',
            'user_id' => $this->admin->id,
            'event_id' => $this->eventId,
            'account_id' => $this->accountId,
            'last_response_code' => 200,
            'last_response_body' => $responseBody,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $this->makeApiKey($this->admin, $this->accountId, ['webhooks:read']);

        foreach (["/events/{$this->eventId}/webhooks/{$webhookId}", "/events/{$this->eventId}/webhooks"] as $url) {
            $response = $this->getJson($url, $this->bearer($key));

            $response->assertStatus(ResponseCodes::HTTP_OK);
            $this->assertStringContainsString('"last_response_code":200', $response->getContent());
            $this->assertStringNotContainsString('last_response_body', $response->getContent());
            $this->assertStringNotContainsString($orderShortId, $response->getContent());
        }

        $this->getJson("/events/{$this->eventId}/webhooks/{$webhookId}", $this->bearer($this->jwtFor($this->admin, $this->accountId)))
            ->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonPath('data.last_response_body', $responseBody);
    }

    public function test_a_key_never_reads_the_promo_codes_report(): void
    {
        DB::table('promo_codes')->insert([
            'code' => 'COMP100',
            'discount' => 100,
            'discount_type' => 'PERCENTAGE',
            'max_allowed_usages' => 50,
            'event_id' => $this->eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = $this->makeApiKey($this->admin, $this->accountId, ['reports:read']);

        $refused = $this->getJson("/events/{$this->eventId}/reports/promo_codes_report", $this->bearer($key));
        $refused->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $refused->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED');
        $this->assertStringNotContainsString('COMP100', $refused->getContent());

        $this->getJson("/events/{$this->eventId}/reports/product_sales", $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_OK);

        $session = $this->getJson(
            "/events/{$this->eventId}/reports/promo_codes_report",
            $this->bearer($this->jwtFor($this->admin, $this->accountId)),
        );
        $session->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertStringContainsString('COMP100', $session->getContent());
    }

    /**
     * @return array{0: int, 1: string, 2: int, 3: string}
     */
    private function makeOrderWithAttendee(): array
    {
        $productId = DB::table('products')->insertGetId([
            'title' => 'General admission',
            'event_id' => $this->eventId,
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productPriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $productId,
            'price' => 20.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderShortId = 'o_'.uniqid();
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => $orderShortId,
            'event_id' => $this->eventId,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => 'O-'.strtoupper(substr(uniqid(), -7)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendeeShortId = 'a_'.uniqid();
        $attendeeId = DB::table('attendees')->insertGetId([
            'short_id' => $attendeeShortId,
            'first_name' => 'Test',
            'last_name' => 'Attendee',
            'email' => 'attendee-'.uniqid().'@example.test',
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'event_id' => $this->eventId,
            'public_id' => 'A-'.strtoupper(substr(uniqid(), -7)),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $orderShortId, $attendeeId, $attendeeShortId];
    }
}

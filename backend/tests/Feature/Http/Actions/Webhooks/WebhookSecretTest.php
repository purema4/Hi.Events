<?php

namespace Tests\Feature\Http\Actions\Webhooks;

use HiEvents\Http\ResponseCodes;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class WebhookSecretTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $authToken;

    private int $accountId;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $this->user = User::factory()->withAccount()->create();
        $this->accountId = $this->user->accounts()->first()->id;
        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($this->user);

        $organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Test Organizer',
            'email' => 'organizer-'.uniqid().'@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'Webhook Secret Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->user->id,
            'organizer_id' => $organizerId,
            'currency' => 'USD',
            'timezone' => 'UTC',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_secret_sent_in_the_request_body_is_ignored(): void
    {
        $sentSecret = str_repeat('c', 32);

        $response = $this->postJson("/events/{$this->eventId}/webhooks", [
            'url' => 'https://example.com/webhook',
            'event_types' => [DomainEventType::ORDER_CREATED->value],
            'secret' => $sentSecret,
        ], ['Authorization' => 'Bearer '.$this->authToken]);

        $response->assertStatus(ResponseCodes::HTTP_OK);

        $storedSecret = DB::table('webhooks')->where('id', $response->json('data.id'))->value('secret');

        $this->assertNotSame($sentSecret, $storedSecret);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $storedSecret);
        $this->assertSame($storedSecret, $response->json('data.secret'));
    }
}

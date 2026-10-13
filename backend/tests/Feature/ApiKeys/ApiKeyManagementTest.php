<?php

namespace Tests\Feature\ApiKeys;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\PersonalAccessToken;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Account\AccountAnonymizationService;
use HiEvents\Services\Domain\Account\AccountHardDeletionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithApiKeys;

    private User $admin;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccountConfiguration();

        [$this->admin, $this->accountId] = $this->makeAccountWithAdmin();
    }

    public function test_an_admin_creates_a_key_and_sees_its_token_only_once(): void
    {
        $response = $this->postJson("/accounts/{$this->accountId}/api-keys", [
            'name' => 'Sales integration',
            'abilities' => ['events:read', 'orders:read'],
        ], $this->bearer($this->jwtFor($this->admin, $this->accountId)));

        $response->assertStatus(ResponseCodes::HTTP_CREATED);
        $token = $response->json('data.token');
        $this->assertMatchesRegularExpression('/^\d+\|hie_[A-Za-z0-9]{48}$/', $token);
        $this->assertSame(['events:read', 'orders:read'], $response->json('data.abilities'));
        $this->assertSame(substr($token, -4), $response->json('data.partial_token'));

        $list = $this->getJson(
            "/accounts/{$this->accountId}/api-keys",
            $this->bearer($this->jwtFor($this->admin, $this->accountId)),
        );

        $list->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Sales integration', $list->json('data.0.name'));
        $this->assertSame($this->admin->id, $list->json('data.0.created_by.id'));
        $this->assertArrayNotHasKey('token', $list->json('data.0'));
        $this->assertStringNotContainsString(explode('|', $token)[1], $list->getContent());

        $this->getJson('/organizers', $this->bearer($token))->assertStatus(ResponseCodes::HTTP_OK);
    }

    public function test_the_key_is_stored_hashed_and_bound_to_the_account(): void
    {
        $token = $this->postJson("/accounts/{$this->accountId}/api-keys", [
            'name' => 'Sales integration',
            'abilities' => ['orders:read'],
            'expires_at' => now()->addYear()->toIso8601String(),
        ], $this->bearer($this->jwtFor($this->admin, $this->accountId)))->json('data.token');

        [$id, $secret] = explode('|', $token, 2);
        $row = DB::table('personal_access_tokens')->where('id', $id)->first();

        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertSame('HiEvents\Models\User', $row->tokenable_type);
        $this->assertSame($this->admin->id, (int) $row->tokenable_id);
        $this->assertSame($this->accountId, (int) $row->account_id);
        $this->assertSame(substr($secret, -4), $row->partial_token);
        $this->assertSame(['orders:read'], json_decode($row->abilities, true));
        $this->assertNotNull($row->expires_at);
    }

    public function test_a_wildcard_or_unknown_ability_is_rejected(): void
    {
        foreach ([['*'], ['orders:write'], []] as $abilities) {
            $this->postJson("/accounts/{$this->accountId}/api-keys", [
                'name' => 'Too wide',
                'abilities' => $abilities,
            ], $this->bearer($this->jwtFor($this->admin, $this->accountId)))
                ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->assertSame(0, DB::table('personal_access_tokens')->where('account_id', $this->accountId)->count());
    }

    public function test_only_an_admin_session_manages_keys(): void
    {
        $organizer = $this->addUserToAccount($this->accountId, Role::ORGANIZER);
        $organizerJwt = $this->jwtFor($organizer, $this->accountId);
        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read', 'orders:read']);

        $payload = ['name' => 'Nope', 'abilities' => ['orders:read']];

        $this->postJson("/accounts/{$this->accountId}/api-keys", $payload, $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $this->getJson("/accounts/{$this->accountId}/api-keys", $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);

        $this->postJson("/accounts/{$this->accountId}/api-keys", $payload, $this->bearer($organizerJwt))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $this->getJson("/accounts/{$this->accountId}/api-keys", $this->bearer($organizerJwt))
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN);

        $this->assertSame(1, DB::table('personal_access_tokens')->where('account_id', $this->accountId)->count());
    }

    public function test_an_admin_revokes_a_key_of_their_account_only(): void
    {
        $key = $this->makeApiKey($this->admin, $this->accountId, ['events:read']);
        $keyId = (int) explode('|', $key)[0];

        [$otherAdmin, $otherAccountId] = $this->makeAccountWithAdmin();
        $otherJwt = $this->jwtFor($otherAdmin, $otherAccountId);

        $this->deleteJson("/accounts/{$otherAccountId}/api-keys/{$keyId}", [], $this->bearer($otherJwt))
            ->assertStatus(ResponseCodes::HTTP_NOT_FOUND);
        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_OK);

        $this->deleteJson(
            "/accounts/{$this->accountId}/api-keys/{$keyId}",
            [],
            $this->bearer($this->jwtFor($this->admin, $this->accountId)),
        )->assertStatus(ResponseCodes::HTTP_NO_CONTENT);

        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_hard_deleting_an_account_deletes_its_keys(): void
    {
        [$sharedUser, $survivingKeyId, $doomedKeyId] = $this->makeKeysOfASharedUser();

        $this->app->make(AccountHardDeletionService::class)->deleteAccount($this->accountId);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $doomedKeyId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $survivingKeyId]);
        $this->assertDatabaseHas('users', ['id' => $sharedUser->id]);
    }

    public function test_anonymizing_an_account_deletes_its_keys(): void
    {
        [, $survivingKeyId, $doomedKeyId] = $this->makeKeysOfASharedUser();

        $this->app->make(AccountAnonymizationService::class)->anonymizeAccount($this->accountId);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $doomedKeyId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $survivingKeyId]);
    }

    public function test_deactivating_a_user_deletes_their_keys_of_this_account(): void
    {
        [$sharedUser, $survivingKeyId, $doomedKeyId] = $this->makeKeysOfASharedUser();

        $this->updateUserStatus($sharedUser, UserStatus::INACTIVE)->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $doomedKeyId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $survivingKeyId]);
    }

    public function test_a_key_does_not_come_back_when_its_creator_is_reactivated(): void
    {
        $creator = $this->addUserToAccount($this->accountId, Role::ADMIN);
        $key = $this->makeApiKey($creator, $this->accountId, ['events:read']);

        $this->updateUserStatus($creator, UserStatus::INACTIVE)->assertStatus(ResponseCodes::HTTP_OK);
        $this->updateUserStatus($creator, UserStatus::ACTIVE)->assertStatus(ResponseCodes::HTTP_OK);

        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_UNAUTHORIZED);
    }

    public function test_updating_an_active_user_keeps_their_keys(): void
    {
        $creator = $this->addUserToAccount($this->accountId, Role::ADMIN);
        $key = $this->makeApiKey($creator, $this->accountId, ['events:read']);

        $this->updateUserStatus($creator, UserStatus::ACTIVE)->assertStatus(ResponseCodes::HTTP_OK);

        $this->getJson('/organizers', $this->bearer($key))->assertStatus(ResponseCodes::HTTP_OK);
    }

    public function test_a_key_cannot_be_created_while_impersonating(): void
    {
        $superAdmin = User::factory()->create();
        $jwt = JWTAuth::claims([
            'account_id' => $this->accountId,
            'impersonator_id' => $superAdmin->id,
            'is_impersonating' => true,
        ])->fromUser($this->admin);

        $this->postJson("/accounts/{$this->accountId}/api-keys", [
            'name' => 'Support test',
            'abilities' => ['orders:read'],
        ], $this->bearer($jwt))->assertStatus(ResponseCodes::HTTP_FORBIDDEN);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('account_id', $this->accountId)->count());
    }

    public function test_the_token_hash_is_never_serialized(): void
    {
        $keyId = (int) explode('|', $this->makeApiKey($this->admin, $this->accountId, ['events:read']))[0];

        $this->assertArrayNotHasKey('token', PersonalAccessToken::query()->findOrFail($keyId)->toArray());
        $this->assertArrayNotHasKey('token', PersonalAccessToken::query()->findOrFail($keyId)->attributesToArray());
    }

    private function updateUserStatus(User $user, UserStatus $status): TestResponse
    {
        return $this->putJson("/users/{$user->id}", [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'role' => Role::ADMIN->name,
            'status' => $status->name,
        ], $this->bearer($this->jwtFor($this->admin, $this->accountId)));
    }

    /**
     * @return array{0: User, 1: int, 2: int}
     */
    private function makeKeysOfASharedUser(): array
    {
        [, $otherAccountId] = $this->makeAccountWithAdmin();
        $sharedUser = $this->addUserToAccount($otherAccountId, Role::ADMIN);
        $sharedUser->accounts()->attach($this->accountId, [
            'role' => Role::ADMIN->value,
            'status' => 'ACTIVE',
            'is_account_owner' => false,
        ]);

        $survivingKeyId = (int) explode('|', $this->makeApiKey($sharedUser, $otherAccountId, ['events:read']))[0];
        $doomedKeyId = (int) explode('|', $this->makeApiKey($sharedUser, $this->accountId, ['events:read']))[0];

        return [$sharedUser, $survivingKeyId, $doomedKeyId];
    }
}

<?php

namespace Tests\Feature\ApiKeys;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

trait InteractsWithApiKeys
{
    protected function seedAccountConfiguration(): void
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);
    }

    /**
     * @return array{0: User, 1: int} the account owner (ADMIN) and the account ID
     */
    protected function makeAccountWithAdmin(): array
    {
        $user = User::factory()->withAccount()->create();

        return [$user, $user->accounts()->first()->id];
    }

    protected function addUserToAccount(int $accountId, Role $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = User::factory()->create();

        $user->accounts()->attach($accountId, [
            'role' => $role->value,
            'status' => $status->name,
            'is_account_owner' => false,
        ]);

        return $user;
    }

    protected function makeOrganizer(int $accountId): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $accountId,
            'name' => 'Test Organizer',
            'email' => 'organizer-'.uniqid().'@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeEvent(int $accountId, int $userId, int $organizerId): int
    {
        return DB::table('events')->insertGetId([
            'title' => 'API Key Test Event',
            'account_id' => $accountId,
            'user_id' => $userId,
            'organizer_id' => $organizerId,
            'currency' => 'USD',
            'timezone' => 'UTC',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeApiKey(
        User $creator,
        int $accountId,
        array $abilities,
        ?CarbonInterface $expiresAt = null,
    ): string {
        $secret = 'hie_'.Str::random(40);

        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => $creator->id,
            'account_id' => $accountId,
            'name' => 'Test key',
            'token' => hash('sha256', $secret),
            'partial_token' => substr($secret, -4),
            'abilities' => json_encode($abilities),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id.'|'.$secret;
    }

    protected function jwtFor(User $user, int $accountId): string
    {
        return JWTAuth::claims(['account_id' => $accountId])->fromUser($user);
    }

    protected function bearer(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}

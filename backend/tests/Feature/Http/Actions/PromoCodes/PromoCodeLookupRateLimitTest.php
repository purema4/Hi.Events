<?php

namespace Tests\Feature\Http\Actions\PromoCodes;

use Tests\TestCase;

class PromoCodeLookupRateLimitTest extends TestCase
{
    public function test_public_promo_code_lookup_is_throttled_at_the_configured_limit(): void
    {
        config(['app.promo_code_lookup_rate_limit_per_minute' => 2]);

        $this->getJson('/public/events/999999/promo-codes/GUESS1')->assertOk();
        $this->getJson('/public/events/999999/promo-codes/GUESS2')->assertOk();
        $this->getJson('/public/events/999999/promo-codes/GUESS3')->assertStatus(429);
    }

    public function test_public_promo_code_lookup_defaults_to_ten_per_minute(): void
    {
        $this->assertSame(10, config('app.promo_code_lookup_rate_limit_per_minute'));
    }
}

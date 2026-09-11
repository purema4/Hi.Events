<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\Services\Domain\GoogleWallet\GoogleWalletIdGenerator;
use Illuminate\Config\Repository;
use Tests\TestCase;

class GoogleWalletIdGeneratorTest extends TestCase
{
    private function generator(array $config = []): GoogleWalletIdGenerator
    {
        return new GoogleWalletIdGenerator(new Repository([
            'google-wallet' => array_merge([
                'issuer_id' => '3388000000022222228',
                'id_prefix' => 'hievents',
            ], $config),
        ]));
    }

    public function test_class_id_is_namespaced_by_issuer_and_prefix(): void
    {
        $this->assertSame(
            '3388000000022222228.hievents_occurrence_42',
            $this->generator()->classIdForOccurrence(42),
        );
    }

    public function test_object_id_is_namespaced_by_issuer_and_prefix(): void
    {
        $this->assertSame(
            '3388000000022222228.hievents_attendee_7',
            $this->generator()->objectIdForAttendee(7),
        );
    }

    public function test_prefix_keeps_separate_environments_apart(): void
    {
        $this->assertSame(
            '3388000000022222228.staging_occurrence_42',
            $this->generator(['id_prefix' => 'staging'])->classIdForOccurrence(42),
        );
    }

    public function test_characters_google_rejects_are_stripped_from_the_prefix(): void
    {
        $this->assertSame(
            '3388000000022222228.mysite_occurrence_1',
            $this->generator(['id_prefix' => 'my.site!'])->classIdForOccurrence(1),
        );
    }

    public function test_an_empty_prefix_falls_back_to_a_default(): void
    {
        $this->assertSame(
            '3388000000022222228.hievents_attendee_1',
            $this->generator(['id_prefix' => '???'])->objectIdForAttendee(1),
        );
    }
}

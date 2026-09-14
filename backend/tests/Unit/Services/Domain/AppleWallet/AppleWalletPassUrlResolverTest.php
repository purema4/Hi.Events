<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassUrlResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletUrlGenerator;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;
use Mockery;
use Tests\TestCase;

class AppleWalletPassUrlResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function resolver(?WalletPassBrandingDTO $passSettings): AppleWalletPassUrlResolver
    {
        $settingsResolver = Mockery::mock(AppleWalletPassSettingsResolver::class);
        $settingsResolver->shouldReceive('resolveForOrganizer')->with(5)->andReturn($passSettings);

        return new AppleWalletPassUrlResolver(
            $settingsResolver,
            new AppleWalletUrlGenerator(new Repository(['apple-wallet' => ['api_url' => 'https://tickets.example.com/api/']])),
        );
    }

    private function enabled(): WalletPassBrandingDTO
    {
        return new WalletPassBrandingDTO(logoUrl: null, bannerImageUrl: null, backgroundColor: null);
    }

    public function test_one_link_downloads_every_ticket_in_the_email(): void
    {
        $url = $this->resolver($this->enabled())->resolveForAttendees(
            collect([
                (new AttendeeDomainObject)->setShortId('a_first'),
                (new AttendeeDomainObject)->setShortId('a_second'),
            ]),
            (new EventDomainObject)->setId(10),
            (new OrganizerDomainObject)->setId(5),
        );

        $this->assertSame(
            'https://tickets.example.com/api/public/events/10/apple-wallet-passes?attendees=a_first%2Ca_second',
            $url,
        );
    }

    public function test_there_is_no_link_when_the_organizer_has_not_enabled_apple_wallet(): void
    {
        $this->assertNull($this->resolver(null)->resolveForAttendees(
            collect([(new AttendeeDomainObject)->setShortId('a_first')]),
            (new EventDomainObject)->setId(10),
            (new OrganizerDomainObject)->setId(5),
        ));
    }

    public function test_the_web_service_lives_under_the_api(): void
    {
        $generator = new AppleWalletUrlGenerator(new Repository(['apple-wallet' => ['api_url' => 'https://tickets.example.com/api/']]));

        $this->assertSame('https://tickets.example.com/api/apple-wallet', $generator->webServiceUrl());
    }
}

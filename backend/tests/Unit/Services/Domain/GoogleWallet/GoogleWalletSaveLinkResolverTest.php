<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveLinkResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletUrlGenerator;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;
use Mockery;
use Tests\TestCase;

class GoogleWalletSaveLinkResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function resolver(?WalletPassBrandingDTO $passSettings): GoogleWalletSaveLinkResolver
    {
        $settingsResolver = Mockery::mock(GoogleWalletPassSettingsResolver::class);
        $settingsResolver->shouldReceive('resolveForOrganizer')->with(5)->andReturn($passSettings);

        return new GoogleWalletSaveLinkResolver(
            $settingsResolver,
            new GoogleWalletUrlGenerator(new Repository(['google-wallet' => ['api_url' => 'https://tickets.example.com/api/']])),
        );
    }

    private function enabled(): WalletPassBrandingDTO
    {
        return new WalletPassBrandingDTO(logoUrl: null, bannerImageUrl: null, appleStripImageUrl: null, backgroundColor: null, themeAccentColor: null);
    }

    public function test_one_link_saves_every_ticket_in_the_email(): void
    {
        $url = $this->resolver($this->enabled())->resolveForAttendees(
            collect([
                (new AttendeeDomainObject)->setShortId('a_first'),
                (new AttendeeDomainObject)->setShortId('a_second'),
            ]),
            10,
            (new OrganizerDomainObject)->setId(5),
        );

        $this->assertSame(
            'https://tickets.example.com/api/public/events/10/google-wallet-save?attendees=a_first%2Ca_second',
            $url,
        );
    }

    public function test_the_link_joins_an_api_url_that_has_no_trailing_slash(): void
    {
        $settingsResolver = Mockery::mock(GoogleWalletPassSettingsResolver::class);
        $settingsResolver->shouldReceive('resolveForOrganizer')->with(5)->andReturn($this->enabled());

        $resolver = new GoogleWalletSaveLinkResolver(
            $settingsResolver,
            new GoogleWalletUrlGenerator(new Repository(['google-wallet' => ['api_url' => 'https://tickets.example.com/api']])),
        );

        $this->assertSame(
            'https://tickets.example.com/api/public/events/10/google-wallet-save?attendees=a_first',
            $resolver->resolveForAttendees(
                collect([(new AttendeeDomainObject)->setShortId('a_first')]),
                10,
                (new OrganizerDomainObject)->setId(5),
            ),
        );
    }

    public function test_there_is_no_link_when_the_organizer_has_not_enabled_google_wallet(): void
    {
        $this->assertNull($this->resolver(null)->resolveForAttendees(
            collect([(new AttendeeDomainObject)->setShortId('a_first')]),
            10,
            (new OrganizerDomainObject)->setId(5),
        ));
    }

    public function test_there_is_no_link_without_attendees(): void
    {
        $this->assertNull($this->resolver($this->enabled())->resolveForAttendees(
            collect(),
            10,
            (new OrganizerDomainObject)->setId(5),
        ));
    }
}

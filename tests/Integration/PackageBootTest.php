<?php

namespace MM\Meros\EmailConnectors\Tests\Integration;

use MM\Meros\EmailConnectors\App\Integrations\Mailchimp;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365SharedMailbox;
use MM\Meros\EmailConnectors\MerosEmailConnectors;
use MM\Meros\Facades\Data\Integrations;
use MM\Meros\Testing\PackageTestCase;

final class PackageBootTest extends PackageTestCase
{
    protected function packageClass(): string { return MerosEmailConnectors::class; }
    protected function packageHandle(): string { return 'meros_email_connectors'; }

    public function test_enabled_package_exposes_email_integrations(): void
    {
        $this->package();
        $this->assertInstanceOf(Mailchimp::class, Integrations::connect('mailchimp'));
        $this->assertInstanceOf(Microsoft365::class, Integrations::all()->first(fn ($item) => $item instanceof Microsoft365));
        $this->assertInstanceOf(Microsoft365SharedMailbox::class, Integrations::all()->first(fn ($item) => $item instanceof Microsoft365SharedMailbox));
    }
}

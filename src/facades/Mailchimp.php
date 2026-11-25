<?php

namespace MM\Meros\EmailConnectors\Facades;

use Illuminate\Support\Facades\Facade;
use MM\Meros\EmailConnectors\App\Integrations\Mailchimp as MailchimpIntegration;

/** @deprecated Use \MM\Meros\Facades\Integrations::connect('mailchimp') instead. */
class Mailchimp extends Facade {
    protected static function getFacadeAccessor() {
        return MailchimpIntegration::class;
    }
}

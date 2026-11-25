<?php

namespace MM\Meros\EmailConnectors\Facades;

use Illuminate\Support\Facades\Facade;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365SharedMailbox as SharedMailboxIntegration;

/** @deprecated Use \MM\Meros\Facades\Integrations::connect('microsoft365_shared_mailbox') instead. */
class Microsoft365SharedMailbox extends Facade {
    protected static function getFacadeAccessor() {
        return SharedMailboxIntegration::class;
    }
}

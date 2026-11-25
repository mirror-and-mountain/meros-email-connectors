<?php 

namespace MM\Meros\EmailConnectors\Facades;

use Illuminate\Support\Facades\Facade;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365 as MSIntegration;

/** @deprecated Use \MM\Meros\Facades\Integrations::connect('microsoft365') instead. */
class Microsoft365 extends Facade {
    protected static function getFacadeAccessor() {
        return MSIntegration::class;
    }
}
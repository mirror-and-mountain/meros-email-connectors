<?php 

namespace MM\Meros\EmailConnectors\Facades;

use Illuminate\Support\Facades\Facade;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365 as MSIntegration;

class Microsoft365 extends Facade {
    protected static function getFacadeAccessor() {
        return MSIntegration::class;
    }
}
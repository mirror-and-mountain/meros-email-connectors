<?php 

namespace MM\Meros\EmailConnectors;

use MM\Meros\App\Providers\PackageServiceProvider;

class ServiceProvider extends PackageServiceProvider {
    protected function init(): void {
        $this->setPackageClass(MerosEmailConnectors::class);
    }
}
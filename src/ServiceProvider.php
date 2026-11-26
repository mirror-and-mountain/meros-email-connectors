<?php 

namespace MM\Meros\EmailConnectors;

use MM\Meros\App\Providers\PackageServiceProvider;

class ServiceProvider extends PackageServiceProvider {
    protected function init(): void {
        $this->setPackageClass(MerosEmailConnectors::class);
    }

    protected function beforeBoot(): void {
        $configPath = dirname(__DIR__) . '/config';
        $this->publishesConfig($configPath, 'meros-email-connectors-');
        $this->mergePackageConfig(
            $configPath . '/integrations.php',
            'integrations',
            'meros-email-connectors-integrations.php'
        );
    }
}
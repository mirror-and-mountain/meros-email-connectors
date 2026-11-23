<?php

namespace MM\Meros\EmailConnectors;

use MM\Meros\App\Package;

final class MerosEmailConnectors extends Package {
    /**
     * Initialises the package.
     *
     * @return void
     */
    protected function init(): void {
        $this->setAuthor('Meros');
        $this->setAuthorUrl('https://meroscrm.com');
        $this->setSupportUrl('https://meroscrm.com/support');
        $this->setDescription('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua');
    }

    public function configure(): void {
        
    }


}

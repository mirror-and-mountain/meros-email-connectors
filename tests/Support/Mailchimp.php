<?php

namespace MM\Meros\EmailConnectors\Tests\Support {
    use MailchimpMarketing\ApiClient as MarketingClient;
    use MailchimpTransactional\ApiClient as TransactionalClient;
    use MM\Meros\Contracts\Features\Admin\SettingsContainer;
    use MM\Meros\EmailConnectors\App\Integrations\Mailchimp;

    class TestMailchimp extends Mailchimp {
        public MarketingClient $marketing;
        public TransactionalClient $transactional;
        public array $errors = [];
        protected function marketingClient(): MarketingClient { return $this->marketing; }
        protected function transactionalClient(): TransactionalClient { return $this->transactional; }
        protected function logError(string $title, string $message = '', ?int $code = null): void { $this->errors[] = [$title, $message, $code]; }
    }

    class MailchimpTestSettings extends SettingsContainer {
        public function initialiseForTest(): void {
            $this->identifier('name', 'snake');
            $this->name = 'mailchimp_test';
        }
    }

    class MailchimpClientDouble extends Mailchimp {
        public array $errors = [];
        protected function logError(string $title, string $message = '', ?int $code = null): void { $this->errors[] = [$title, $message, $code]; }
        public function makeMarketingClient(): MarketingClient { return $this->marketingClient(); }
        public function makeTransactionalClient(): TransactionalClient { return $this->transactionalClient(); }
    }
}

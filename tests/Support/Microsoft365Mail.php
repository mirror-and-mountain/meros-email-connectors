<?php

namespace MM\Meros\EmailConnectors\Tests\Support {
    use GuzzleHttp\Psr7\Response as GuzzleResponse;
    use Illuminate\Http\Client\Response;
    use MM\Meros\Contracts\Features\Admin\SettingsContainer;
    use MM\Meros\EmailConnectors\App\Integrations\Microsoft365;
    use MM\Meros\EmailConnectors\App\Integrations\Microsoft365SharedMailbox;
    use MM\Meros\Support\HttpClient;

    class SharedMailboxTestSettings extends SettingsContainer {
        public function initialiseForTest(): void {
            $this->identifier('name', 'snake');
            $this->name = 'shared_mail_test';
        }
    }

    class MailTestHttpClient extends HttpClient {
        public array $requests = [];
        public array $statuses = [];
        public function send(array $request): Response {
            $this->requests[] = $request;
            return new Response(new GuzzleResponse(array_shift($this->statuses)));
        }
    }

    class MailTestMicrosoft365 extends Microsoft365 {
        public MailTestHttpClient $client;
        public array $errors = [];
        public ?string $token = 'original-token';
        protected function getConnection(): ?array { return ['status' => 'connected']; }
        protected function getAccessToken(): ?string { return $this->token; }
        protected function refreshToken(): ?string { return 'refreshed-token'; }
        protected function httpClient(): HttpClient { return $this->client; }
        protected function logError(string $title, string $message = '', ?int $code = null): void {
            $this->errors[] = [$title, $message, $code];
        }
    }

    class MailTestSharedMailbox extends Microsoft365SharedMailbox {
        public MailTestHttpClient $client;
        public array $errors = [];
        protected function getConnection(): ?array { return ['status' => 'connected']; }
        protected function getAccessToken(): ?string { return 'original-token'; }
        protected function refreshToken(): ?string { return 'refreshed-token'; }
        protected function httpClient(): HttpClient { return $this->client; }
        protected function logError(string $title, string $message = '', ?int $code = null): void {
            $this->errors[] = [$title, $message, $code];
        }
        public function authorizationParams(): array { return $this->getAuthorizationParams(); }
        public function requiredSettings(): array { return $this->requiredConnectionSettings(); }
    }
}

namespace {
    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['options'][$name] ?? $default;
    }
}

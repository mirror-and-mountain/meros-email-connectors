<?php

require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/../src/app/Integrations/Microsoft365.php';
require __DIR__ . '/../src/app/Integrations/Microsoft365SharedMailbox.php';

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response;
use MM\Meros\Contracts\Features\Admin\SettingsContainer;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365;
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365SharedMailbox;
use MM\Meros\Support\HttpClient;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['options'][$name] ?? $default;
}

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

    protected function getConnection(): ?array {
        return ['status' => 'connected'];
    }

    protected function getAccessToken(): ?string {
        return $this->token;
    }

    protected function refreshToken(): ?string {
        return 'refreshed-token';
    }

    protected function httpClient(): HttpClient {
        return $this->client;
    }

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message, $code];
    }
}

class MailTestSharedMailbox extends Microsoft365SharedMailbox {
    public MailTestHttpClient $client;
    public array $errors = [];

    protected function getConnection(): ?array {
        return ['status' => 'connected'];
    }

    protected function getAccessToken(): ?string {
        return 'original-token';
    }

    protected function refreshToken(): ?string {
        return 'refreshed-token';
    }

    protected function httpClient(): HttpClient {
        return $this->client;
    }

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message, $code];
    }

    public function authorizationParams(): array {
        return $this->getAuthorizationParams();
    }

    public function requiredSettings(): array {
        return $this->requiredConnectionSettings();
    }
}

function initialiseMailer(Integration $mailer, SettingsContainer $settings): void {
    (new ReflectionProperty(Integration::class, 'settings'))->setValue($mailer, $settings);
    (new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($mailer, $settings);
    (new ReflectionMethod(Integration::class, 'identifier'))->invoke($mailer, 'name', 'snake');
    (new ReflectionProperty(Integration::class, 'name'))->setValue($mailer, 'test_mail');
}

function mailer(array $statuses): MailTestMicrosoft365 {
    $mailer = (new ReflectionClass(MailTestMicrosoft365::class))->newInstanceWithoutConstructor();
    $settings = (new ReflectionClass(SharedMailboxTestSettings::class))->newInstanceWithoutConstructor();
    $settings->initialiseForTest();
    initialiseMailer($mailer, $settings);
    $GLOBALS['options']['shared_mail_test'] = ['test_mail_enabled' => true];
    (new ReflectionProperty(Integration::class, 'enabled'))->setValue($mailer, true);
    $mailer->client = new MailTestHttpClient();
    $mailer->client->statuses = $statuses;
    return $mailer;
}

$mailer = mailer([401, 202]);
check($mailer->sendMail(
    ['first@example.com'],
    'Subject',
    '<p>Hello</p>',
    ['a' => 'cc1@example.com', 'b' => 'cc2@example.com'],
    ['bcc@example.com'],
    true
), 'Expected successful submission after refresh.');
$first = $mailer->client->requests[0];
$retry = $mailer->client->requests[1];
check($first['payload']['message']['toRecipients'] === [
    ['emailAddress' => ['address' => 'first@example.com']],
], 'Expected To recipient.');
check($first['payload']['message']['ccRecipients'] === [
    ['emailAddress' => ['address' => 'cc1@example.com']],
    ['emailAddress' => ['address' => 'cc2@example.com']],
], 'Expected a list of CC recipients.');
check($first['payload']['message']['bccRecipients'] === [
    ['emailAddress' => ['address' => 'bcc@example.com']],
], 'Expected BCC recipient.');
check($first['payload']['message']['body']['contentType'] === 'HTML', 'Expected HTML body.');
check($retry['payload'] === $first['payload'], 'Retry must preserve all recipients.');
check($retry['headers']['Authorization'] === 'Bearer refreshed-token', 'Retry must use refreshed token.');

$mailer = mailer([202]);
check($mailer->sendMail(['first@example.com'], 'Subject', 'Text'), 'Expected successful submission without CC or BCC.');
$message = $mailer->client->requests[0]['payload']['message'];
check(!array_key_exists('ccRecipients', $message) && !array_key_exists('bccRecipients', $message), 'Empty optional recipients should be omitted.');
check($message['body']['contentType'] === 'Text', 'Expected text body by default.');
check($mailer->client->requests[0]['url'] === 'https://graph.microsoft.com/v1.0/me/sendMail' && !isset($message['from']), 'Personal mailbox behavior must remain unchanged.');

$mailer = mailer([]);
$mailer->token = null;
check(!$mailer->ready() && !$mailer->sendMail(['first@example.com'], 'Subject', 'Text'), 'A missing access token must prevent sending.');
check($mailer->client->requests === [] && $mailer->errors !== [], 'Missing-token failures must be logged before making a request.');
$mailer->token = 'original-token';
$GLOBALS['options']['shared_mail_test']['test_mail_enabled'] = false;
check(!$mailer->ready() && !$mailer->sendMail(['first@example.com'], 'Subject', 'Text'), 'Disabling the integration must prevent sending.');

foreach ([
    [['bad-address'], [], 'ccRecipients'],
    [[], [new stdClass()], 'bccRecipients'],
] as [$cc, $bcc, $field]) {
    $mailer = mailer([]);
    check(!$mailer->sendMail(['first@example.com'], 'Subject', 'Text', $cc, $bcc), 'Invalid optional recipient must fail.');
    check($mailer->client->requests === [], 'Invalid optional recipient must not send a request.');
    check(str_contains($mailer->errors[0][1], $field), 'Expected the invalid recipient group in the error.');
}

$settings = (new ReflectionClass(SharedMailboxTestSettings::class))->newInstanceWithoutConstructor();
$settings->initialiseForTest();
$shared = (new ReflectionClass(MailTestSharedMailbox::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Integration::class, 'enabled'))->setValue($shared, true);
initialiseMailer($shared, $settings);
$shared->client = new MailTestHttpClient();
$shared->client->statuses = [401, 202];
$GLOBALS['options']['shared_mail_test'] = ['test_mail_enabled' => true, 'shared_mailbox_default' => 'helpdesk+team@example.com'];

check($shared->ready(), 'A configured shared mailbox with an active connection must be ready.');
check(in_array('shared_mailbox', $shared->requiredSettings(), true), 'Shared mailbox must be a required connection setting.');
check($shared->authorizationParams()['scope'] === 'https://graph.microsoft.com/Mail.Send.Shared offline_access', 'Expected delegated shared-mail scope.');
check($shared->sendMail(['to@example.com'], 'Shared subject', 'Body', ['cc@example.com'], ['bcc@example.com']), 'Expected successful shared mailbox submission.');
$first = $shared->client->requests[0];
$retry = $shared->client->requests[1];
check($first['url'] === 'https://graph.microsoft.com/v1.0/users/helpdesk%2Bteam%40example.com/sendMail', 'Expected the encoded shared mailbox endpoint.');
check($first['payload']['message']['from'] === ['emailAddress' => ['address' => 'helpdesk+team@example.com']], 'Expected the shared mailbox as sender.');
check($first['payload']['message']['ccRecipients'][0]['emailAddress']['address'] === 'cc@example.com', 'Expected CC on the shared message.');
check($first['payload']['message']['bccRecipients'][0]['emailAddress']['address'] === 'bcc@example.com', 'Expected BCC on the shared message.');
check($retry['url'] === $first['url'] && $retry['payload'] === $first['payload'], 'Refresh retry must preserve the shared mailbox and message.');

$shared->client->requests = [];
$shared->client->statuses = [403];
check(!$shared->sendMail(['to@example.com'], 'Subject', 'Body'), 'A shared mailbox permission failure must be reported.');
check(count($shared->client->requests) === 1 && $shared->client->requests[0]['url'] === $first['url'], 'Permission failure must not fall back to the signed-in mailbox.');
check(end($shared->errors)[0] === 'Message rejected', 'Expected a Graph rejection error.');

foreach (['', 'not-an-email'] as $invalidAddress) {
    $GLOBALS['options']['shared_mail_test']['shared_mailbox_default'] = $invalidAddress;
    check(!$shared->ready(), 'An invalid shared mailbox must not be ready.');
    $shared->client->requests = [];
    check(!$shared->sendMail(['to@example.com'], 'Subject', 'Body'), 'Invalid shared mailbox must fail.');
    check($shared->client->requests === [], 'Invalid shared mailbox must not send as the connected user.');
    check(end($shared->errors)[0] === 'Integration not ready', 'Expected a readiness error.');
}

echo "Microsoft 365 mail tests passed.\n";

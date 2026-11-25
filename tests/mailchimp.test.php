<?php

require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/app/Integrations/Mailchimp.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MailchimpMarketing\ApiClient as MarketingClient;
use MailchimpMarketing\Api\ListsApi;
use MailchimpTransactional\ApiClient as TransactionalClient;
use MailchimpTransactional\Configuration as TransactionalConfiguration;
use MM\Meros\Contracts\Features\Admin\SettingsContainer;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Contracts\Features\Integrations\Interfaces\Mailer;
use MM\Meros\EmailConnectors\App\Integrations\Mailchimp;

function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['options'][$name] ?? $default;
}

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(callable $fn, string $type, string $message): void {
    try {
        $fn();
    } catch (Throwable $exception) {
        check($exception instanceof $type && str_contains($exception->getMessage(), $message), 'Unexpected exception: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected exception: ' . $message);
}

class TestMailchimp extends Mailchimp {
    public MarketingClient $marketing;
    public TransactionalClient $transactional;
    public array $errors = [];

    protected function marketingClient(): MarketingClient {
        return $this->marketing;
    }

    protected function transactionalClient(): TransactionalClient {
        return $this->transactional;
    }

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message, $code];
    }
}

class MailchimpTestSettings extends SettingsContainer {
    public function initialiseForTest(): void {
        $this->identifier('name', 'snake');
        $this->name = 'mailchimp_test';
    }
}

class MailchimpClientTest extends Mailchimp {
    public array $errors = [];

    protected function logError(string $title, string $message = '', ?int $code = null): void {
        $this->errors[] = [$title, $message, $code];
    }

    public function makeMarketingClient(): MarketingClient {
        return $this->marketingClient();
    }

    public function makeTransactionalClient(): TransactionalClient {
        return $this->transactionalClient();
    }
}

function response(int $status, string $body = ''): Response {
    return new Response($status, ['Content-Type' => 'application/json'], $body);
}

$history = [];
$mock = new MockHandler([
    response(200, '{"lists":[],"total_items":0}'),
    response(200, '{"members":[],"total_items":0}'),
    response(200, '{"email_address":"contact@example.com"}'),
    response(200, '{"status":"pending"}'),
    response(200, '{"status":"unsubscribed"}'),
    response(200, '{"status":"unsubscribed"}'),
    response(200, '{"status":"unsubscribed"}'),
    response(204),
    response(204),
    response(204),
    response(200, '[{"email":"to@example.com","status":"sent"}]'),
    response(200, '[{"email":"to@example.com","status":"rejected","reject_reason":"hard-bounce"}]'),
    response(200, '[{"slug":"welcome"}]'),
    response(200, '{"slug":"welcome"}'),
    response(403, '{"error":"denied"}'),
    response(401, '{"name":"Invalid_Key"}'),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$http = new Client(['handler' => $stack]);
$marketing = new MarketingClient();
$marketing->setConfig(['apiKey' => 'fake-us7', 'server' => 'us7']);
(new ReflectionProperty(ListsApi::class, 'client'))->setValue($marketing->lists, $http);
$transactional = new TransactionalClient();
$transactional->setApiKey('fake-transactional-key');
(new ReflectionProperty(TransactionalConfiguration::class, 'requestClient'))->setValue($transactional, $http);
$mailchimp = (new ReflectionClass(TestMailchimp::class))->newInstanceWithoutConstructor();
$mailchimp->marketing = $marketing;
$mailchimp->transactional = $transactional;
$settings = (new ReflectionClass(MailchimpTestSettings::class))->newInstanceWithoutConstructor();
$settings->initialiseForTest();
(new ReflectionProperty(Integration::class, 'settings'))->setValue($mailchimp, $settings);
(new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($mailchimp, $settings);
(new ReflectionMethod(Integration::class, 'identifier'))->invoke($mailchimp, 'name', 'snake');
(new ReflectionProperty(Integration::class, 'name'))->setValue($mailchimp, 'test_mailchimp');
$GLOBALS['options']['mailchimp_test'] = [
    'test_mailchimp_enabled' => true,
    'marketing_api_key_default' => 'fake-key-us7',
    'transactional_api_key_default' => 'fake-transactional',
    'transactional_from_email_default' => 'sender@example.com',
    'transactional_from_name_default' => 'Example Sender',
];
check($mailchimp instanceof Mailer, 'Mailchimp must implement the Mailer contract.');

$mailchimp->getAudiences(25, 50);
$mailchimp->getContacts('audience', 40, 20);
$mailchimp->getContact('audience', 'Contact@Example.com');
$mailchimp->createContact('audience', 'Contact@Example.com', ['merge_fields' => ['FNAME' => 'Test']]);
$mailchimp->addContactToAudience('audience', 'Contact@Example.com', ['status' => 'subscribed']);
$mailchimp->updateContact('audience', 'Contact@Example.com', ['merge_fields' => ['FNAME' => 'Updated']]);
$mailchimp->unsubscribeContact('audience', 'Contact@Example.com');
$mailchimp->removeContactFromAudience('audience', 'Contact@Example.com');
$mailchimp->addTag('audience', 'Contact@Example.com', 'VIP');
$mailchimp->removeTag('audience', 'Contact@Example.com', 'VIP');
check($mailchimp->sendMail(['to@example.com'], 'Subject', 'Hello'), 'Expected successful text send.');
check(!$mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, ['main' => 'Hello'], ['FIRST' => 'Alex']), 'Recipient rejections must return false.');
check(count($mailchimp->errors) === 1, 'Recipient rejections must be logged.');
$mailchimp->getTemplates('welcome');
$mailchimp->getTemplate('welcome');

$request = function (int $index) use (&$history) { return $history[$index]['request']; };
$json = fn (int $index) => json_decode((string) $request($index)->getBody(), true);
$hash = md5('contact@example.com');
check(str_contains((string) $request(0)->getUri(), 'count=25') && str_contains((string) $request(0)->getUri(), 'offset=50'), 'Audience pagination must reach the API.');
check(str_contains((string) $request(1)->getUri(), '/lists/audience/members') && str_contains((string) $request(1)->getUri(), 'offset=20'), 'Contact pagination must reach the API.');
check(str_ends_with($request(2)->getUri()->getPath(), '/members/' . $hash), 'Email must map to the lowercased subscriber hash.');
check($json(3)['status'] === 'pending' && $json(3)['merge_fields']['FNAME'] === 'Test', 'New contacts must default to pending with merge fields.');
check($request(4)->getMethod() === 'PUT' && $json(4)['status_if_new'] === 'pending' && !array_key_exists('status', $json(4)), 'Adding an existing contact must not change subscription status.');
check($request(5)->getMethod() === 'PATCH' && $json(5)['merge_fields']['FNAME'] === 'Updated', 'Expected contact update.');
check($json(6)['status'] === 'unsubscribed', 'Unsubscribe must update status.');
check($request(7)->getMethod() === 'DELETE' && str_ends_with($request(7)->getUri()->getPath(), '/members/' . $hash), 'Remove must archive, not permanently delete.');
check($json(8)['tags'][0] === ['name' => 'VIP', 'status' => 'active'] && $json(9)['tags'][0]['status'] === 'inactive', 'Expected tag activation and removal.');
check($request(10)->getUri()->getPath() === '/api/1.3/messages/send' && $json(10)['message']['text'] === 'Hello', 'Expected transactional send.');
check($json(10)['message']['from_email'] === 'sender@example.com' && $json(10)['message']['from_name'] === 'Example Sender', 'Expected configured sender.');
check($request(11)->getUri()->getPath() === '/api/1.3/messages/send-template' && $json(11)['template_content'][0] === ['name' => 'main', 'content' => 'Hello'], 'Expected template send with content.');
check($json(11)['message']['global_merge_vars'][0] === ['name' => 'FIRST', 'content' => 'Alex'] && !isset($json(11)['message']['subject']), 'Expected merge variables and template subject default.');
check($json(12)['label'] === 'welcome' && $json(13)['name'] === 'welcome', 'Expected template list and info requests.');

expectException(fn () => $mailchimp->getAudiences(), RuntimeException::class, 'HTTP 403');
expectException(fn () => $mailchimp->getTemplates(), RuntimeException::class, 'HTTP 401');
check(count($mailchimp->errors) === 3, 'Both SDK failure paths must log.');
expectException(fn () => $mailchimp->getContacts('audience', 0), InvalidArgumentException::class, 'Count');
expectException(fn () => $mailchimp->addTag('audience', 'bad', 'VIP'), InvalidArgumentException::class, 'email');
check(!$mailchimp->sendMailWithTemplate('', ['to@example.com']), 'An empty template slug must fail.');
check(count($history) === 16, 'Invalid input must not cause HTTP requests.');

$mock->append(
    response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"queued"},{"email":"bcc@example.com","status":"scheduled"}]'),
    response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"rejected"}]'),
    response(200, '[{"email":"to@example.com","status":"sent"}]'),
    response(200, '[{"email":"to@example.com","status":"sent"}]'),
    response(401, '{"name":"Invalid_Key"}')
);
check($mailchimp->sendMail(['to@example.com'], 'HTML subject', '<p>Hello</p>', ['cc@example.com'], ['bcc@example.com'], true), 'Expected successful HTML send with CC and BCC.');
check($json(16)['message']['html'] === '<p>Hello</p>' && !isset($json(16)['message']['text']), 'Expected HTML body only.');
check($json(16)['message']['to'] === [
    ['email' => 'to@example.com', 'type' => 'to'],
    ['email' => 'cc@example.com', 'type' => 'cc'],
    ['email' => 'bcc@example.com', 'type' => 'bcc'],
], 'Expected Mailchimp recipient types.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', ['cc@example.com']), 'A partial recipient rejection must fail.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', ['cc@example.com']), 'A response missing a recipient must fail.');
check($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], 'Custom subject'), 'Template subject override must send.');
check($json(19)['message']['subject'] === 'Custom subject' && $json(19)['template_content'] === [], 'Expected template subject override and empty content blocks.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'), 'API errors must return false.');
check(end($mailchimp->errors)[0] === 'Mailchimp transactional send failed', 'API send failure must be logged.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', [], [new stdClass()]), 'Invalid recipients must fail.');
check(!$mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [0 => 'bad']), 'Invalid template content must fail.');
check(!$mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [], ['X' => []]), 'Invalid merge variables must fail.');
$GLOBALS['options']['mailchimp_test']['transactional_from_email_default'] = 'bad';
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'), 'An invalid sender must fail.');
check(count($history) === 21, 'Invalid sends must not make HTTP requests.');

$GLOBALS['options']['mailchimp_test']['transactional_from_email_default'] = 'sender@example.com';
unset($GLOBALS['options']['mailchimp_test']['transactional_from_name_default']);
$mock->append(
    response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"sent"},{"email":"bcc@example.com","status":"queued"}]'),
    response(200, '[{"email":"other@example.com","status":"sent"}]'),
    response(200, '[{"email":"to@example.com","status":"unknown"}]')
);
check($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [], [], ['cc@example.com'], ['bcc@example.com']), 'Expected template send with CC and BCC.');
check($json(21)['message']['to'] === $json(16)['message']['to'] && !isset($json(21)['message']['from_name']), 'Expected template recipient types and optional sender name.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'), 'An unexpected recipient response must fail.');
check(!$mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'), 'An unknown recipient status must fail.');

$configured = (new ReflectionClass(MailchimpClientTest::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Integration::class, 'settings'))->setValue($configured, $settings);
(new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($configured, $settings);
(new ReflectionMethod(Integration::class, 'identifier'))->invoke($configured, 'name', 'snake');
(new ReflectionProperty(Integration::class, 'name'))->setValue($configured, 'test_mailchimp');
$enabled = new ReflectionProperty(Integration::class, 'enabled');
$enabled->setValue($configured, true);
$GLOBALS['options']['mailchimp_test'] = [
    'test_mailchimp_enabled' => true,
    'marketing_api_key_default' => 'fake-key-us42',
    'transactional_api_key_default' => 'fake-transactional',
    'transactional_from_email_default' => 'sender@example.com',
];
$client = $configured->makeMarketingClient();
check($configured->ready() && $configured->ready('marketing') && $configured->ready('transactional'), 'Both configured services must be ready.');
check($client->getHost() === 'https://us42.api.mailchimp.com/3.0' && $client->getPassword() === 'fake-key-us42', 'Expected Marketing key and server suffix.');
check($configured->makeTransactionalClient()->getApiKey() === 'fake-transactional', 'Expected separate transactional key.');
unset($GLOBALS['options']['mailchimp_test']['transactional_api_key_default']);
check($configured->ready() && $configured->ready('marketing') && !$configured->ready('transactional'), 'Marketing-only configuration must be ready only for Marketing.');
expectException(fn () => $configured->getTemplates(), RuntimeException::class, 'transactional api key is not configured');
check(end($configured->errors)[0] === 'Missing Mailchimp API key', 'Missing transactional key must be logged.');
check($configured->makeMarketingClient()->getPassword() === 'fake-key-us42', 'Marketing must work without the transactional key.');
$GLOBALS['options']['mailchimp_test']['transactional_api_key_default'] = 'fake-transactional';
unset($GLOBALS['options']['mailchimp_test']['marketing_api_key_default']);
check($configured->ready() && !$configured->ready('marketing') && $configured->ready('transactional'), 'Transactional-only configuration must be ready only for Transactional.');
expectException(fn () => $configured->ready('unknown'), InvalidArgumentException::class, 'Unknown Mailchimp capability');
expectException(fn () => $configured->getAudiences(), RuntimeException::class, 'marketing api key is not configured');
check(end($configured->errors)[0] === 'Missing Mailchimp API key', 'Missing marketing key must be logged.');
check($configured->makeTransactionalClient()->getApiKey() === 'fake-transactional', 'Transactional must work without the marketing key.');
$GLOBALS['options']['mailchimp_test']['marketing_api_key_default'] = '   ';
expectException(fn () => $configured->getAudiences(), RuntimeException::class, 'not configured');
check(end($configured->errors)[0] === 'Missing Mailchimp API key', 'Whitespace-only key must be logged as missing.');
$GLOBALS['options']['mailchimp_test']['marketing_api_key_default'] = 'missingsuffix';
check(!$configured->ready('marketing'), 'A Marketing key without a server suffix must not be ready.');
expectException(fn () => $configured->makeMarketingClient(), RuntimeException::class, 'server suffix');
check(end($configured->errors)[0] === 'Invalid Mailchimp configuration', 'Invalid Marketing configuration must be logged.');
$GLOBALS['options']['mailchimp_test']['test_mailchimp_enabled'] = false;
check(!$configured->ready(), 'A disabled integration must not be ready.');
expectException(fn () => $configured->getAudiences(), RuntimeException::class, 'not enabled');
check(end($configured->errors)[0] === 'Mailchimp integration disabled', 'Disabled Marketing access must be logged.');
check(!$configured->sendMail(['to@example.com'], 'Subject', 'Body'), 'Disabled Transactional send must return false.');
check($configured->errors[count($configured->errors) - 2][0] === 'Mailchimp integration disabled', 'Disabled Transactional access must be logged.');
check(count($history) === 24, 'Configuration failures must not make HTTP requests.');

$GLOBALS['options']['mailchimp_test']['test_mailchimp_enabled'] = true;
unset($GLOBALS['options']['mailchimp_test']['marketing_api_key_default'], $GLOBALS['options']['mailchimp_test']['transactional_api_key_default']);
check(!$configured->ready() && !$configured->ready('marketing') && !$configured->ready('transactional'), 'Neither service is ready without a key.');
expectException(fn () => $mailchimp->getAudiences(), RuntimeException::class, 'marketing api key is not configured');
check(count($history) === 24, 'Request readiness must be enforced even with an overridden SDK client.');

echo "Mailchimp integration tests passed.\n";

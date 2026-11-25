<?php

namespace MM\Meros\EmailConnectors\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use MailchimpMarketing\ApiClient as MarketingClient;
use MailchimpMarketing\Api\ListsApi;
use MailchimpTransactional\ApiClient as TransactionalClient;
use MailchimpTransactional\Configuration as TransactionalConfiguration;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Contracts\Features\Integrations\Interfaces\Mailer;
use MM\Meros\EmailConnectors\Tests\Support\MailchimpClientDouble;
use MM\Meros\EmailConnectors\Tests\Support\MailchimpTestSettings;
use MM\Meros\EmailConnectors\Tests\Support\TestMailchimp;
use MM\Meros\Foundations\Testing\WordPressState;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;

class MailchimpTest extends TestCase {
    private MailchimpTestSettings $settings;
    private bool $hadOptions;
    private mixed $previousOptions;

    protected function setUp(): void {
        if (!function_exists('get_option')) {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                $stateFile = $loader->findFile(WordPressState::class);
                if ($stateFile !== false) {
                    require_once dirname($stateFile) . '/wordpress-stubs.php';
                    break;
                }
            }
        }
        if (!function_exists('get_option')) {
            throw new RuntimeException('Foundations WordPress test stubs are unavailable.');
        }
        require_once dirname(__DIR__) . '/Support/Mailchimp.php';
        $this->settings = (new ReflectionClass(MailchimpTestSettings::class))->newInstanceWithoutConstructor();
        $this->settings->initialiseForTest();
        $this->hadOptions = array_key_exists('mailchimp_test', WordPressState::$options);
        $this->previousOptions = WordPressState::$options['mailchimp_test'] ?? null;
        WordPressState::$options['mailchimp_test'] = [
            'test_mailchimp_enabled' => true,
            'marketing_api_key_default' => 'fake-key-us7',
            'transactional_api_key_default' => 'fake-transactional',
            'transactional_from_email_default' => 'sender@example.com',
            'transactional_from_name_default' => 'Example Sender',
        ];
    }

    protected function tearDown(): void {
        if ($this->hadOptions) {
            WordPressState::$options['mailchimp_test'] = $this->previousOptions;
        } else {
            unset(WordPressState::$options['mailchimp_test']);
        }
    }

    private function initialise(Integration $integration): void {
        (new ReflectionProperty(Integration::class, 'settings'))->setValue($integration, $this->settings);
        (new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($integration, $this->settings);
        (new ReflectionMethod(Integration::class, 'identifier'))->invoke($integration, 'name', 'snake');
        (new ReflectionProperty(Integration::class, 'name'))->setValue($integration, 'test_mailchimp');
    }

    private function response(int $status, string $body = ''): Response {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    private function assertFailure(callable $fn, string $type, string $message): void {
        try {
            $fn();
        } catch (Throwable $exception) {
            self::assertInstanceOf($type, $exception);
            self::assertStringContainsString($message, $exception->getMessage());
            return;
        }
        self::fail('Expected exception: ' . $message);
    }

    private function mockedClient(array $responses, array &$history): TestMailchimp {
        $mock = new MockHandler($responses);
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
        $this->initialise($mailchimp);
        return $mailchimp;
    }

    public function testMarketingRequestsAndFailures(): void {
        $history = [];
        $mailchimp = $this->mockedClient([
            $this->response(200, '{"lists":[],"total_items":0}'),
            $this->response(200, '{"members":[],"total_items":0}'),
            $this->response(200, '{"email_address":"contact@example.com"}'),
            $this->response(200, '{"status":"pending"}'),
            $this->response(200, '{"status":"unsubscribed"}'),
            $this->response(200, '{"status":"unsubscribed"}'),
            $this->response(200, '{"status":"unsubscribed"}'),
            $this->response(204), $this->response(204), $this->response(204),
            $this->response(403, '{"error":"denied"}'),
        ], $history);
        self::assertInstanceOf(Mailer::class, $mailchimp);
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
        $request = function (int $index) use (&$history) { return $history[$index]['request']; };
        $json = fn (int $index) => json_decode((string) $request($index)->getBody(), true);
        $hash = md5('contact@example.com');
        self::assertStringContainsString('count=25', (string) $request(0)->getUri());
        self::assertStringContainsString('offset=50', (string) $request(0)->getUri());
        self::assertStringContainsString('/lists/audience/members', (string) $request(1)->getUri());
        self::assertStringContainsString('offset=20', (string) $request(1)->getUri());
        self::assertStringEndsWith('/members/' . $hash, $request(2)->getUri()->getPath());
        self::assertSame('pending', $json(3)['status']);
        self::assertSame('Test', $json(3)['merge_fields']['FNAME']);
        self::assertSame('PUT', $request(4)->getMethod());
        self::assertSame('pending', $json(4)['status_if_new']);
        self::assertArrayNotHasKey('status', $json(4));
        self::assertSame('PATCH', $request(5)->getMethod());
        self::assertSame('Updated', $json(5)['merge_fields']['FNAME']);
        self::assertSame('unsubscribed', $json(6)['status']);
        self::assertSame('DELETE', $request(7)->getMethod());
        self::assertStringEndsWith('/members/' . $hash, $request(7)->getUri()->getPath());
        self::assertSame(['name' => 'VIP', 'status' => 'active'], $json(8)['tags'][0]);
        self::assertSame('inactive', $json(9)['tags'][0]['status']);
        $this->assertFailure(fn () => $mailchimp->getAudiences(), RuntimeException::class, 'HTTP 403');
        self::assertCount(1, $mailchimp->errors);
        $this->assertFailure(fn () => $mailchimp->getContacts('audience', 0), InvalidArgumentException::class, 'Count');
        $this->assertFailure(fn () => $mailchimp->addTag('audience', 'bad', 'VIP'), InvalidArgumentException::class, 'email');
        self::assertCount(11, $history);
    }

    public function testTransactionalMessagesTemplatesAndFailures(): void {
        $history = [];
        $mailchimp = $this->mockedClient([
            $this->response(200, '[{"email":"to@example.com","status":"sent"}]'),
            $this->response(200, '[{"email":"to@example.com","status":"rejected","reject_reason":"hard-bounce"}]'),
            $this->response(200, '[{"slug":"welcome"}]'),
            $this->response(200, '{"slug":"welcome"}'),
            $this->response(401, '{"name":"Invalid_Key"}'),
            $this->response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"queued"},{"email":"bcc@example.com","status":"scheduled"}]'),
            $this->response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"rejected"}]'),
            $this->response(200, '[{"email":"to@example.com","status":"sent"}]'),
            $this->response(200, '[{"email":"to@example.com","status":"sent"}]'),
            $this->response(401, '{"name":"Invalid_Key"}'),
            $this->response(200, '[{"email":"to@example.com","status":"sent"},{"email":"cc@example.com","status":"sent"},{"email":"bcc@example.com","status":"queued"}]'),
            $this->response(200, '[{"email":"other@example.com","status":"sent"}]'),
            $this->response(200, '[{"email":"to@example.com","status":"unknown"}]'),
        ], $history);
        self::assertTrue($mailchimp->sendMail(['to@example.com'], 'Subject', 'Hello'));
        self::assertFalse($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, ['main' => 'Hello'], ['FIRST' => 'Alex']));
        self::assertCount(1, $mailchimp->errors);
        $mailchimp->getTemplates('welcome');
        $mailchimp->getTemplate('welcome');
        $request = function (int $index) use (&$history) { return $history[$index]['request']; };
        $json = fn (int $index) => json_decode((string) $request($index)->getBody(), true);
        self::assertSame('/api/1.3/messages/send', $request(0)->getUri()->getPath());
        self::assertSame('Hello', $json(0)['message']['text']);
        self::assertSame('sender@example.com', $json(0)['message']['from_email']);
        self::assertSame('Example Sender', $json(0)['message']['from_name']);
        self::assertSame('/api/1.3/messages/send-template', $request(1)->getUri()->getPath());
        self::assertSame(['name' => 'main', 'content' => 'Hello'], $json(1)['template_content'][0]);
        self::assertSame(['name' => 'FIRST', 'content' => 'Alex'], $json(1)['message']['global_merge_vars'][0]);
        self::assertArrayNotHasKey('subject', $json(1)['message']);
        self::assertSame('welcome', $json(2)['label']);
        self::assertSame('welcome', $json(3)['name']);
        $this->assertFailure(fn () => $mailchimp->getTemplates(), RuntimeException::class, 'HTTP 401');
        self::assertCount(2, $mailchimp->errors);
        self::assertFalse($mailchimp->sendMailWithTemplate('', ['to@example.com']));
        self::assertCount(5, $history);
        self::assertTrue($mailchimp->sendMail(['to@example.com'], 'HTML subject', '<p>Hello</p>', ['cc@example.com'], ['bcc@example.com'], true));
        self::assertSame('<p>Hello</p>', $json(5)['message']['html']);
        self::assertArrayNotHasKey('text', $json(5)['message']);
        self::assertSame([
            ['email' => 'to@example.com', 'type' => 'to'],
            ['email' => 'cc@example.com', 'type' => 'cc'],
            ['email' => 'bcc@example.com', 'type' => 'bcc'],
        ], $json(5)['message']['to']);
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', ['cc@example.com']));
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', ['cc@example.com']));
        self::assertTrue($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], 'Custom subject'));
        self::assertSame('Custom subject', $json(8)['message']['subject']);
        self::assertSame([], $json(8)['template_content']);
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'));
        self::assertSame('Mailchimp transactional send failed', end($mailchimp->errors)[0]);
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body', [], [new stdClass()]));
        self::assertFalse($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [0 => 'bad']));
        self::assertFalse($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [], ['X' => []]));
        WordPressState::$options['mailchimp_test']['transactional_from_email_default'] = 'bad';
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'));
        self::assertCount(10, $history);
        WordPressState::$options['mailchimp_test']['transactional_from_email_default'] = 'sender@example.com';
        unset(WordPressState::$options['mailchimp_test']['transactional_from_name_default']);
        self::assertTrue($mailchimp->sendMailWithTemplate('welcome', ['to@example.com'], null, [], [], ['cc@example.com'], ['bcc@example.com']));
        self::assertSame($json(5)['message']['to'], $json(10)['message']['to']);
        self::assertArrayNotHasKey('from_name', $json(10)['message']);
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'));
        self::assertFalse($mailchimp->sendMail(['to@example.com'], 'Subject', 'Body'));
    }

    public function testSeparateApiKeysAndReadinessAreEnforced(): void {
        $configured = (new ReflectionClass(MailchimpClientDouble::class))->newInstanceWithoutConstructor();
        $this->initialise($configured);
        (new ReflectionProperty(Integration::class, 'enabled'))->setValue($configured, true);
        WordPressState::$options['mailchimp_test']['marketing_api_key_default'] = 'fake-key-us42';
        $client = $configured->makeMarketingClient();
        self::assertTrue($configured->ready());
        self::assertTrue($configured->ready('marketing'));
        self::assertTrue($configured->ready('transactional'));
        self::assertSame('https://us42.api.mailchimp.com/3.0', $client->getHost());
        self::assertSame('fake-key-us42', $client->getPassword());
        self::assertSame('fake-transactional', $configured->makeTransactionalClient()->getApiKey());
        unset(WordPressState::$options['mailchimp_test']['transactional_api_key_default']);
        self::assertTrue($configured->ready());
        self::assertTrue($configured->ready('marketing'));
        self::assertFalse($configured->ready('transactional'));
        $this->assertFailure(fn () => $configured->getTemplates(), RuntimeException::class, 'transactional api key is not configured');
        self::assertSame('Missing Mailchimp API key', end($configured->errors)[0]);
        self::assertSame('fake-key-us42', $configured->makeMarketingClient()->getPassword());
        WordPressState::$options['mailchimp_test']['transactional_api_key_default'] = 'fake-transactional';
        unset(WordPressState::$options['mailchimp_test']['marketing_api_key_default']);
        self::assertTrue($configured->ready());
        self::assertFalse($configured->ready('marketing'));
        self::assertTrue($configured->ready('transactional'));
        $this->assertFailure(fn () => $configured->ready('unknown'), InvalidArgumentException::class, 'Unknown Mailchimp capability');
        $this->assertFailure(fn () => $configured->getAudiences(), RuntimeException::class, 'marketing api key is not configured');
        self::assertSame('Missing Mailchimp API key', end($configured->errors)[0]);
        self::assertSame('fake-transactional', $configured->makeTransactionalClient()->getApiKey());
        WordPressState::$options['mailchimp_test']['marketing_api_key_default'] = '   ';
        $this->assertFailure(fn () => $configured->getAudiences(), RuntimeException::class, 'not configured');
        self::assertSame('Missing Mailchimp API key', end($configured->errors)[0]);
        WordPressState::$options['mailchimp_test']['marketing_api_key_default'] = 'missingsuffix';
        self::assertFalse($configured->ready('marketing'));
        $this->assertFailure(fn () => $configured->makeMarketingClient(), RuntimeException::class, 'server suffix');
        self::assertSame('Invalid Mailchimp configuration', end($configured->errors)[0]);
        WordPressState::$options['mailchimp_test']['test_mailchimp_enabled'] = false;
        self::assertFalse($configured->ready());
        $this->assertFailure(fn () => $configured->getAudiences(), RuntimeException::class, 'not enabled');
        self::assertSame('Mailchimp integration disabled', end($configured->errors)[0]);
        self::assertFalse($configured->sendMail(['to@example.com'], 'Subject', 'Body'));
        self::assertSame('Mailchimp integration disabled', $configured->errors[count($configured->errors) - 2][0]);
        WordPressState::$options['mailchimp_test']['test_mailchimp_enabled'] = true;
        unset(WordPressState::$options['mailchimp_test']['marketing_api_key_default'], WordPressState::$options['mailchimp_test']['transactional_api_key_default']);
        self::assertFalse($configured->ready());
        self::assertFalse($configured->ready('marketing'));
        self::assertFalse($configured->ready('transactional'));
        $history = [];
        $mailchimp = $this->mockedClient([], $history);
        $this->assertFailure(fn () => $mailchimp->getAudiences(), RuntimeException::class, 'marketing api key is not configured');
        self::assertSame([], $history);
    }
}

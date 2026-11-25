<?php

namespace MM\Meros\EmailConnectors\Tests\Unit;

use MM\Meros\Contracts\Features\Admin\SettingsContainer;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\EmailConnectors\Tests\Support\MailTestHttpClient;
use MM\Meros\EmailConnectors\Tests\Support\MailTestMicrosoft365;
use MM\Meros\EmailConnectors\Tests\Support\MailTestSharedMailbox;
use MM\Meros\EmailConnectors\Tests\Support\SharedMailboxTestSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Microsoft365MailTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__) . '/Support/Microsoft365Mail.php';
    }

    private function initialiseMailer(Integration $mailer, SettingsContainer $settings): void {
        (new ReflectionProperty(Integration::class, 'settings'))->setValue($mailer, $settings);
        (new ReflectionProperty(Integration::class, 'merosSettings'))->setValue($mailer, $settings);
        (new ReflectionMethod(Integration::class, 'identifier'))->invoke($mailer, 'name', 'snake');
        (new ReflectionProperty(Integration::class, 'name'))->setValue($mailer, 'test_mail');
        (new ReflectionProperty(Integration::class, 'enabled'))->setValue($mailer, true);
    }

    private function settings(): SharedMailboxTestSettings {
        $settings = (new ReflectionClass(SharedMailboxTestSettings::class))->newInstanceWithoutConstructor();
        $settings->initialiseForTest();
        return $settings;
    }

    private function mailer(array $statuses): MailTestMicrosoft365 {
        $mailer = (new ReflectionClass(MailTestMicrosoft365::class))->newInstanceWithoutConstructor();
        $this->initialiseMailer($mailer, $this->settings());
        $GLOBALS['options']['shared_mail_test'] = ['test_mail_enabled' => true];
        $mailer->client = new MailTestHttpClient();
        $mailer->client->statuses = $statuses;
        return $mailer;
    }

    private function shared(): MailTestSharedMailbox {
        $shared = (new ReflectionClass(MailTestSharedMailbox::class))->newInstanceWithoutConstructor();
        $this->initialiseMailer($shared, $this->settings());
        $shared->client = new MailTestHttpClient();
        $GLOBALS['options']['shared_mail_test'] = ['test_mail_enabled' => true, 'shared_mailbox_default' => 'helpdesk+team@example.com'];
        return $shared;
    }

    public function testPersonalMailRetryPreservesRecipientsAndUsesRefreshedToken(): void {
        $mailer = $this->mailer([401, 202]);
        self::assertTrue($mailer->sendMail(['first@example.com'], 'Subject', '<p>Hello</p>', ['a' => 'cc1@example.com', 'b' => 'cc2@example.com'], ['bcc@example.com'], true));
        [$first, $retry] = $mailer->client->requests;
        self::assertSame([['emailAddress' => ['address' => 'first@example.com']]], $first['payload']['message']['toRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'cc1@example.com']], ['emailAddress' => ['address' => 'cc2@example.com']]], $first['payload']['message']['ccRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'bcc@example.com']]], $first['payload']['message']['bccRecipients']);
        self::assertSame('HTML', $first['payload']['message']['body']['contentType']);
        self::assertSame($first['payload'], $retry['payload']);
        self::assertSame('Bearer refreshed-token', $retry['headers']['Authorization']);
    }

    public function testPersonalMailDefaultsAndReadiness(): void {
        $mailer = $this->mailer([202]);
        self::assertTrue($mailer->sendMail(['first@example.com'], 'Subject', 'Text'));
        $message = $mailer->client->requests[0]['payload']['message'];
        self::assertArrayNotHasKey('ccRecipients', $message);
        self::assertArrayNotHasKey('bccRecipients', $message);
        self::assertArrayNotHasKey('from', $message);
        self::assertSame('Text', $message['body']['contentType']);
        self::assertSame('https://graph.microsoft.com/v1.0/me/sendMail', $mailer->client->requests[0]['url']);
        $mailer = $this->mailer([]);
        $mailer->token = null;
        self::assertFalse($mailer->ready());
        self::assertFalse($mailer->sendMail(['first@example.com'], 'Subject', 'Text'));
        self::assertSame([], $mailer->client->requests);
        self::assertNotEmpty($mailer->errors);
        $mailer->token = 'original-token';
        $GLOBALS['options']['shared_mail_test']['test_mail_enabled'] = false;
        self::assertFalse($mailer->ready());
        self::assertFalse($mailer->sendMail(['first@example.com'], 'Subject', 'Text'));
    }

    public function testInvalidOptionalRecipientsNeverSend(): void {
        foreach ([[['bad-address'], [], 'ccRecipients'], [[], [new stdClass()], 'bccRecipients']] as [$cc, $bcc, $field]) {
            $mailer = $this->mailer([]);
            self::assertFalse($mailer->sendMail(['first@example.com'], 'Subject', 'Text', $cc, $bcc));
            self::assertSame([], $mailer->client->requests);
            self::assertStringContainsString($field, $mailer->errors[0][1]);
        }
    }

    public function testSharedMailboxRetryAndPermissionFailure(): void {
        $shared = $this->shared();
        $shared->client->statuses = [401, 202];
        self::assertTrue($shared->ready());
        self::assertContains('shared_mailbox', $shared->requiredSettings());
        self::assertSame('https://graph.microsoft.com/Mail.Send.Shared offline_access', $shared->authorizationParams()['scope']);
        self::assertTrue($shared->sendMail(['to@example.com'], 'Shared subject', 'Body', ['cc@example.com'], ['bcc@example.com']));
        [$first, $retry] = $shared->client->requests;
        self::assertSame('https://graph.microsoft.com/v1.0/users/helpdesk%2Bteam%40example.com/sendMail', $first['url']);
        self::assertSame(['emailAddress' => ['address' => 'helpdesk+team@example.com']], $first['payload']['message']['from']);
        self::assertSame('cc@example.com', $first['payload']['message']['ccRecipients'][0]['emailAddress']['address']);
        self::assertSame('bcc@example.com', $first['payload']['message']['bccRecipients'][0]['emailAddress']['address']);
        self::assertSame($first['url'], $retry['url']);
        self::assertSame($first['payload'], $retry['payload']);
        $shared->client->requests = [];
        $shared->client->statuses = [403];
        self::assertFalse($shared->sendMail(['to@example.com'], 'Subject', 'Body'));
        self::assertCount(1, $shared->client->requests);
        self::assertSame($first['url'], $shared->client->requests[0]['url']);
        self::assertSame('Message rejected', end($shared->errors)[0]);
    }

    public function testInvalidSharedMailboxCannotFallBackToPersonalMailbox(): void {
        $shared = $this->shared();
        foreach (['', 'not-an-email'] as $invalidAddress) {
            $GLOBALS['options']['shared_mail_test']['shared_mailbox_default'] = $invalidAddress;
            self::assertFalse($shared->ready());
            $shared->client->requests = [];
            self::assertFalse($shared->sendMail(['to@example.com'], 'Subject', 'Body'));
            self::assertSame([], $shared->client->requests);
            self::assertSame('Integration not ready', end($shared->errors)[0]);
        }
    }
}

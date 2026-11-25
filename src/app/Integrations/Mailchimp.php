<?php

namespace MM\Meros\EmailConnectors\App\Integrations;

use GuzzleHttp\Exception\GuzzleException;
use MailchimpMarketing\ApiClient as MarketingClient;
use MailchimpMarketing\ApiException as MarketingException;
use MailchimpTransactional\ApiClient as TransactionalClient;
use MM\Meros\Contracts\Features\Admin\Setting;
use MM\Meros\Contracts\Features\Integrations\Integration;
use MM\Meros\Contracts\Features\Integrations\Interfaces\Mailer;

class Mailchimp extends Integration implements Mailer {
    protected string $category = 'mail';

    /**
     * Configure the integration's label and setup instructions.
     */
    protected function configure(): void {
        $this->label('Mailchimp');
        $this->description('Manage Mailchimp audiences and send transactional email.');
        $this->instructions('Configure the Marketing and Transactional API keys for the services you use.');
    }

    /**
     * Register separate encrypted API keys for Marketing and Transactional.
     */
    protected function initCustomSettings(): void {
        foreach ([
            'marketing_api_key' => ['Marketing API Key', 'The Mailchimp Marketing API key, including its server suffix.'],
            'transactional_api_key' => ['Transactional API Key', 'The separate Mailchimp Transactional API key.'],
        ] as $name => [$label, $description]) {
            $this->settings()->add('string', function (Setting $setting) use ($name, $label, $description) {
                $setting->name($name);
                $setting->label($label);
                $setting->description($description);
                $setting->encrypt();
                $setting->field('password');
            });
        }

        $this->settings()->add('string', function (Setting $setting) {
            $setting->name('transactional_from_email');
            $setting->label('Transactional Sender Email');
            $setting->description('Sender email address on a verified Mailchimp Transactional domain.');
            $setting->field('email');
        });

        $this->settings()->add('string', function (Setting $setting) {
            $setting->name('transactional_from_name');
            $setting->label('Transactional Sender Name');
            $setting->description('Optional sender display name for transactional email.');
            $setting->field('text');
        });
    }

    protected function isReady(?string $capability): bool {
        if ($capability !== null && !in_array($capability, ['marketing', 'transactional'], true)) {
            throw new \InvalidArgumentException("Unknown Mailchimp capability '{$capability}'.");
        }

        if ($capability === null) {
            return $this->isReady('marketing') || $this->isReady('transactional');
        }

        $key = $this->settings($capability . '_api_key', true);
        return is_string($key) && trim($key) !== ''
            && ($capability !== 'marketing' || preg_match('/^.+-([a-z0-9]+)$/i', trim($key)) === 1);
    }

    /**
     * Marketing API: retrieve one page of audiences.
     *
     * @param int $count Page size, from 1 to 1000.
     * @param int $offset Number of audiences to skip.
     * @return object Decoded response, including lists and total_items.
     */
    public function getAudiences(int $count = 100, int $offset = 0): object {
        $this->validatePage($count, $offset);
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->getAllLists(null, null, $count, $offset));
    }

    /**
     * Marketing API: retrieve one page of contacts in an audience.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param int $count Page size, from 1 to 1000.
     * @param int $offset Number of contacts to skip.
     * @return object Decoded response, including members and total_items.
     */
    public function getContacts(string $audienceId, int $count = 100, int $offset = 0): object {
        $this->validateAudience($audienceId);
        $this->validatePage($count, $offset);
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->getListMembersInfo($audienceId, null, null, $count, $offset));
    }

    /**
     * Marketing API: retrieve an audience contact by email address.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @return object Decoded contact response.
     */
    public function getContact(string $audienceId, string $email): object {
        $this->validateAudience($audienceId);
        $hash = $this->subscriberHash($email);
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->getListMember($audienceId, $hash));
    }

    /**
     * Marketing API: create a contact in an audience.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @param array $data Additional Mailchimp member fields, such as merge_fields.
     * @param string $status Subscription status; defaults to pending confirmation.
     * @return object Decoded contact response.
     */
    public function createContact(string $audienceId, string $email, array $data = [], string $status = 'pending'): object {
        $this->validateAudience($audienceId);
        $this->subscriberHash($email);
        $this->validateStatus($status);
        $data['email_address'] = $email;
        $data['status'] = $status;
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->addListMember($audienceId, $data));
    }

    /**
     * Marketing API: update the supplied fields on an existing audience contact.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Current contact email address.
     * @param array $data Mailchimp member fields to update.
     * @return object Decoded contact response.
     */
    public function updateContact(string $audienceId, string $email, array $data): object {
        $this->validateAudience($audienceId);
        $hash = $this->subscriberHash($email);
        if ($data === []) {
            throw new \InvalidArgumentException('Contact update data must not be empty.');
        }
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->updateListMember($audienceId, $hash, $data));
    }

    /**
     * Marketing API: create or update an audience contact without changing an existing subscription status.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @param array $data Additional Mailchimp member fields; status is ignored.
     * @param string $statusIfNew Subscription status for a new contact; defaults to pending.
     * @return object Decoded contact response.
     */
    public function addContactToAudience(string $audienceId, string $email, array $data = [], string $statusIfNew = 'pending'): object {
        $this->validateAudience($audienceId);
        $hash = $this->subscriberHash($email);
        $this->validateStatus($statusIfNew);
        unset($data['status']);
        $data['email_address'] = $email;
        $data['status_if_new'] = $statusIfNew;
        return $this->marketingRequest(fn (MarketingClient $client) => $client->lists->setListMember($audienceId, $hash, $data));
    }

    /**
     * Marketing API: unsubscribe a contact while retaining it in the audience.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @return object Decoded contact response.
     */
    public function unsubscribeContact(string $audienceId, string $email): object {
        return $this->updateContact($audienceId, $email, ['status' => 'unsubscribed']);
    }

    /**
     * Marketing API: archive a contact from an audience without permanently deleting it.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     */
    public function removeContactFromAudience(string $audienceId, string $email): void {
        $this->validateAudience($audienceId);
        $hash = $this->subscriberHash($email);
        $this->marketingRequest(function (MarketingClient $client) use ($audienceId, $hash) {
            $client->lists->deleteListMember($audienceId, $hash);
            return null;
        }, false);
    }

    /**
     * Marketing API: add a tag to an audience contact.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @param string $tag Tag name.
     */
    public function addTag(string $audienceId, string $email, string $tag): void {
        $this->changeTag($audienceId, $email, $tag, 'active');
    }

    /**
     * Marketing API: remove a tag from an audience contact.
     *
     * @param string $audienceId Mailchimp audience ID.
     * @param string $email Contact email address.
     * @param string $tag Tag name.
     */
    public function removeTag(string $audienceId, string $email, string $tag): void {
        $this->changeTag($audienceId, $email, $tag, 'inactive');
    }

    /**
     * Transactional API: submit a text or HTML message using the configured sender.
     *
     * @param array<string> $to
     * @param array<string> $cc
     * @param array<string> $bcc
     * @return bool True if all recipients were accepted; this does not guarantee delivery.
     */
    public function sendMail(array $to, string $subject, string $body, array $cc = [], array $bcc = [], bool $html = false): bool {
        $message = $this->mailMessage($to, $cc, $bcc);
        if ($message === null) {
            return false;
        }

        $message['subject'] = $subject;
        $message[$html ? 'html' : 'text'] = $body;

        return $this->sendTransactional(fn (TransactionalClient $client) => $client->messages->send(['message' => $message]), $message['to']);
    }

    /**
     * Transactional API: submit a message using a published Transactional template.
     *
     * @param string $templateSlug Immutable Transactional template slug.
     * @param array<string> $to Recipient email addresses.
     * @param string|null $subject Optional override of the template subject.
     * @param array<string, string> $templateContent Editable block names and content.
     * @param array<string, mixed> $mergeVars Global merge variable names and values.
     * @param array<string> $cc
     * @param array<string> $bcc
     * @return bool True if all recipients were accepted; this does not guarantee delivery.
     */
    public function sendMailWithTemplate(
        string $templateSlug,
        array $to,
        ?string $subject = null,
        array $templateContent = [],
        array $mergeVars = [],
        array $cc = [],
        array $bcc = []
    ): bool {
        if (trim($templateSlug) === '') {
            $this->logError('Invalid template', 'A transactional template slug is required.');
            return false;
        }

        $message = $this->mailMessage($to, $cc, $bcc);
        if ($message === null) {
            return false;
        }

        $blocks = [];
        foreach ($templateContent as $name => $content) {
            if (!is_string($name) || trim($name) === '' || !is_string($content)) {
                $this->logError('Invalid template content', 'Template content must map block names to strings.');
                return false;
            }
            $blocks[] = ['name' => $name, 'content' => $content];
        }

        if ($subject !== null) {
            $message['subject'] = $subject;
        }
        foreach ($mergeVars as $name => $value) {
            if (!is_string($name) || trim($name) === '' || (!is_scalar($value) && $value !== null)) {
                $this->logError('Invalid template variables', 'Merge variables must map names to scalar values or null.');
                return false;
            }
            $message['global_merge_vars'][] = ['name' => $name, 'content' => $value];
        }

        return $this->sendTransactional(fn (TransactionalClient $client) => $client->messages->sendTemplate([
            'template_name' => $templateSlug,
            'template_content' => $blocks,
            'message' => $message,
        ]), $message['to']);
    }

    /**
     * Transactional API: list Transactional templates, optionally filtered by label.
     *
     * @param string|null $label Optional template label.
     * @return array Decoded Transactional template objects.
     */
    public function getTemplates(?string $label = null): array {
        $response = $this->transactionalRequest(fn (TransactionalClient $client) => $client->templates->list($label === null ? [] : ['label' => $label]));
        if (!is_array($response)) {
            throw new \RuntimeException('Unexpected Mailchimp Transactional templates response.');
        }
        return $response;
    }

    /**
     * Transactional API: retrieve a Transactional template by its immutable slug.
     *
     * @param string $templateSlug Transactional template slug.
     * @return object Decoded template response.
     */
    public function getTemplate(string $templateSlug): object {
        if (trim($templateSlug) === '') {
            throw new \InvalidArgumentException('A transactional template slug is required.');
        }
        $response = $this->transactionalRequest(fn (TransactionalClient $client) => $client->templates->info(['name' => $templateSlug]));
        if (!is_object($response)) {
            throw new \RuntimeException('Unexpected Mailchimp Transactional template response.');
        }
        return $response;
    }

    /**
     * Configure the Marketing SDK using the key's data-center suffix.
     */
    protected function marketingClient(): MarketingClient {
        $key = $this->requireApiKey('marketing_api_key');
        preg_match('/^.+-([a-z0-9]+)$/i', $key, $matches);
        $client = new MarketingClient();
        $client->setConfig(['apiKey' => $key, 'server' => strtolower($matches[1])]);
        return $client;
    }

    /**
     * Configure the Transactional SDK with its separate API key.
     */
    protected function transactionalClient(): TransactionalClient {
        $key = $this->requireApiKey('transactional_api_key');
        $client = new TransactionalClient();
        $client->setApiKey($key);
        return $client;
    }

    /**
     * Require an enabled integration and the API key for the requested service.
     */
    private function requireApiKey(string $name): string {
        $capability = $name === 'marketing_api_key' ? 'marketing' : 'transactional';
        $ready = $this->ready($capability);
        if (!$this->isEnabled()) {
            $message = 'The Mailchimp integration is not enabled.';
            $this->logError('Mailchimp integration disabled', $message);
            throw new \RuntimeException($message);
        }
        $key = $this->settings($name, true);
        if (!is_string($key) || trim($key) === '') {
            $message = 'The Mailchimp ' . str_replace('_', ' ', $name) . ' is not configured.';
            $this->logError('Missing Mailchimp API key', $message);
            throw new \RuntimeException($message);
        }
        if ($ready && ($capability !== 'marketing' || preg_match('/^.+-([a-z0-9]+)$/i', trim($key)) === 1)) {
            return trim($key);
        }
        $message = $capability === 'marketing' && preg_match('/^.+-([a-z0-9]+)$/i', trim($key)) !== 1
            ? 'The Mailchimp Marketing API key must include its server suffix.'
            : 'The Mailchimp configuration changed during the readiness check.';
        $this->logError('Invalid Mailchimp configuration', $message);
        throw new \RuntimeException($message);
    }

    /**
     * Execute a Marketing API request and surface SDK errors.
     */
    private function marketingRequest(callable $request, bool $expectsResponse = true): ?object {
        $this->requireApiKey('marketing_api_key');
        try {
            $response = $request($this->marketingClient());
        } catch (GuzzleException|MarketingException $exception) {
            $this->requestFailed('Marketing', $exception);
        }
        if ($expectsResponse && !is_object($response)) {
            throw new \RuntimeException('Unexpected Mailchimp Marketing response.');
        }
        return $response;
    }

    /**
     * Execute a Transactional API request and surface SDK errors.
     */
    private function transactionalRequest(callable $request): mixed {
        $this->requireApiKey('transactional_api_key');
        try {
            $response = $request($this->transactionalClient());
        } catch (GuzzleException $exception) {
            $this->requestFailed('Transactional', $exception);
        }
        // The Transactional SDK returns HTTP errors rather than throwing them.
        if ($response instanceof GuzzleException) {
            $this->requestFailed('Transactional', $response);
        }
        return $response;
    }

    /**
     * Log and throw an API failure without exposing credentials or response data.
     */
    private function requestFailed(string $service, \Throwable $exception): never {
        $status = $exception instanceof \GuzzleHttp\Exception\RequestException && $exception->hasResponse()
            ? $exception->getResponse()->getStatusCode()
            : null;
        $message = 'Mailchimp ' . $service . ' request failed' . ($status !== null ? ' (HTTP ' . $status . ')' : '') . '.';
        $this->logError('Mailchimp request failed', $message, $status);
        throw new \RuntimeException($message);
    }

    /**
     * Build a Transactional message with the configured sender and typed recipients.
     */
    private function mailMessage(array $to, array $cc, array $bcc): ?array {
        if ($to === []) {
            $this->logError('No recipients', 'At least one recipient is required.');
            return null;
        }

        $recipients = [];
        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc] as $type => $addresses) {
            foreach ($addresses as $address) {
                if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $this->logError('Invalid recipient', 'An email address in ' . $type . ' is not valid.');
                    return null;
                }
                $recipients[] = ['email' => $address, 'type' => $type];
            }
        }

        $sender = $this->settings('transactional_from_email', true);
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            $this->logError('Invalid sender', 'A valid transactional sender email address is required.');
            return null;
        }

        $message = ['from_email' => $sender, 'to' => $recipients];
        $name = $this->settings('transactional_from_name', true);
        if (is_string($name) && trim($name) !== '') {
            $message['from_name'] = $name;
        }
        return $message;
    }

    /**
     * Check that every requested recipient was accepted by the Transactional API.
     */
    private function sendTransactional(callable $request, array $recipients): bool {
        try {
            $response = $this->transactionalRequest($request);
        } catch (\RuntimeException $exception) {
            $this->logError('Mailchimp transactional send failed', $exception->getMessage());
            return false;
        }

        if (!is_array($response) || count($response) !== count($recipients)) {
            $this->logError('Invalid Mailchimp response', 'The transactional response did not contain a result for every recipient.');
            return false;
        }

        $remaining = [];
        foreach ($recipients as $recipient) {
            $email = strtolower($recipient['email']);
            $remaining[$email] = ($remaining[$email] ?? 0) + 1;
        }

        $accepted = true;
        foreach ($response as $recipient) {
            if (!is_object($recipient) || !is_string($recipient->email ?? null) || !is_string($recipient->status ?? null)) {
                $this->logError('Invalid Mailchimp response', 'The transactional recipient response is incomplete.');
                return false;
            }
            $email = strtolower($recipient->email);
            if (($remaining[$email] ?? 0) === 0) {
                $this->logError('Invalid Mailchimp response', 'The transactional response contains an unexpected recipient.');
                return false;
            }
            $remaining[$email]--;
            if (!in_array($recipient->status, ['sent', 'queued', 'scheduled'], true)) {
                $this->logError('Mailchimp recipient rejected', 'Mailchimp did not accept a transactional recipient.');
                $accepted = false;
            }
        }
        return $accepted;
    }

    /**
     * Set a Marketing audience contact's tag to active or inactive.
     */
    private function changeTag(string $audienceId, string $email, string $tag, string $status): void {
        $this->validateAudience($audienceId);
        $hash = $this->subscriberHash($email);
        if (trim($tag) === '') {
            throw new \InvalidArgumentException('A tag name is required.');
        }
        $this->marketingRequest(function (MarketingClient $client) use ($audienceId, $hash, $tag, $status) {
            $client->lists->updateListMemberTags($audienceId, $hash, ['tags' => [['name' => $tag, 'status' => $status]]]);
            return null;
        }, false);
    }

    /**
     * Require a non-empty audience ID.
     */
    private function validateAudience(string $audienceId): void {
        if (trim($audienceId) === '') {
            throw new \InvalidArgumentException('An audience ID is required.');
        }
    }

    /**
     * Validate an email and calculate the Marketing API's subscriber hash.
     */
    private function subscriberHash(string $email): string {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid contact email address is required.');
        }
        return md5(strtolower($email));
    }

    /**
     * Validate the subscription status used when creating a contact.
     */
    private function validateStatus(string $status): void {
        if (!in_array($status, ['pending', 'subscribed', 'unsubscribed', 'transactional'], true)) {
            throw new \InvalidArgumentException('Invalid new contact subscription status.');
        }
    }

    /**
     * Validate Marketing API pagination arguments.
     */
    private function validatePage(int $count, int $offset): void {
        if ($count < 1 || $count > 1000 || $offset < 0) {
            throw new \InvalidArgumentException('Count must be between 1 and 1000 and offset must be non-negative.');
        }
    }
}

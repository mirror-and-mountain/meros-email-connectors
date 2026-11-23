<?php

namespace MM\Meros\EmailConnectors\App\Integrations;

use MM\Meros\Contracts\Features\Admin\Setting;
use MM\Meros\Contracts\Features\Integrations\OAuthIntegration;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientId;
use MM\Meros\Contracts\Features\Integrations\Concerns\UsesClientSecret;

class Microsoft365 extends OAuthIntegration {
    /**
     * The category of the integration.
     *
     * @var string
     */
    protected string $category = 'mail';

    use UsesClientId, UsesClientSecret;

    protected function configure(): void {
        $this->usePKCE();
        $this->useRefreshHeartbeat();

        $this->label('Microsoft 365 Email Connector');
        $this->description('Send email from a connected Microsoft 365 mailbox using Microsoft Graph.');
        $this->instructions(
            "This integration allows you to send email from a connected Microsoft 365 mailbox using Microsoft Graph.
            To work correctly, you need to have a Microsoft Entra app registration with the appropriate permissions. For help on
            setting up your app registration, refer to our <a href=\"https://docs.meros.com/integrations/microsoft-365\" target=\"_blank\">documentation</a>."
        );

        $this->clientIdLabel = 'Application (client) ID';
        $this->clientIdDescription = 'The client ID of your Microsoft Entra app registration.';
        $this->clientSecretLabel = 'Client Secret';
        $this->clientSecretDescription = 'The client secret value of your Microsoft Entra app registration.';
    }

    protected function initCustomSettings(): void {
        $this->settings()->add('string', function (Setting $setting) {
            $setting->name('tenant_id');
            $setting->label('Directory (tenant) ID');
            $setting->description('The tenant ID of your Microsoft Entra organization.');
            $setting->field('text');
        });
    }

    protected function getAuthorizationUrl(): string {
        return $this->identityEndpoint('authorize');
    }

    protected function getAuthorizationParams(): array {
        return array_merge(parent::getAuthorizationParams(), [
            'scope' => 'https://graph.microsoft.com/Mail.Send offline_access',
        ]);
    }

    protected function getTokenRequestEndpoint(): string {
        return $this->identityEndpoint('token');
    }

    protected function requiredConnectionSettings(): array {
        return [...parent::requiredConnectionSettings(), 'tenant_id'];
    }

    protected function parseTokenResponse(array $response): array {
        $parsed = parent::parseTokenResponse($response);

        if (isset($response['expires_in']) && is_numeric($response['expires_in']) && (int) $response['expires_in'] > 0) {
            $parsed['issued_at'] = time();
            $parsed['expires_at'] = $parsed['issued_at'] + (int) $response['expires_in'];
        }

        return $parsed;
    }

    /**
     * Submit a message from the connected mailbox. Acceptance does not guarantee delivery.
     *
     * @param array<string> $to
     */
    public function sendMail(array $to, string $subject, string $body, bool $html = false): void {
        if ($to === []) {
            $this->logError('No recipients', 'At least one recipient is required.');
            return;
        }

        foreach ($to as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->logError('Invalid recipient', 'The email address "' . $address . '" is not valid.');
                return;
            }
        }

        if (!$this->hasActiveConnection()) {
            $this->logError('No active connection', 'Microsoft 365 is not enabled or has no active connection.');
            return;
        }

        $token = $this->getAccessToken();
        if ($token === null || $token === '') {
            $this->logError('No access token', 'No Microsoft 365 access token is available.');
            return;
        }

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => $html ? 'HTML' : 'Text',
                    'content' => $body,
                ],
                'toRecipients' => array_map(
                    fn (string $address) => ['emailAddress' => ['address' => $address]],
                    array_values($to)
                ),
            ],
        ];

        $send = fn (string $accessToken) => $this->httpClient()->send([
            'method' => 'POST',
            'url' => 'https://graph.microsoft.com/v1.0/me/sendMail',
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'format' => 'json',
            'payload' => $payload,
        ]);

        $response = $send($token);
        if ($response->status() === 401) {
            $token = $this->refreshToken();
            if ($token === null || $token === '') {
                $this->logError('Access token refresh failed', 'Microsoft 365 access token refresh failed; the message was not sent.');
                return;
            }
            $response = $send($token);
        }

        if ($response->status() !== 202) {
            $error = $response->json('error.code');
            $detail = is_string($error) && $error !== '' ? ' (' . $error . ')' : '';
            $this->logError('Message rejected', 'Microsoft Graph rejected the message. HTTP ' . $response->status() . $detail . '.');
            return;
        }
    }

    /**
     * Returns the Microsoft identity endpoint URL for the given action.
     *
     * @param string|null $action
     *
     * @return string
     */
    private function identityEndpoint(string $action): ?string {
        $tenantId = $this->settings('tenant_id', true);
        if (!is_string($tenantId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
            $this->logError('Invalid tenant ID', 'A valid Microsoft Entra tenant ID is required.');
            return null;
        }

        return 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/' . $action;
    }
}

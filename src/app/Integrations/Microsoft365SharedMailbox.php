<?php

namespace MM\Meros\EmailConnectors\App\Integrations;

use MM\Meros\Contracts\Features\Admin\Setting;

class Microsoft365SharedMailbox extends Microsoft365 {
    protected function configure(): void {
        parent::configure();

        $this->label('Microsoft 365 Shared Mailbox Connector');
        $this->description('Send email from a shared Microsoft 365 mailbox using Microsoft Graph.');
        $this->instructions(
            'Configure an Entra app with delegated Microsoft Graph Mail.Send.Shared permission. ' .
            'Connect using a user with Full Access and Send As or Send on Behalf permission for the shared mailbox. ' .
            'Register this integration\'s Return URL as a web redirect URI in the app.'
        );
    }

    protected function initCustomSettings(): void {
        parent::initCustomSettings();

        $this->settings()->add('string', function (Setting $setting) {
            $setting->name('shared_mailbox');
            $setting->label('Shared Mailbox Email Address');
            $setting->description('The email address of the shared mailbox to send from.');
            $setting->field('email');
        });
    }

    protected function requiredConnectionSettings(): array {
        return [...parent::requiredConnectionSettings(), 'shared_mailbox'];
    }

    protected function getAuthorizationParams(): array {
        return array_merge(parent::getAuthorizationParams(), [
            'scope' => 'https://graph.microsoft.com/Mail.Send.Shared offline_access',
        ]);
    }

    protected function isReady(?string $capability): bool {
        $address = $this->settings('shared_mailbox', true);
        return parent::isReady($capability)
            && is_string($address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function mailRequest(array $message): ?array {
        $address = $this->settings('shared_mailbox', true);
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->logError('Invalid shared mailbox', 'A valid shared mailbox email address is required.');
            return null;
        }

        $message['from'] = ['emailAddress' => ['address' => $address]];

        return [
            'url' => 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($address) . '/sendMail',
            'payload' => ['message' => $message],
        ];
    }
}

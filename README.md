# Meros Email Connectors

## Microsoft 365 mailbox

The package registers the Microsoft 365 email connector. In Microsoft Entra,
register a web application for the tenant, add the connector's Return URL as a
**web** redirect URI, and grant the delegated Microsoft Graph `Mail.Send`
permission. Admin consent may be required.

Configure the tenant ID, client ID and client secret in Meros, enable the
connector, and connect using the Microsoft 365 account whose mailbox should send
the messages. The connection is shared across Meros users.

Once connected, use the connector through the container or its facade:

```php
use MM\Meros\EmailConnectors\App\Integrations\Microsoft365;

app(Microsoft365::class)->sendMail(
    ['recipient@example.com'],
    'Hello',
    '<p>Message body</p>',
    ['cc@example.com'],
    ['bcc@example.com'],
    true
);
```

The fourth and fifth arguments are optional CC and BCC address arrays; pass
`true` as the sixth argument for an HTML body. The connector does not replace
WordPress mail or support attachments.

## Microsoft 365 shared mailbox

The shared mailbox connector uses a separate delegated OAuth connection from the
personal mailbox connector. Register its Return URL as a **web** redirect URI in
the Microsoft Entra app, and grant the app Microsoft Graph delegated
`Mail.Send.Shared` permission. Admin consent may be required.

Configure the tenant ID, client ID, client secret and shared mailbox email address
in the connector settings. Connect using a user with Exchange **Full Access** and
either **Send As** or **Send on Behalf** permission for the shared mailbox. The
connector sends through the shared mailbox, so Sent Items are saved there by
default; Exchange mailbox settings may alter this behavior.

Use the connector through the container or its facade:

```php
use MM\Meros\EmailConnectors\Facades\Microsoft365SharedMailbox;

Microsoft365SharedMailbox::sendMail(
    ['recipient@example.com'],
    'Subject',
    'Message body',
    ['cc@example.com'],
    ['bcc@example.com']
);
```

The method returns `true` when Graph accepts the message for processing, not
when delivery is confirmed. It returns `false` on a rejected request. This also
applies to the personal mailbox connector.

## Mailchimp

Enable the Mailchimp integration and configure its Marketing API key (including
the data-center suffix, for example `-us1`) and/or its separate Transactional API
key. Configure only the service you need. Keys are stored in encrypted settings.
Transactional email requires an enabled Mailchimp Transactional account and a
verified sending domain. Configure the Transactional Sender Email and optional
Transactional Sender Name in the integration settings.

Contacts belong to an audience. Marketing calls return the SDK's decoded response
objects. Listing methods accept a page size (1–1000) and offset; inspect
`total_items` and request further pages when necessary.

Use the shared integrations facade to check readiness before making requests:

```php
use MM\Meros\Facades\Integrations;

$mailchimp = Integrations::connect('mailchimp');
if ($mailchimp->ready('marketing')) {
    $audiences = $mailchimp->getAudiences();
}
```

`ready()` is true when either API is configured and the integration is enabled.
Use `ready('marketing')` or `ready('transactional')` to check a particular API.
These checks do not verify credentials with Mailchimp. Transactional message
sending additionally requires a valid configured sender email. Requests enforce
their own service-specific readiness even if called through an older facade.
Integration-specific facades are deprecated but remain available for compatibility.

```php
use MM\Meros\EmailConnectors\Facades\Mailchimp;

$audiences = Mailchimp::getAudiences(100, 0);
$contacts = Mailchimp::getContacts('audience-id', 100, 0);
$contact = Mailchimp::getContact('audience-id', 'contact@example.com');

Mailchimp::createContact('audience-id', 'contact@example.com', [
    'merge_fields' => ['FNAME' => 'Alex'],
]);
Mailchimp::updateContact('audience-id', 'contact@example.com', [
    'merge_fields' => ['FNAME' => 'Alex'],
]);
Mailchimp::addContactToAudience('audience-id', 'contact@example.com');
Mailchimp::addTag('audience-id', 'contact@example.com', 'VIP');
Mailchimp::removeTag('audience-id', 'contact@example.com', 'VIP');
Mailchimp::unsubscribeContact('audience-id', 'contact@example.com');
Mailchimp::removeContactFromAudience('audience-id', 'contact@example.com');
```

New contacts default to `pending` (confirmation required). The optional fourth
argument to `createContact` or `addContactToAudience` selects another status;
only use `subscribed` when appropriate consent has been obtained.
`addContactToAudience` creates or updates a member without changing an existing
member's subscription status. `unsubscribeContact` keeps the member in the
audience; `removeContactFromAudience` archives it, rather than permanently
deleting it.

The connector implements the Meros `Mailer` contract. Supply recipient addresses,
subject, body, optional CC and BCC arrays, and whether the body is HTML:

```php
Mailchimp::sendMail(
    ['recipient@example.com'],
    'Hello',
    '<p>Message body</p>',
    ['cc@example.com'],
    ['bcc@example.com'],
    true
);
```

For a published Transactional template, the body comes from the template.
Optionally override its subject, provide editable blocks and global merge
variables, and specify CC and BCC recipients:

```php
$templates = Mailchimp::getTemplates();
$template = Mailchimp::getTemplate('welcome-template');
Mailchimp::sendMailWithTemplate(
    'welcome-template',
    ['recipient@example.com'],
    null, // Use the template's subject.
    ['main' => '<p>Welcome!</p>'],
    ['FIRST_NAME' => 'Alex'],
    ['cc@example.com'],
    ['bcc@example.com']
);
```

Templates here are **Transactional** templates, identified by immutable slug.
Both send methods return `true` only if all recipients are accepted, queued or
scheduled; this does not guarantee delivery. They return `false` and log invalid
input, configuration or API failures, and recipient rejections. A `false` result
can mean that some recipients were already accepted; avoid blindly retrying.
Other methods raise exceptions for invalid input and API failures. Attempts to use
a disabled integration or a service without its API key are logged. See the [Marketing API](https://mailchimp.com/developer/marketing/api/)
and [Transactional API](https://mailchimp.com/developer/transactional/api/) for
available payload fields and subscription requirements.

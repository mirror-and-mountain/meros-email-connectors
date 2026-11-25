# Meros Email Connectors

Microsoft 365 mailbox and Mailchimp integrations for Meros Framework.

## Mailchimp template flow action

When the integration is enabled, the **Send Email with Mailchimp Template**
flow action sends through Mailchimp Transactional. Enter the published
Transactional template's immutable slug, a subject, message body and recipients.
The action checks the template before sending and passes the sanitized,
formatted HTML body as the global `BODY` merge variable to all recipients.

For the Mailchimp merge language, add `*|HTML:BODY|*` where the body should
appear in the template. Configure the Transactional account's merge language
to match the template, and publish it before sending. The action requires a
Transactional API key and a configured sender; a Marketing key alone is not
sufficient. A failed send can mean some recipients were already accepted.

## Documentation

- [Mailbox setup, shared mailboxes and Mailchimp operations](https://github.com/mirror-and-mountain/meros-docs/blob/main/packages/meros-email-connectors/README.md)
- [Package developer testing](https://github.com/mirror-and-mountain/meros-docs/blob/main/framework/testing/package-developers.md)

Detailed documentation is maintained in [meros-docs](https://github.com/mirror-and-mountain/meros-docs).

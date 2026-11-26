<?php

return [
    'meros_email_connectors_mailchimp' => [
        'force_enabled'              => false,
        'allow_configuration'        => true,
        'marketing_api_key'          => env('MAILCHIMP_MARKETING_API_KEY', ''),
        'transactional_api_key'      => env('MAILCHIMP_TRANSACTIONAL_API_KEY', ''),
        'transactional_from_email'   => env('MAILCHIMP_TRANSACTIONAL_SENDER_EMAIL', ''),
        'transactional_from_name'    => env('MAILCHIMP_TRANSACTIONAL_SENDER_NAME', '')
    ],

    'meros_email_connectors_microsoft365' => [
        'force_enabled'              => false,
        'allow_configuration'        => true,
        'tenant_id'                  => env('MICROSOFT365_TENANT_ID', ''),
        'client_id'                  => env('MICROSOFT365_CLIENT_ID', ''),
        'client_secret'              => env('MICROSOFT365_CLIENT_SECRET', '')
    ],

    'meros_email_connectors_microsoft365_shared_mailbox' => [
        'force_enabled'              => false,
        'allow_configuration'        => true,
        'tenant_id'                  => env('MICROSOFT365_TENANT_ID', ''),
        'client_id'                  => env('MICROSOFT365_CLIENT_ID', ''),
        'client_secret'              => env('MICROSOFT365_CLIENT_SECRET', ''),
        'shared_mailbox'             => env('MICROSOFT365_SHARED_MAILBOX', '')
    ]
];
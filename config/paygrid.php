<?php

return [
    'demo_password' => env('PAYGRID_DEMO_PASSWORD', 'PayGridDemo!2026'),
    'gateway' => [
        'hilogate' => [
            'base_url' => rtrim((string) env('HILOGATE_BASE_URL', 'https://app.hilogate.com/api'), '/'),
            'merchant_id' => env('HILOGATE_MERCHANT_ID'),
            'secret_key' => env('HILOGATE_SECRET_KEY'),
            'environment' => env('HILOGATE_ENVIRONMENT', 'sandbox'),
            'timeout' => (int) env('HILOGATE_TIMEOUT_SECONDS', 4),
            'ca_bundle' => env('HILOGATE_CA_BUNDLE'),
            'resolve_ip' => env('HILOGATE_RESOLVE_IP'),
            'pull_mode' => env('HILOGATE_PULL_MODE', 'qris'),
            'onboarding_email' => env('HILOGATE_ONBOARDING_EMAIL'),
            'onboarding_password' => env('HILOGATE_ONBOARDING_PASSWORD'),
            'transaction_callback_url' => env('HILOGATE_TRANSACTION_CALLBACK_URL'),
            'withdrawal_callback_url' => env('HILOGATE_WITHDRAWAL_CALLBACK_URL'),
        ],
    ],
    'topup' => [
        'minimum_amount' => (int) env('PAYGRID_TOPUP_MINIMUM_AMOUNT', 10000),
        'maximum_amount' => (int) env('PAYGRID_TOPUP_MAXIMUM_AMOUNT', 2000000),
        'expires_in_minutes' => (int) env('PAYGRID_TOPUP_EXPIRES_MINUTES', 30),
        'ticket_grace_minutes' => (int) env('PAYGRID_TOPUP_TICKET_GRACE_MINUTES', 10),
    ],
    'onboarding' => [
        'link_expires_hours' => (int) env('PAYGRID_ONBOARDING_LINK_EXPIRES_HOURS', 24),
    ],
    'gateway_sync' => [
        'enabled' => env('PAYGRID_GATEWAY_SYNC_ENABLED', true),
        'interval_seconds' => (int) env('PAYGRID_GATEWAY_SYNC_INTERVAL_SECONDS', 8),
        'page_size' => (int) env('PAYGRID_GATEWAY_SYNC_PAGE_SIZE', 50),
        'concurrency' => (int) env('PAYGRID_GATEWAY_SYNC_CONCURRENCY', 6),
    ],

    'security' => [
        'server_ip' => env('PAYGRID_SERVER_IP'),
        'callback_trusted_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYGRID_CALLBACK_TRUSTED_IPS', ''))
        ))),
        'internal_api_token' => env('PAYGRID_INTERNAL_API_TOKEN'),
    ],

    'reports' => [
        'default_page_size' => (int) env('PAYGRID_REPORT_PAGE_SIZE', 50),
        'max_page_size' => (int) env('PAYGRID_REPORT_MAX_PAGE_SIZE', 200),
    ],
];

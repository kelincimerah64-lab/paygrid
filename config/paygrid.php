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
            'agent_create_enabled' => env('PAYGRID_HILOGATE_AGENT_CREATE_ENABLED', false),
        ],
        'artageto' => [
            'base_url' => rtrim((string) env('ARTAGETO_BASE_URL', 'https://app.artageto.com/api'), '/'),
            'environment' => env('ARTAGETO_ENVIRONMENT', env('HILOGATE_ENVIRONMENT', 'production')),
            'timeout' => (int) env('ARTAGETO_TIMEOUT_SECONDS', 20),
        ],
    ],
    'topup' => [
        'minimum_amount' => (int) env('PAYGRID_TOPUP_MINIMUM_AMOUNT', 10000),
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
        'max_pages' => (int) env('PAYGRID_GATEWAY_SYNC_MAX_PAGES', 10),
        'backfill_pages_per_run' => (int) env('PAYGRID_GATEWAY_BACKFILL_PAGES_PER_RUN', 3),
        'concurrency' => (int) env('PAYGRID_GATEWAY_SYNC_CONCURRENCY', 6),
        'success_log_retention_hours' => (int) env('PAYGRID_GATEWAY_SUCCESS_LOG_RETENTION_HOURS', 6),
        'failed_log_retention_days' => (int) env('PAYGRID_GATEWAY_FAILED_LOG_RETENTION_DAYS', 14),
        'failed_job_retention_days' => (int) env('PAYGRID_FAILED_JOB_RETENTION_DAYS', 14),
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

    'telegram_bot_monitoring' => [
        'spreadsheet_id' => env('TELEGRAM_BOT_SHEET_ID', '19bXJLfZBarZ4BNnX5K-Dl3ttfsyh7lBIUjUys1i96dg'),
        'sheet_range' => env('TELEGRAM_BOT_SHEET_RANGE', 'A:X'),
        'service_account_email' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_EMAIL'),
        'service_account_private_key' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_PRIVATE_KEY'),
        'cache_ttl_seconds' => (int) env('TELEGRAM_BOT_SHEET_CACHE_TTL', 45),
        'reminder_threshold_minutes' => (int) env('TELEGRAM_BOT_REMINDER_THRESHOLD_MINUTES', 15),
    ],

    'fee_menus' => [
        'ma' => [
            'based' => ['label' => 'Based', 'floor' => 0.80],
            'h_plus_1' => ['label' => 'Based + H+1', 'floor' => 0.80],
            'everyday' => ['label' => 'Based + Everyday', 'floor' => 0.85],
            'same_day' => ['label' => 'Based + Sameday', 'floor' => 0.90],
            'h_plus_1_sc' => ['label' => 'H+1 + Script', 'floor' => 0.85],
            'everyday_sc' => ['label' => 'Everyday + Script', 'floor' => 0.90],
            'same_day_sc' => ['label' => 'Sameday + Script', 'floor' => 0.95],
            'h_plus_1_api' => ['label' => 'H+1 + API', 'floor' => 0.80],
            'everyday_api' => ['label' => 'Everyday + API', 'floor' => 0.85],
            'same_day_api' => ['label' => 'Sameday + API', 'floor' => 0.90],
        ],
        'agent' => [
            'based' => ['label' => 'Based', 'floor' => 0],
            'h_plus_1' => ['label' => 'Based + H+1', 'floor' => 0],
            'everyday' => ['label' => 'Based + Everyday', 'floor' => 0],
            'same_day' => ['label' => 'Based + Sameday', 'floor' => 0],
            'h_plus_1_sc' => ['label' => 'H+1 + Script', 'floor' => 0],
            'everyday_sc' => ['label' => 'Everyday + Script', 'floor' => 0],
            'same_day_sc' => ['label' => 'Sameday + Script', 'floor' => 0],
            'h_plus_1_api' => ['label' => 'H+1 + API', 'floor' => 0],
            'everyday_api' => ['label' => 'Everyday + API', 'floor' => 0],
            'same_day_api' => ['label' => 'Sameday + API', 'floor' => 0],
        ],
        'merchant' => [
            'based' => ['label' => 'Based', 'floor' => 0],
            'h_plus_1' => ['label' => 'Based + H+1', 'floor' => 0],
            'everyday' => ['label' => 'Based + Everyday', 'floor' => 0],
            'same_day' => ['label' => 'Based + Sameday', 'floor' => 0],
            'h_plus_1_sc' => ['label' => 'H+1 + Script', 'floor' => 0],
            'everyday_sc' => ['label' => 'Everyday + Script', 'floor' => 0],
            'same_day_sc' => ['label' => 'Sameday + Script', 'floor' => 0],
            'h_plus_1_api' => ['label' => 'H+1 + API', 'floor' => 0],
            'everyday_api' => ['label' => 'Everyday + API', 'floor' => 0],
            'same_day_api' => ['label' => 'Sameday + API', 'floor' => 0],
        ],
    ],
];

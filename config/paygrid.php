<?php

return [
    'demo_password' => env('PAYGRID_DEMO_PASSWORD', 'PayGridDemo!2026'),
    'demo_host' => env('PAYGRID_DEMO_HOST'),
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
        // White-label of the same Hilogate backend on a different domain (UG
        // Ultimate's reseller instance) - same merchant_id/secret_key and API
        // shape as hilogate, only the host differs, so it's served by
        // HilogateClient with this config block swapped in per-merchant.
        'magnetopay' => [
            'base_url' => rtrim((string) env('MAGNETOPAY_BASE_URL', 'https://app.magnetopay.com/api'), '/'),
            'environment' => env('MAGNETOPAY_ENVIRONMENT', env('HILOGATE_ENVIRONMENT', 'production')),
            'timeout' => (int) env('MAGNETOPAY_TIMEOUT_SECONDS', env('HILOGATE_TIMEOUT_SECONDS', 4)),
            'ca_bundle' => env('MAGNETOPAY_CA_BUNDLE'),
            'resolve_ip' => env('MAGNETOPAY_RESOLVE_IP'),
            'pull_mode' => env('MAGNETOPAY_PULL_MODE', env('HILOGATE_PULL_MODE', 'qris')),
        ],
    ],
    'topup' => [
        'minimum_amount' => (int) env('PAYGRID_TOPUP_MINIMUM_AMOUNT', 10000),
        'expires_in_minutes' => (int) env('PAYGRID_TOPUP_EXPIRES_MINUTES', 30),
        'ticket_grace_minutes' => (int) env('PAYGRID_TOPUP_TICKET_GRACE_MINUTES', 10),
    ],
    'onboarding' => [
        'link_expires_hours' => (int) env('PAYGRID_ONBOARDING_LINK_EXPIRES_HOURS', 720),
    ],
    'gateway_sync' => [
        'enabled' => env('PAYGRID_GATEWAY_SYNC_ENABLED', true),
        'interval_seconds' => (int) env('PAYGRID_GATEWAY_SYNC_INTERVAL_SECONDS', 8),
        'page_size' => (int) env('PAYGRID_GATEWAY_SYNC_PAGE_SIZE', 50),
        'window_hours' => (int) env('PAYGRID_GATEWAY_SYNC_WINDOW_HOURS', 24),
        'max_pages' => (int) env('PAYGRID_GATEWAY_SYNC_MAX_PAGES', 10),
        'backfill_pages_per_run' => (int) env('PAYGRID_GATEWAY_BACKFILL_PAGES_PER_RUN', 3),
        'reconciliation_pages_per_run' => (int) env('PAYGRID_RECONCILIATION_PAGES_PER_RUN', 10),
        'reconciliation_stop_after_pages' => (int) env('PAYGRID_RECONCILIATION_STOP_AFTER_PAGES', 5),
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
        'ticket_bot_token' => env('TELEGRAM_TICKET_BOT_TOKEN'),
        'cs_group_chat_id' => env('TELEGRAM_TICKET_CS_GROUP_CHAT_ID', '-1004445566939'),
    ],

    'jira_sync' => [
        'base_url' => rtrim((string) env('JIRA_BASE_URL', 'https://inacash-team.atlassian.net'), '/'),
        'email' => env('JIRA_EMAIL'),
        'api_token' => env('JIRA_API_TOKEN'),
        'project_key' => env('JIRA_PROJECT_KEY', 'TIC'),
        'issue_type' => env('JIRA_ISSUE_TYPE', 'Task'),
        'webhook_token' => env('JIRA_SYNC_WEBHOOK_TOKEN'),
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

    'assistant' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('PAYGRID_ASSISTANT_MODEL', 'gemini-3.8-flash'),
    ],

    'whatsapp' => [
        'provider' => env('PAYGRID_WHATSAPP_PROVIDER', 'waha'),
        'api_url' => env('PAYGRID_WHATSAPP_API_URL'),
        'api_key' => env('PAYGRID_WHATSAPP_API_KEY'),
        'session' => env('PAYGRID_WHATSAPP_SESSION', 'default'),
    ],

    // Kill switch for the WhatsApp -> Claude Code ops-assistant Q&A bridge
    // (see App\Jobs\AnswerWhatsAppAssistantQuestion). Stays false until the
    // WAHA webhook is deliberately pointed at /api/whatsapp/assistant-webhook
    // AND this is flipped on - two independent switches, so neither alone
    // makes the bot live.
    'whatsapp_assistant_enabled' => (bool) env('PAYGRID_WHATSAPP_ASSISTANT_ENABLED', false),

    // Where the restricted, read-only "ops-assistant" Claude Code project
    // lives on this server (separate repo, see its own CLAUDE.md) and how to
    // invoke it - used by App\Jobs\AnswerWhatsAppAssistantQuestion.
    'ops_assistant_cli' => [
        'working_directory' => env('PAYGRID_OPS_ASSISTANT_DIR', '/home/ec2-user/paygrid-ops-assistant'),
        'path' => env('PAYGRID_OPS_ASSISTANT_PATH', '/home/ec2-user/.npm-global/bin:/usr/local/bin:/usr/bin:/bin'),
    ],

    'telegram_broadcast' => [
        'bot_token' => env('TELEGRAM_BROADCAST_BOT_TOKEN'),
        'groups' => env('TELEGRAM_BROADCAST_GROUPS'),
    ],
];

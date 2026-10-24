<?php

// Telegram support bot ported from the n8n "Merchant Support Bot" workflow
// and its scheduled/WAHA companion workflows. See docs/bot-tele.md.
return [
    'bot_token' => env('BOTTELE_TELEGRAM_BOT_TOKEN'),

    // Must match the secret_token passed to setWebhook; requests without it are rejected.
    'webhook_secret' => env('BOTTELE_TELEGRAM_WEBHOOK_SECRET'),

    // n8n used one group (-1004445566939) for both /checkticket and the tech ticket feed.
    'admin_chat_id' => env('BOTTELE_ADMIN_CHAT_ID'),
    'tech_chat_id' => env('BOTTELE_TECH_CHAT_ID'),
    'ip_approval_chat_id' => env('BOTTELE_IP_APPROVAL_CHAT_ID'),

    // Private chat of the PIC who gets auto-assign escalations (n8n hardcoded one person's id).
    'pic_escalation_chat_id' => env('BOTTELE_PIC_ESCALATION_CHAT_ID'),

    // Every bot timestamp is stored as Asia/Jakarta wall-clock time, like the Google Sheet it replaces.
    'timezone' => 'Asia/Jakarta',

    'attendance_hours' => 8,

    // Optional Jakarta time: the scheduled ticket jobs ignore tickets created before it.
    // Leave empty in production to keep handling every OPEN ticket, as n8n did.
    'automation_since' => env('BOTTELE_AUTOMATION_SINCE'),

    // WhatsApp (WAHA) approval poll for IP Whitelist tickets created on the PayGrid web.
    // Defaults reuse the WAHA server PayGrid already talks to for WA tickets.
    'waha' => [
        // `?:` rather than env()'s default, because a present-but-blank BOTTELE_WAHA_* line must still fall back.
        'base_url' => env('BOTTELE_WAHA_BASE_URL') ?: env('PAYGRID_WHATSAPP_API_URL'),
        'api_key' => env('BOTTELE_WAHA_API_KEY') ?: env('PAYGRID_WHATSAPP_API_KEY'),
        'session' => env('BOTTELE_WAHA_SESSION') ?: env('PAYGRID_WHATSAPP_SESSION') ?: 'default',
        'ip_approval_group_id' => env('BOTTELE_WAHA_IP_APPROVAL_GROUP_ID'),
        // HMAC key configured on the WAHA webhook; incoming events without a valid signature are rejected.
        'webhook_hmac_key' => env('BOTTELE_WAHA_WEBHOOK_HMAC_KEY'),
    ],
];

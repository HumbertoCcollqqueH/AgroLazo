<?php

return [
    'live_enabled' => (bool) env('MESSAGING_LIVE_ENABLED', false),
    'registered_contacts_only' => (bool) env('MESSAGING_REGISTERED_CONTACTS_ONLY', true),
    'simulator_enabled' => (bool) env('MESSAGING_SIMULATOR_ENABLED', true),
    'notice_version' => env('MESSAGING_NOTICE_VERSION', 'local-test-v1'),
    'notice_text' => env('MESSAGING_NOTICE_TEXT', 'Prueba local: usaremos este contacto y tus respuestas para registrar y revisar ofertas agrícolas. No envíes tu DNI por mensajes.'),
    'notice_approved' => (bool) env('MESSAGING_NOTICE_APPROVED', false),
    'draft_ttl_days' => 7,
    'max_sms_segments' => (int) env('SMS_MAX_SEGMENTS', 3),
    'approved_templates' => json_decode(env('WHATSAPP_APPROVED_TEMPLATES', '{}'), true) ?: [],
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'business_account_id' => env('META_BUSINESS_ACCOUNT_ID', env('META_WABA_ID')),
        'public_url' => env('WHATSAPP_PUBLIC_WEBHOOK_URL'),
    ],
    'evolution' => [
        'url' => env('EVOLUTION_URL', env('EVOLUTION_API_URL', 'http://evolution:8080')),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE', env('EVOLUTION_INSTANCE_NAME', 'agroconecta')),
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),
        'callback_url' => env('EVOLUTION_CALLBACK_URL', env('APP_ENV') === 'production' ? rtrim((string) env('APP_URL'), '/').'/api/v1/webhooks/whatsapp/evolution' : 'http://nginx/api/v1/webhooks/whatsapp/evolution'),
    ],
    'twilio' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'two_way_verified' => (bool) env('SMS_TWO_WAY_VERIFIED', false),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
        'inbound_url' => env('TWILIO_INBOUND_URL'),
        'status_url' => env('TWILIO_STATUS_URL'),
    ],
];

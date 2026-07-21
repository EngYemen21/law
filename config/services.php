<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // المساعد القانوني الذكي (Claude API)
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
    ],

    // الخيار الأول: GLM Coding (z.ai) — متوافق مع OpenAI
    'glm' => [
        'key' => env('GLM_API_KEY'),
        'model' => env('GLM_MODEL', 'glm-4.6'),
        'base' => env('GLM_BASE_URL', 'https://api.z.ai/api/paas/v4'),
    ],

    // بديل: Gemini API
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    // الوكيل التشغيلي الذكي للتذاكر (فرز آلي + طلب مستندات + إحالة) — مفتاح تعطيل فوري
    'ai_agent' => [
        'enabled' => env('AI_TICKET_AGENT', true),
    ],

    // Zoom (Server-to-Server OAuth) — اجتماعات الاستشارات المرئية
    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        // الرابط الاحتياطي (placeholder) حين لا تُهيّأ مفاتيح Zoom بعد — مصدر واحد موحّد
        'fallback_base' => env('ZOOM_FALLBACK_BASE', 'https://meet.salasel.sa/'),
        // Meeting SDK (تضمين الاجتماع داخل المنصّة) — تطبيق منفصل عن S2S
        'sdk_key' => env('ZOOM_SDK_KEY'),
        'sdk_secret' => env('ZOOM_SDK_SECRET'),
        // سرّ التحقّق من أحداث Zoom (Event Subscriptions / Webhooks)
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

];

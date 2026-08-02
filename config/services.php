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

    // احتياطي: GLM Coding (z.ai) — متوافق مع OpenAI
    'glm' => [
        'key' => env('GLM_API_KEY'),
        'model' => env('GLM_MODEL', 'glm-4.6'),
        'base' => env('GLM_BASE_URL', 'https://api.z.ai/api/paas/v4'),
    ],

    // الأساسي: Gemini API
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    // الوكيل التشغيلي الذكي للتذاكر (فرز آلي + طلب مستندات + إحالة) — مفتاح تعطيل فوري
    'ai_agent' => [
        'enabled' => env('AI_TICKET_AGENT', true),
    ],

    // مرونة الـAI — مدّة تهدئة المزوّد (بالدقائق) بعد نفاد الحصّة/الازدحام (قاطع الدائرة)
    'ai' => [
        'cooldown' => (int) env('AI_COOLDOWN_MINUTES', 30),
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

    // بوّابة الدفع Moyasar (ميسّر) — نظام الفواتير المستضاف. بلا مفاتيح يبقى الدفع محاكى.
    // وضع test/live يُحدَّد ببادئة المفتاح نفسه (sk_test_/sk_live_) — طابِق المفاتيح مع بيئتك.
    'moyasar' => [
        // خادميّ فقط — لا يُسرَّب في props/الواجهة/الـLogs
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        // آمن للواجهة (Apple Pay/Moyasar.js مستقبلاً)
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        // سرّ التحقّق من إشعارات الويب (secret_token في جسم الحدث)
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
    ],

    // «تقنيات» (taqnyat.sa) — واجهة Verify الرسميّة لرمز التحقّق (OTP): تقنيات تُولّد الرمز وتتحقّق منه.
    // بلا مفاتيح تُمنع المصادقة برسالة «الخدمة غير مهيّأة» (لا محاكاة — نمط بوّابة الدفع).
    'taqnyat' => [
        // خادميّ فقط — لا يُسرَّب في props/الواجهة/الـLogs
        'api_key' => env('TAQNYAT_API_KEY'),
        // اسم المُرسِل المعتمد لدى تقنيات (Sender Name) — إلزاميّ للإرسال
        'sender' => env('TAQNYAT_SENDER'),
        'base_url' => env('TAQNYAT_BASE_URL', 'https://api.taqnyat.sa'),
    ],

    // تجاوز تطويريّ مؤقّت لرمز التحقّق (OTP) عند تعطّل المزوّد: رمز ثابت للدخول/التسجيل.
    // ⚠️ يعمل في غير الإنتاج فقط (يُتجاهَل تماماً حين APP_ENV=production). اتركه فارغاً لإيقافه.
    'auth_dev_otp' => env('AUTH_DEV_OTP'),

    // النظام الخارجيّ للمخاطبات الرسميّة (ناجز/تراسل...) — يعمل محاكاةً بلا مفاتيح
    'external_corr' => [
        'base_url' => env('EXTCORR_BASE_URL'),
        'api_key' => env('EXTCORR_API_KEY'),
        'auth_type' => env('EXTCORR_AUTH_TYPE', 'Bearer'),
        'ref_prefix' => env('EXTCORR_REF_PREFIX', 'EXT'),
        'ep_send' => env('EXTCORR_EP_SEND', '/v1/correspondence/send'),
        'ep_status' => env('EXTCORR_EP_STATUS', '/v1/correspondence/{ref}/status'),
        'ep_reply' => env('EXTCORR_EP_REPLY', '/v1/correspondence/{ref}/reply'),
    ],

];

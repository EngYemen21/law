<?php

use App\Services\Payments\MoyasarGateway;

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

        /*
         * أسعار النماذج لكل مليون توكن، بعملة واحدة يحدّدها المكتب.
         * تُترك فارغة عمداً: الأسعار تتغيّر وتختلف بالمنطقة والعقد، فتثبيتها في
         * الشيفرة يجعل كل تقرير كلفة كاذباً بصمت. وحين لا يُهيَّأ سعرٌ لنموذج
         * تُسجَّل كلفته `null` = «غير معلومة» لا «صفر»، وتُنبِّه اللوحة أن المجموع
         * المعروض جزئيّ (`incomplete_cost_coverage`).
         *
         * مثال بعد اعتماد الأسعار من المزوّد:
         *   'gemini-2.5-flash' => ['input' => 0.075, 'output' => 0.30],
         */
        'pricing' => [],

        /*
         * فصل مهام الذكاء على طوابير مستقلّة بالحساسيّة (P6).
         * مُطفأ افتراضياً: العامل في الإنتاج يستمع لـdefault وحده، وتفعيلُه قبل
         * تحديث أمر العامل يوقف كل معالجة الذكاء **صامتةً** — لا خطأ ولا سجلّ،
         * فقط مهامّ لا تُلتقط. فعِّله بعد ضبط العامل على:
         *   queue:work --queue=ai-low-risk,ai-documents,ai-legal-review,default
         */
        'separate_queues' => (bool) env('AI_SEPARATE_QUEUES', false),

        /*
         * مدد الاحتفاظ بالأيام لكل فئة بيانات (App\Services\Ai\AiDataClass).
         * `null` = بلا حدّ. القيم الافتراضيّة في التعداد نفسه **افتراضات محافظة
         * تنتظر اعتماد المكتب**، لا أرقاماً نهائيّة: مدّة الاحتفاظ بملفّ قانونيّ
         * قرارٌ نظاميّ ومهنيّ لا هندسيّ. ينفّذها `php artisan ai:purge --force`.
         *
         * مثال بعد الاعتماد:
         *   'retention' => ['restricted' => 60, 'confidential' => 365],
         */
        'retention' => [],
    ],

    // Zoom (Server-to-Server OAuth) — اجتماعات الاستشارات المرئية
    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        // الرابط الاحتياطي (placeholder) حين لا تُهيّأ مفاتيح Zoom بعد — مصدر واحد موحّد
        // ميت: صفر مناد في app/ كلّه. يُعلَّق لا يُحذف كي لا يُعاد اختراعه، وكي يعرف
        // من يجد ZOOM_FALLBACK_BASE في .env أنه بلا أثر.
        // 'fallback_base' => env('ZOOM_FALLBACK_BASE', 'https://salaselbabel.net/'),
        // Meeting SDK (تضمين الاجتماع داخل المنصّة) — تطبيق منفصل عن S2S
        'sdk_key' => env('ZOOM_SDK_KEY'),
        'sdk_secret' => env('ZOOM_SDK_SECRET'),
        // سرّ التحقّق من أحداث Zoom (Event Subscriptions / Webhooks)
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

    // بوّابات الدفع المسجّلة (الاسم ← الصنف المطبّق لـApp\Services\Payments\PaymentGateway) والافتراضيّة
    // التي تُنشأ بها روابط الدفع. إضافة بوّابة = صنفٌ + سطرٌ هنا + مفاتيحها في كتلتها أدناه.
    'payments' => [
        'default' => env('PAYMENT_GATEWAY', 'moyasar'),
        'gateways' => [
            'moyasar' => MoyasarGateway::class,
        ],
    ],

    // بوّابة الدفع Moyasar (ميسّر) — نظام الفواتير المستضاف. بلا مفتاح سرّيّ لا دفع (503).
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
        // نقطة إرسال الرسائل النصّية (غير verify.php الخاصّة بالـOTP) — تُضبط بمتغيّر
        // بيئة كي لا يلزم تعديل كود إن غيّرت تقنيات المسار.
        'sms_endpoint' => env('TAQNYAT_SMS_ENDPOINT', '/v1/messages'),
    ],

    // رمز تحقّقٍ ثابت للتجربة بلا مزوّد رسائل: يعمل في صندوق التجربة وحده (local/testing/staging —
    // `AppEnvironment`) ويُتجاهَل في أيّ بيئةٍ أخرى؛ و`php artisan env:check` يكشفه إن ضُبط في الإنتاج.
    'auth_dev_otp' => env('AUTH_DEV_OTP'),

];

<?php

namespace App\Support\Integrations;

/**
 * **سجلّ مفاتيح الخدمات الخارجيّة المعلَن** — ما يُدرج هنا وحده تكتبه الشاشة، فمفتاحٌ خارجه لا يبلغ الإعداد بحال.
 *
 * المفتاح هو **مسار الإعداد الذي تقرؤه الخدمة نفسها** (`services.moyasar.secret_key`): قيمة الشاشة تُطبَّق عليه عند
 * الإقلاع (`IntegrationSecrets::apply`)، فلا تُعدَّل خدمةٌ واحدة، وتبقى `.env` احتياطاً حين تكون فارغة.
 *
 * `secret`: لا يُعرض إلّا مقنّعاً ولا يُرسَل إلى المتصفّح أبداً. غيره (معرّفات، عنوان مرسل) يُعرض كما هو.
 */
final class IntegrationRegistry
{
    /**
     * الخدمات بترتيب البطاقات.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function services(): array
    {
        return [
            'moyasar' => ['label' => 'بوّابة الدفع — ميسّر', 'hint' => 'تُنشأ بها فواتير الدفع وتُجلب الدفعات. مفتاح sk_test_ للتجربة، وsk_live_ للإنتاج وحده.'],
            'zoom' => ['label' => 'الاجتماعات — Zoom', 'hint' => 'تطبيق Server-to-Server OAuth لإنشاء الاجتماعات، وMeeting SDK للانضمام من المنصّة، وسرّ الإشعارات للتسجيلات.'],
            'mail' => ['label' => 'البريد الإلكترونيّ', 'hint' => 'مزوّد الإرسال وعنوان المرسل. log لا يرسل شيئاً (يكتب في السجلّ) — للتجربة وحدها.'],
            'taqnyat' => ['label' => 'الرسائل النصّيّة ورموز الدخول — تقنيات', 'hint' => 'تُرسل بها رموز الدخول (OTP) والتنبيهات النصّيّة.'],
            'ai' => ['label' => 'الذكاء الاصطناعيّ', 'hint' => 'مفاتيح مزوّدي النماذج — يكفي أحدهما.'],
        ];
    }

    /**
     * @return array<string, array{service: string, label: string, env: string, secret: bool, rules: list<string>, options?: list<string>}>
     */
    public static function fields(): array
    {
        return [
            'services.moyasar.secret_key' => ['service' => 'moyasar', 'label' => 'المفتاح السرّيّ', 'env' => 'MOYASAR_SECRET_KEY', 'secret' => true,
                'rules' => ['string', 'max:200', 'starts_with:sk_test_,sk_live_']],
            'services.moyasar.publishable_key' => ['service' => 'moyasar', 'label' => 'مفتاح الواجهة (Publishable)', 'env' => 'MOYASAR_PUBLISHABLE_KEY', 'secret' => true,
                'rules' => ['string', 'max:200', 'starts_with:pk_test_,pk_live_']],
            'services.moyasar.webhook_secret' => ['service' => 'moyasar', 'label' => 'سرّ الإشعارات (Webhook)', 'env' => 'MOYASAR_WEBHOOK_SECRET', 'secret' => true,
                'rules' => ['string', 'min:16', 'max:200']],

            'services.zoom.account_id' => ['service' => 'zoom', 'label' => 'Account ID', 'env' => 'ZOOM_ACCOUNT_ID', 'secret' => false, 'rules' => ['string', 'max:100']],
            'services.zoom.client_id' => ['service' => 'zoom', 'label' => 'Client ID', 'env' => 'ZOOM_CLIENT_ID', 'secret' => false, 'rules' => ['string', 'max:100']],
            'services.zoom.client_secret' => ['service' => 'zoom', 'label' => 'Client Secret', 'env' => 'ZOOM_CLIENT_SECRET', 'secret' => true, 'rules' => ['string', 'max:200']],
            'services.zoom.sdk_key' => ['service' => 'zoom', 'label' => 'Meeting SDK Key', 'env' => 'ZOOM_SDK_KEY', 'secret' => false, 'rules' => ['string', 'max:100']],
            'services.zoom.sdk_secret' => ['service' => 'zoom', 'label' => 'Meeting SDK Secret', 'env' => 'ZOOM_SDK_SECRET', 'secret' => true, 'rules' => ['string', 'max:200']],
            'services.zoom.webhook_secret' => ['service' => 'zoom', 'label' => 'سرّ الإشعارات (Webhook)', 'env' => 'ZOOM_WEBHOOK_SECRET', 'secret' => true, 'rules' => ['string', 'max:200']],

            'mail.default' => ['service' => 'mail', 'label' => 'مزوّد الإرسال', 'env' => 'MAIL_MAILER', 'secret' => false,
                'rules' => ['string', 'in:resend,smtp,log'], 'options' => ['resend', 'smtp', 'log']],
            'services.resend.key' => ['service' => 'mail', 'label' => 'مفتاح Resend', 'env' => 'RESEND_API_KEY', 'secret' => true,
                'rules' => ['string', 'max:200', 'starts_with:re_']],
            'mail.from.address' => ['service' => 'mail', 'label' => 'عنوان المرسل', 'env' => 'MAIL_FROM_ADDRESS', 'secret' => false, 'rules' => ['string', 'email', 'max:150']],
            'mail.from.name' => ['service' => 'mail', 'label' => 'اسم المرسل', 'env' => 'MAIL_FROM_NAME', 'secret' => false, 'rules' => ['string', 'max:100']],

            'services.taqnyat.api_key' => ['service' => 'taqnyat', 'label' => 'مفتاح الواجهة البرمجيّة', 'env' => 'TAQNYAT_API_KEY', 'secret' => true, 'rules' => ['string', 'max:200']],
            'services.taqnyat.sender' => ['service' => 'taqnyat', 'label' => 'اسم المرسل', 'env' => 'TAQNYAT_SENDER', 'secret' => false, 'rules' => ['string', 'max:30']],

            'services.glm.key' => ['service' => 'ai', 'label' => 'مفتاح GLM', 'env' => 'GLM_API_KEY', 'secret' => true, 'rules' => ['string', 'max:300']],
            'services.gemini.key' => ['service' => 'ai', 'label' => 'مفتاح Gemini', 'env' => 'GEMINI_API_KEY', 'secret' => true, 'rules' => ['string', 'max:300']],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }
}

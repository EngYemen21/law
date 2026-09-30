<?php

namespace App\Support;

use App\Services\MoyasarService;
use Illuminate\Support\Str;

/**
 * **فحص إعداد البيئة** (فصل البيئات، المرحلة ١ — 2026-09-29) — ما يكشفه `php artisan env:check`.
 *
 * الميزات الخطرة تُطفأ خارج صندوق التجربة وقت التشغيل (`AppEnvironment`)، لكنّ الإعداد الخاطئ يبقى خطأً
 * يجب أن يُرى: رمزٌ ثابت منسيّ، تصحيحٌ مفتوح، بريدٌ لا يُرسل، مفتاحُ دفعٍ تجريبيّ في الإنتاج أو حقيقيّ في التطوير.
 * `fail` يُفشل الأمر (خروج 1)، و`warn` تنبيهٌ لا يُفشله.
 */
final class EnvironmentAudit
{
    /**
     * @return list<array{level: 'fail'|'warn', key: string, message: string}>
     */
    public static function findings(): array
    {
        $allowed = (array) config('app.env_check_allow', []);
        $findings = AppEnvironment::isSandbox() ? self::sandbox() : self::production();

        // الاستثناء المؤقّت المعلَن (`ENV_CHECK_ALLOW`) يُبقي المخالفة ظاهرةً لكن لا يُفشل الفحص
        return array_map(fn (array $f) => $f['level'] === 'fail' && in_array($f['key'], $allowed, true)
            ? ['level' => 'warn', 'key' => $f['key'], 'message' => $f['message'].' (مستثناة مؤقّتاً بـENV_CHECK_ALLOW)']
            : $f, $findings);
    }

    /** @return list<array{level: 'fail'|'warn', key: string, message: string}> */
    private static function production(): array
    {
        $out = [];
        $fail = function (string $key, string $message) use (&$out): void {
            $out[] = ['level' => 'fail', 'key' => $key, 'message' => $message];
        };

        if (config('app.debug')) {
            $fail('APP_DEBUG', 'التصحيح مفتوح — يكشف تفاصيل الأخطاء والأسرار للزوّار. اجعله false.');
        }
        if (blank(config('app.key'))) {
            $fail('APP_KEY', 'مفتاح التشفير فارغ — php artisan key:generate.');
        }
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! Str::startsWith($url, 'https://') || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $fail('APP_URL', 'عنوان الموقع ليس https على نطاقٍ حقيقيّ — تنكسر الروابط المرسلة وإشعارات الدفع.');
        }
        if (OtpService::isDevOtpConfigured()) {
            $fail('AUTH_DEV_OTP', 'الرمز الثابت مضبوط — مُتجاهَلٌ في الإنتاج، لكن أفرغه كي لا يعمل إن تغيّر اسم البيئة.');
        }
        if (config('app.allow_db_reset')) {
            $fail('ALLOW_DB_RESET', 'تصفير قاعدة البيانات مسموحٌ من لوحة الإدارة — اجعله false.');
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $fail('MAIL_MAILER', 'البريد لا يُرسَل (log/array) — تُكتب الرسائل ورموز البريد في السجلّ بدل أن تصل.');
        }
        if (blank(config('services.taqnyat.api_key'))) {
            $fail('TAQNYAT_API_KEY', 'مفتاح تقنيات فارغ — لا دخول ولا تسجيل في الإنتاج (الرمز الثابت لا يعمل هنا).');
        }

        $secret = (string) config('services.moyasar.secret_key');
        $publishable = (string) config('services.moyasar.publishable_key');
        if (MoyasarService::isTestKey($secret) || MoyasarService::isTestKey($publishable)) {
            $fail('MOYASAR_SECRET_KEY', 'مفتاح ميسّر تجريبيّ (sk_test_/pk_test_) في الإنتاج — لا يُحصَّل مالٌ حقيقيّ.');
        }
        if ($secret !== '' && blank(config('services.moyasar.webhook_secret'))) {
            $fail('MOYASAR_WEBHOOK_SECRET', 'سرّ إشعارات ميسّر فارغ — لا تُسوّى الفواتير المدفوعة آليّاً.');
        }
        if (filled(config('services.zoom.client_id')) && blank(config('services.zoom.webhook_secret'))) {
            $fail('ZOOM_WEBHOOK_SECRET', 'سرّ إشعارات Zoom فارغ — لا تصل أحداث الاجتماعات والتسجيلات.');
        }

        if (in_array('debug', [config('logging.channels.single.level'), config('logging.channels.daily.level')], true)) {
            $out[] = ['level' => 'warn', 'key' => 'LOG_LEVEL', 'message' => 'مستوى السجلّ debug — يكبر السجلّ بسرعة؛ الأنسب warning.'];
        }

        return $out;
    }

    /** @return list<array{level: 'fail'|'warn', key: string, message: string}> */
    private static function sandbox(): array
    {
        $out = [];

        foreach (['secret_key' => 'MOYASAR_SECRET_KEY', 'publishable_key' => 'MOYASAR_PUBLISHABLE_KEY'] as $field => $key) {
            if (MoyasarService::isLiveKey((string) config("services.moyasar.{$field}"))) {
                $out[] = ['level' => 'fail', 'key' => $key, 'message' => 'مفتاح ميسّر حقيقيّ (live) في بيئة تجربة — ضع مفتاح sk_test_/pk_test_. الدفع معطّلٌ هنا حتى ذلك.'];
            }
        }

        return $out;
    }
}

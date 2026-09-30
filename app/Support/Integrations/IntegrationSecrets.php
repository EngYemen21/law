<?php

namespace App\Support\Integrations;

use App\Models\IntegrationSecret;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * **مفاتيح الخدمات الخارجيّة من الشاشة** — التطبيق على الإعداد، والقراءة المقنّعة، والكتابة.
 *
 * الأولويّة (قرار المالك 2026-09-30): قيمة الشاشة متى ضُبطت، وإلّا ما في `.env` — فلا يتعطّل شيءٌ قبل إدخالها،
 * وحذفُها من الشاشة يُعيد الخدمة إلى `.env`.
 */
final class IntegrationSecrets
{
    /** مسارات الإعداد التي طبّقتها الشاشة في هذا الطلب — مصدرُ شارة «الشاشة». */
    private const APPLIED = 'support.integration-secrets.applied';

    /**
     * يطبّق القيم المحفوظة على الإعداد — مرّةً عند الإقلاع (`AppServiceProvider`). الخدمات كلّها تقرأ الإعداد عند
     * النداء، فلا تُمسّ. **لا يُسقط التطبيقَ جدولٌ غائب** (ترحيلٌ لم يجرِ) **ولا قيمةٌ لا تُفكّ** (تغيّر `APP_KEY`):
     * تبقى `.env` في الحالين، ويُسجَّل التحذير.
     */
    public static function apply(): void
    {
        $applied = [];

        try {
            $rows = IntegrationSecret::query()->get();
        } catch (QueryException) {
            app()->instance(self::APPLIED, []);

            return;
        }

        foreach ($rows as $row) {
            if (! IntegrationRegistry::has($row->key)) {
                continue;
            }

            // فكٌّ صريح للقيمة الخامّ (لا عبر الـcast): مفتاح تطبيقٍ تغيّر يرمي هنا فتُتجاوز القيمة وتبقى `.env`
            try {
                $value = Crypt::decryptString((string) $row->getRawOriginal('value'));
            } catch (DecryptException) {
                Log::warning('integrations.decrypt_failed', ['key' => $row->key]);

                continue;
            }

            if ($value !== '') {
                config([$row->key => $value]);
                $applied[] = $row->key;
            }
        }

        app()->instance(self::APPLIED, $applied);
    }

    /**
     * حالة كلّ مفتاح كما تُعرض: مصدره، وقيمته **مقنّعةً للأسرار** — السرّ نفسه لا يغادر الخادم.
     *
     * @return array<string, array{source: 'screen'|'env'|'none', display: string}>
     */
    public static function states(): array
    {
        $applied = app()->bound(self::APPLIED) ? app(self::APPLIED) : [];
        $out = [];

        foreach (IntegrationRegistry::fields() as $key => $field) {
            $value = (string) config($key);
            $out[$key] = [
                'source' => in_array($key, $applied, true) ? 'screen' : ($value !== '' ? 'env' : 'none'),
                'display' => $field['secret'] ? self::mask($value) : $value,
            ];
        }

        return $out;
    }

    /** «••••YymY» — آخر أربعة أحرف تكفي للتمييز ولا تكشف المفتاح؛ والقصير كلّه نقاط. */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strlen($value) <= 8 ? '••••••••' : '••••'.mb_substr($value, -4);
    }

    public static function put(string $key, string $value, User $by): void
    {
        IntegrationSecret::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by->id]);
    }

    public static function forget(string $key): void
    {
        IntegrationSecret::whereKey($key)->delete();
    }
}

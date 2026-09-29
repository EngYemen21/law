<?php

namespace App\Models;

use App\Services\Ai\AiPolicyGate;
use App\Support\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** قراءة قيمة إعداد (مع افتراضي). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? $row->value : $default;
    }

    /** حفظ قيمة إعداد. */
    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** نسبة ضريبة القيمة المضافة (%) من «الإعدادات» (`SettingsRegistry`) — مصدر واحد لكل حسابات الضريبة. */
    public static function vatRate(): int
    {
        return SettingsRegistry::int('vat_rate');
    }

    /** مبلغ الضريبة على أساسٍ ما، بنسبة الإدارة الحالية. */
    public static function vatOn(int|float $base): int
    {
        return (int) round($base * static::vatRate() / 100);
    }

    // ── معايرة الذكاء الاصطناعي (P4) ──
    // الخطة تفرض معايرة العتبات مع الفريق القانونيّ. كانت أرقاماً ثابتة في الشيفرة،
    // فتصير كل معايرة تعديلَ كودٍ ونشراً — أي أن قراراً قانونياً يمرّ عبر مطوّر.
    // مكانها هنا: تُضبط من لوحة التحكّم، والثابت في الصنف يبقى القيمة الافتراضيّة.

    /** عتبة القبول الآليّ للمهام متوسّطة الحساسيّة (0–100). */
    public static function aiAutoAcceptThreshold(): int
    {
        $value = (int) static::get('ai_auto_accept_threshold', AiPolicyGate::DEFAULT_THRESHOLD);

        return max(0, min(100, $value));
    }

    /**
     * أسعار النماذج لكل مليون توكن. مصفوفة فارغة = لا سعر معلوم ⇒ الكلفة `null`
     * وتُعلن اللوحة أن المجموع جزئيّ — لا صفر يوهم بأن النداء مجّانيّ.
     *
     * @return array<string, array{input:float,output:float}>
     */
    public static function aiPricing(): array
    {
        $stored = json_decode((string) static::get('ai_pricing', ''), true);

        return is_array($stored) && $stored !== [] ? $stored : (array) config('services.ai.pricing', []);
    }

    /**
     * مفاتيح تفعيل مسارات الذكاء، لكل معرّف تعليمة.
     *
     * الخطة تفرض «تفعيل مسارات منفردة **بعد تجاوز معيارها**، لا المنظومة كاملة دفعة
     * واحدة». والمفاتيح مقصورة على المسارات التي لها بوّابة تقييم: مسارٌ بلا معيار
     * لا معنى لتفعيله «بعد تجاوزه» شيئاً.
     *
     * **الافتراض: مفعَّل.** غياب المفتاح لا يعني الإطفاء — وإلّا أطفأ النشرُ الأوّل
     * كل شيء صامتاً.
     */
    public static function aiTaskEnabled(string $taskId): bool
    {
        $stored = json_decode((string) static::get('ai_enabled_tasks', ''), true);

        return ! is_array($stored) || ! array_key_exists($taskId, $stored) || (bool) $stored[$taskId];
    }

    /** @return array<string,bool> ما أُطفئ صراحةً فقط */
    public static function aiDisabledTasks(): array
    {
        $stored = json_decode((string) static::get('ai_enabled_tasks', ''), true);

        return array_filter(is_array($stored) ? $stored : [], fn ($on) => ! $on);
    }

    /**
     * ميزانيّة الذكاء الشهريّة.
     *
     * `cap = null` يعني **بلا سقف** لا صفراً: الصفر يمنع كل نداء. و`stop` مُطفأ
     * افتراضياً — تفعيله يوقف معالجة الذكاء عند التجاوز، وهو أثرٌ واسع لا يُفتَرض.
     *
     * @return array{cap:float|null, warnAt:float, stop:bool}
     */
    public static function aiBudget(): array
    {
        $stored = json_decode((string) static::get('ai_budget', ''), true);
        $stored = is_array($stored) ? $stored : [];

        $cap = $stored['cap'] ?? null;

        return [
            'cap' => $cap === null || $cap === '' ? null : (float) $cap,
            // نسبة التنبيه: 80% افتراضاً — تحذيرٌ قبل الوقوع لا بعده
            'warnAt' => max(0.1, min(1.0, (float) ($stored['warnAt'] ?? 0.8))),
            'stop' => (bool) ($stored['stop'] ?? false),
        ];
    }

    /**
     * اعتماد مدد الاحتفاظ: من اعتمدها ومتى وبأيّ سند.
     *
     * كانت المدد تُحفَظ بلا اعتماد، فتُقرأ في الشاشة «افتراض لم يُعتمد» إلى الأبد ولو
     * أقرّها المكتب. **الاعتماد واقعةٌ تُسجَّل** — وبدونها لا يُعرف عند التدقيق من قرّر
     * ولا على أيّ أساس، وهو أوّل ما يُسأل عنه في سياسة احتفاظ.
     *
     * @return array{by:string|null, at:string|null, basis:string|null}
     */
    public static function aiRetentionApproval(): array
    {
        $stored = json_decode((string) static::get('ai_retention_approval', ''), true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'by' => $stored['by'] ?? null,
            'at' => $stored['at'] ?? null,
            'basis' => $stored['basis'] ?? null,
        ];
    }

    /** هل اعتُمدت المدد فعلاً؟ — لا «معتمد» بلا معتمِدٍ وتاريخ. */
    public static function aiRetentionApproved(): bool
    {
        $approval = static::aiRetentionApproval();

        return $approval['by'] !== null && $approval['at'] !== null;
    }

    /**
     * مدد الاحتفاظ بالأيام لكل فئة بيانات. `null` لفئة = بلا حدّ.
     *
     * @return array<string, int|null>
     */
    public static function aiRetention(): array
    {
        $stored = json_decode((string) static::get('ai_retention', ''), true);

        return is_array($stored) && $stored !== [] ? $stored : (array) config('services.ai.retention', []);
    }
}

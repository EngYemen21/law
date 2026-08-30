<?php

namespace App\Models;

use App\Services\Ai\AiPolicyGate;
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

    /** نسبة ضريبة القيمة المضافة (%) كما تضبطها الإدارة — مصدر واحد لكل حسابات الضريبة. */
    public static function vatRate(): int
    {
        return (int) static::get('vat_rate', 15);
    }

    /** مبلغ الضريبة على أساسٍ ما، بنسبة الإدارة الحالية. */
    public static function vatOn(int|float $base): int
    {
        return (int) round($base * static::vatRate() / 100);
    }

    /** أسعار الاستشارات الحالية (office/video/phone) + الضريبة — بافتراضات النظام الأصلية. */
    public static function consultPrices(): array
    {
        return [
            'office' => (int) static::get('price_office', 600),
            'video' => (int) static::get('price_video', 450),
            'phone' => (int) static::get('price_phone', 350),
            'vat' => static::vatRate(),
        ];
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

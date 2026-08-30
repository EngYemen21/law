<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * تصنيف بيانات الذكاء الاصطناعي الأربعة — مطلب المرحلة P5.
 *
 * التصنيف ليس تسمية: هو ما يحكم **مدّة الاحتفاظ**، وهل يُرسَل للمزوّد الخارجيّ،
 * وهل يُسجَّل في السجلّ التشغيليّ. مكتبٌ قانونيّ يعالج هويّات ووثائق وأدلّة، فبقاء
 * كل شيء إلى الأبد بالدرجة نفسها خطرٌ لا حياد.
 *
 * **مدد الاحتفاظ أدناه افتراضات محافظة تحتاج اعتماد المكتب** — لا أرقاماً نهائيّة.
 * تُضبط في `config/services.php` تحت `ai.retention`، ويُنفّذها `ai:purge`.
 */
enum AiDataClass: string
{
    /** لا تخصّ شخصاً: رموز فشل، مؤشّرات، أزمنة. */
    case Public = 'public';

    /** تشغيليّة داخليّة: أنواع المهام، إصدارات التعليمات، النماذج. */
    case Internal = 'internal';

    /** بيانات ملفّ عميل: ملخّصات، تصنيفات، قرارات. */
    case Confidential = 'confidential';

    /** هويّات ومستندات وأدلّة — أعلى درجات الحماية. */
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'عامة',
            self::Internal => 'داخلية',
            self::Confidential => 'سرية',
            self::Restricted => 'حساسة جداً',
        };
    }

    /** أيّام الاحتفاظ الافتراضيّة — تُتجاوَز بالتهيئة، و`null` = بلا حدّ. */
    public function defaultRetentionDays(): ?int
    {
        return match ($this) {
            self::Public => null,        // مؤشّرات مجهّلة: تبقى للقياس التاريخيّ
            self::Internal => 365,
            self::Confidential => 180,
            self::Restricted => 90,      // الأقصر: أعلى خطراً وأقلّ حاجةً للبقاء
        };
    }

    /** مدّة الاحتفاظ الفعليّة بعد التهيئة. */
    public function retentionDays(): ?int
    {
        // الأولويّة: ما ضبطته الإدارة من لوحة التحكّم ← التهيئة ← الافتراض المحافظ.
        // مدّة الاحتفاظ بملفّ قانونيّ قرارٌ نظاميّ، فلا يصحّ أن يلزمه نشرُ كود.
        $stored = Setting::aiRetention();
        if (array_key_exists($this->value, $stored)) {
            return $stored[$this->value] === null ? null : (int) $stored[$this->value];
        }

        $configured = config("services.ai.retention.{$this->value}", 'unset');

        return $configured === 'unset' ? $this->defaultRetentionDays() : $configured;
    }

    /** هل يجوز إرسال هذه الفئة إلى مزوّد خارجيّ؟ */
    public function mayLeaveTheOffice(): bool
    {
        // «الحساسة جداً» لا تُرسَل خاماً؛ تمرّ عبر AiContextBuilder الذي يموّه معرّفاتها
        return $this !== self::Restricted;
    }

    /** هل تُسجَّل قيمتها في السجلّ التشغيليّ؟ */
    public function mayBeLogged(): bool
    {
        return $this === self::Public || $this === self::Internal;
    }

    /** تصنيف حقول `ai_runs` — مرجعٌ واحد لما يُحتفَظ به وما يُمسَح. */
    public static function ofAiRunField(string $field): self
    {
        return match ($field) {
            'task_type', 'source', 'status', 'model', 'prompt_version',
            'duration_ms', 'failure_code', 'input_tokens', 'output_tokens',
            'estimated_cost', 'trace_id' => self::Internal,

            'confidence', 'confidence_signals' => self::Internal,

            // أعدادٌ وحجم لا محتوى — دليلُ تدقيقٍ يبقى بعد تجريد الحقول السرّية
            'outbound_audit' => self::Internal,

            'entity_type', 'entity_id', 'entity_ref',
            'review_note', 'review_reason' => self::Confidential,

            default => self::Confidential,
        };
    }
}

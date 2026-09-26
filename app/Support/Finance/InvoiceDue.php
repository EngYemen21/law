<?php

namespace App\Support\Finance;

use App\Support\ArabicCount;
use App\Support\SettingsRegistry;

/**
 * **مهلة سداد الفاتورة: التاريخ ونصّه معاً** — المصدر الواحد لـ`due_at` و`due_label`.
 *
 * كانت كلّ نقطة إصدارٍ تكتب زوجاً منقوشاً: `'due_label' => 'خلال 3 أيام'` و
 * `'due_at' => now()->addDays(3)`. ستّة مواضع، كلٌّ برقمٍ يتكرّر مرّتين في سطرين متجاورين —
 * فتعديل أحدهما دون الآخر يطبع على الفاتورة مهلةً غيرَ التي يحسبها النظام. وصارت المهل
 * إعداداتٍ في `SettingsRegistry` (مجموعة «الفواتير والسداد»)، فالرقم يُقرأ مرّةً ومنه يُبنى الاثنان.
 *
 * والمهلة تُجمَّد على الفاتورة لحظة إصدارها: تغييرُ الإعداد يسري على ما يصدر بعده وحده.
 */
final class InvoiceDue
{
    /** فاتورة الاستشارة عند تسعيرها. */
    public static function consult(): array
    {
        return self::in(SettingsRegistry::int('invoice_due_days_consult'));
    }

    /** فاتورة أتعاب القضيّة عند اعتمادها دفعةً واحدة. */
    public static function caseFee(): array
    {
        return self::in(SettingsRegistry::int('invoice_due_days_case'));
    }

    /** فاتورة أتعاب التنفيذ عند قبول العرض دفعةً واحدة. */
    public static function execFee(): array
    {
        return self::in(SettingsRegistry::int('invoice_due_days_exec'));
    }

    /** فاتورة النسبة من المحصّل مع كلّ تحصيل. */
    public static function collection(): array
    {
        return self::in(SettingsRegistry::int('invoice_due_days_collection'));
    }

    /**
     * الدفعة رقم `$n` من خطّة تقسيط تُفتح الآن — في القضايا والتنفيذ معاً.
     *
     * **الأولى بمهلتها المستقلّة** (`installment_first_due_days`، قرار المالك 2026-09-26: «اجعل الإدارة
     * تحدّد»): كانت ثلاثة أيّامٍ منقوشة، ثمّ رُبطت بمهلة الفاتورة الأمّ — وكلاهما قرارٌ عن الإدارة.
     * وما بعدها بمضاعفات الفاصل بين الدفعات، محسوبةً من لحظة فتح الخطّة.
     */
    public static function installment(int $n): array
    {
        return self::in($n <= 1
            ? SettingsRegistry::int('installment_first_due_days')
            : ($n - 1) * SettingsRegistry::int('installment_interval_days'));
    }

    /**
     * @return array{due_label:string,due_at:string}
     */
    public static function in(int $days): array
    {
        return [
            'due_label' => 'خلال '.ArabicCount::days($days),
            'due_at' => now()->addDays($days)->toDateString(),
        ];
    }
}

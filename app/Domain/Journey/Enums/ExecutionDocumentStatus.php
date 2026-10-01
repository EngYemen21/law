<?php

namespace App\Domain\Journey\Enums;

/**
 * **حالة مستند التنفيذ** (`execution_documents.status`) — يطلبه المكتب، يرفعه العميل، ثمّ يُقبل أو يُعاد.
 *
 * كان النصّ العربيّ يُكتب ويُقارَن حرفيّاً في النموذج والمتحكّم والخدمة (قاعدة CLAUDE.md)، وشرطا «يقبل الرفع»
 * و«بانتظار المراجعة» منسوخين بين `ExecutionDocument::toData` وحارسَي `ExecFlowController`. القيم هي المخزَّنة
 * نفسها، فلا ترحيل.
 */
enum ExecutionDocumentStatus: string
{
    case Required = 'مطلوب';
    case Uploaded = 'مرفوع';
    case Accepted = 'مقبول';
    case Rejected = 'مرفوض';

    /** يقبل رفع العميل: مطلوبٌ لم يُرفع، أو أعاده المكتب — لا ما اعتمده ولا ما ينتظر مراجعته. */
    public function acceptsUpload(): bool
    {
        return $this === self::Required || $this === self::Rejected;
    }

    /** رُفع وينتظر قبول المكتب أو ردّه. */
    public function awaitsReview(): bool
    {
        return $this === self::Uploaded;
    }

    /** وصل المكتبَ (رُفع أو قُبل) — عدّاد «المستوفى». */
    public function provided(): bool
    {
        return $this === self::Uploaded || $this === self::Accepted;
    }

    /** نغمة الشارة (يطابق exDocStatusTone). */
    public function tone(): string
    {
        return match ($this) {
            self::Accepted => 'b-green',
            self::Uploaded => 'b-blue',
            self::Rejected => 'b-red',
            self::Required => 'b-amber',
        };
    }
}

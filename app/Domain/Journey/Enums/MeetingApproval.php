<?php

namespace App\Domain\Journey\Enums;

/**
 * **حالة اعتماد محضر الاجتماع — مشتقّةٌ لا مخزّنة** (قرار المالك 2026-10-02).
 *
 * كانت الشاشات تعرض نصّ عمود `meetings.approve` كما هو، وقيمته الافتراضيّة عند الإنشاء «بانتظار اعتماد
 * الإدارة» — فيظهر على اجتماعٍ قادم كأنّ عقده ينتظر موافقة، وعلى ملغى لن يُعتمد أبداً (ثبت في
 * `/admin/meetmgmt`). والاعتماد في الحقيقة اعتمادُ **المحضر** بعد الانتهاء (`Meeting::approvalBlocker`):
 * فالحالة تُشتقّ هنا من الانعقاد والمخرجات والاعتماد، و`Meeting::approvalState` يعيد `null` لما لا اعتماد له.
 */
enum MeetingApproval: string
{
    /** انتهى الاجتماع ولم يصل ملخّص Zoom ولم يُدوَّن محضر — لا زرّ اعتماد بعد. */
    case AwaitingMinutes = 'بانتظار المحضر';

    /** انتهى وله مخرجاتٌ حقيقيّة — ينتظر قرار الإدارة (زرّ الاعتماد ظاهر). */
    case AwaitingApproval = 'بانتظار اعتماد المحضر';

    case Approved = 'معتمد';

    /** مفتاحٌ لاتينيّ للواجهة — تشرط به لا بالنصّ المعروض. */
    public function key(): string
    {
        return match ($this) {
            self::AwaitingMinutes => 'awaiting_minutes',
            self::AwaitingApproval => 'awaiting_approval',
            self::Approved => 'approved',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::AwaitingMinutes => 'b-grey',
            self::AwaitingApproval => 'b-amber',
            self::Approved => 'b-green',
        };
    }

    /** @return array{key: string, label: string, tone: string} */
    public function toCard(): array
    {
        return ['key' => $this->key(), 'label' => $this->value, 'tone' => $this->tone()];
    }
}

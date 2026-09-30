<?php

namespace App\Domain\Journey\Enums;

/**
 * **ردّ العميل على عرض أتعاب التنفيذ** (`executions.offer_status`) — الكتالوج الواحد بدل نصوصٍ منقوشة في
 * منطق السداد وفتح الملفّ (تدقيق الدفع 2026-09-30). القيم هي المخزَّنة كما هي.
 */
enum ExecutionOfferStatus: string
{
    case Accepted = 'مقبول';
    case Rejected = 'مرفوض';
    case Inquiry = 'استفسار';
}

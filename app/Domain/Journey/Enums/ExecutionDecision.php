<?php

namespace App\Domain\Journey\Enums;

/**
 * **قرار دراسة طلب التنفيذ** (`executions.decision`) — قبوله أو رفضه بعد الدراسة.
 *
 * كان النصّ العربيّ «مرفوض»/«مقبول» يُكتب ويُقارَن حرفيّاً في الخدمة والانتقالات والمهامّ (قاعدة CLAUDE.md:
 * لا نصوص حالةٍ في المنطق). القيم هي المخزَّنة نفسها، فلا ترحيل.
 */
enum ExecutionDecision: string
{
    case Accepted = 'مقبول';
    case Rejected = 'مرفوض';
}

<?php

namespace App\Enums;

/**
 * **نوع انشغال المحامي في وقتٍ ما** — يجيب عنه `LawyerAvailability::conflictAt`.
 *
 * التفريق لأجل خيار «السماح بحجزٍ متداخل» (`consult_allow_overlap`): الانشغال العاديّ (موعد،
 * استشارة، اجتماع، دعوة) يجوز تجاوزه حين تسمح الإدارة، وجلسة المحكمة لا تُتجاوز أبداً — المحامي
 * خارج المكتب (قرار المالك 2026-09-28).
 */
enum BusyKind
{
    case Free;
    case Busy;
    case Hearing;
}

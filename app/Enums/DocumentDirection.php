<?php

namespace App\Enums;

/**
 * **اتّجاه مستندٍ في «المستندات»** (`documents.direction`) — مصدرٌ واحد لقيمتين.
 *
 * كانت القيمة نصّاً حرّاً: الرفع يكتب `up`، والصفحة تقرأ `out`/`up`، وبيانات التجربة كتبت `in` —
 * فاختفى «صكّ ملكيّة الأرض» من «مستنداتك المرفوعة»، وعرضته الرئيسيّة «صادراً» (جرد التبويبات 2026-10-04).
 */
enum DocumentDirection: string
{
    /** صادرٌ من المكتب إلى العميل */
    case Out = 'out';

    /** رفعه العميل بنفسه */
    case Up = 'up';

    public function label(): string
    {
        return match ($this) {
            self::Out => 'صادر من المكتب',
            self::Up => 'مرفوع من العميل',
        };
    }
}

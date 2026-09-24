<?php

namespace App\Support;

use App\Domain\Journey\Enums\TicketStatus;
use App\Models\Ticket;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * حارس موحّد لكل عملية تغيّر محتوى تذكرة.
 *
 * التذكرة المحوّلة أو المغلقة سجلّ للقراءة والتدقيق فقط. إخفاء المحرر في
 * الواجهة ليس حماية؛ لذلك تستدعيه جميع endpoints التي تكتب رسائل أو مرفقات
 * أو ملخصات أو تغييرات تشغيلية على التذكرة.
 */
final class TicketWritePolicy
{
    /**
     * @throws HttpException
     */
    public static function assertWritable(Ticket $ticket): void
    {
        if ($ticket->is_frozen) {
            throw new HttpException(422, 'لا يمكن تعديل تذكرة مجمدة بقرار نهائي.');
        }

        if (in_array($ticket->status, TicketStatus::finals(), true)) {
            throw new HttpException(422, 'لا يمكن تعديل تذكرة نهائية.');
        }
    }
}

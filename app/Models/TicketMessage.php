<?php

namespace App\Models;

use App\Models\Concerns\RecordsSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use RecordsSender;

    protected $fillable = ['ticket_id', 'who', 'name', 'role', 'body', 'time_label'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** الملفّ الذي تنتمي إليه الرسالة — يقرؤه `RecordsSender` لنقل مسؤوليّة المحادثة. */
    public function conversation(): ?Model
    {
        return $this->ticket;
    }

    // الشكل الذي تتوقعه الواجهة (يطابق Message في chat.ts)
    // `forClient`: حمولةٌ تصل العميل (صفحته أو بثٌّ على قناته) — لا عنوان IP فيها أيّاً كان الباني
    public function toMessage(bool $forClient = false): array
    {
        return [
            'id' => $this->id,
            'who' => $this->who,
            'name' => $this->senderName($forClient),
            'role' => $this->role,
            'text' => $this->body,
            'time' => $this->time_label,
            // التاريخ الحقيقي للرسالة — كانت الواجهة تطبع تاريخ اليوم على كل رسالة مهما قدُمت
            'date' => $this->created_at?->locale('ar')->translatedFormat('l j F Y'),
        ] + $this->senderIpField($forClient);
    }
}

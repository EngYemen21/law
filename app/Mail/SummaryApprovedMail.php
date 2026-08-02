<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * اعتماد المستشار لملخّص الملف (بريد) — يُرسَل للعميل عند اعتماد المستشار الملخّص
 * وإصدار الرأي القانونيّ المبدئيّ، ويدعوه لحجز استشارة لإتمام الرأي.
 */
class SummaryApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public ?string $opinion = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'اعتُمد ملخّص ملفك وصدر الرأي القانونيّ — '.$this->ticket->number);
    }

    public function content(): Content
    {
        $t = $this->ticket;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $opinionBlock = filled($this->opinion)
            ? '<div style="background:#F6F8FA;border-radius:10px;padding:12px 16px;margin:12px 0">'.nl2br(e($this->opinion)).'</div>'
            : '';

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">اعتُمد ملخّص ملفك</h2>'
            .'<p>مرحباً '.e($t->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>اعتمد المستشار القانونيّ ملخّص ملفك في التذكرة رقم <b>'.e($t->number).'</b> وأصدر الرأي القانونيّ المبدئيّ:</p>'
            .$opinionBlock
            .'<p>ولإبداء الرأي الكامل ومناقشة التفاصيل، ندعوك لحجز استشارة قانونية من حسابك.</p>'
            .'<p><a href="'.e($baseUrl.'/tickets/'.$t->number).'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">فتح التذكرة وحجز استشارة</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

<?php

namespace App\Mail;

use App\Models\LegalCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * تحديد أتعاب القضية (بريد) — يُرسَل للعميل عند تحديد الإدارة العليا للأتعاب وإصدار الفاتورة،
 * ويدعوه لسدادها لتفعيل القضية.
 */
class CaseFeeSetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case,
        public int $total
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'صدرت فاتورة أتعاب قضيتك — '.$this->case->number);
    }

    public function content(): Content
    {
        $c = $this->case;
        $baseUrl = rtrim((string) config('app.url'), '/');

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">تم تحديد أتعاب قضيتك</h2>'
            .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>حدّدت الإدارة العليا أتعاب قضيتك رقم <b>'.e($c->number).'</b> ('.e($c->type).') بمبلغ <b>'.e((string) $this->total).' ر.س</b> (شامل الضريبة).</p>'
            .'<p>يُرجى سداد الفاتورة لتفعيل القضية والبدء في إجراءاتها.</p>'
            .'<p><a href="'.e($baseUrl.'/cases').'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">سداد الأتعاب</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

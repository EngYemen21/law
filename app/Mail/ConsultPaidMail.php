<?php

namespace App\Mail;

use App\Models\Consult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * تأكيد سداد فاتورة الاستشارة (بريد) — يُرسَل للعميل عند نجاح الدفع عبر ميسّر،
 * ويدعوه لاختيار موعد الجلسة.
 */
class ConsultPaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام دفعتك — استشارة '.$this->consult->ref);
    }

    public function content(): Content
    {
        $c = $this->consult;
        $baseUrl = rtrim((string) config('app.url'), '/');

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">تم استلام دفعتك بنجاح</h2>'
            .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>تم تأكيد سداد فاتورة استشارتك رقم <b>'.e($c->ref).'</b> بمبلغ <b>'.e((string) $c->total).' ر.س</b> (شامل الضريبة).</p>'
            .'<p>خطوتك التالية: <b>اختيار موعد الجلسة</b> من حسابك.</p>'
            .'<p><a href="'.e($baseUrl.'/myconsults').'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">اختيار الموعد</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

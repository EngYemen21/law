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
 * تحويل التذكرة إلى قضية (بريد) — يُرسَل للعميل عند تحويل استشارته المكتملة إلى قضية قانونية.
 */
class CaseConvertedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public LegalCase $case,
        public string $ticketNumber
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم تحويل تذكرتك إلى قضية قانونية — '.$this->case->number);
    }

    public function content(): Content
    {
        $c = $this->case;
        $baseUrl = rtrim((string) config('app.url'), '/');

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">تم تحويل طلبك إلى قضية قانونية</h2>'
            .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>تم تحويل تذكرتك رقم <b>'.e($this->ticketNumber).'</b> إلى قضية قانونية رقم <b>'.e($c->number).'</b> ('.e($c->type).').</p>'
            .'<p>الخطوة التالية: تتولّى الإدارة تحديد الأتعاب، وستصلك فاتورتها لسدادها وتفعيل القضية.</p>'
            .'<p><a href="'.e($baseUrl.'/cases').'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">متابعة قضيتي</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

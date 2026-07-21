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
 * بريد «رابط الجلسة جاهز» — يُرسَل قبل الموعد بـ5 دقائق مع تفعيل زر الدخول في المنصّة.
 */
class MeetingLinkReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Consult $consult) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رابط جلسة استشارتك جاهز — '.$this->consult->ref);
    }

    public function content(): Content
    {
        $c = $this->consult;
        $portal = rtrim((string) config('app.url'), '/').'/myconsults';

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">رابط جلستك جاهز</h2>'
            .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>حان وقت استشارتك <b>'.e($c->ref).'</b> ('.e($c->when_label).'). زر <b>الدخول إلى الجلسة</b> مفعّل الآن في حسابك.</p>'
            .'<p><a href="'.e($portal).'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">الدخول إلى الجلسة</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

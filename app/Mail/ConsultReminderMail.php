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
 * تذكير بموعد الاستشارة (بريد) — يُرسَل للعميل قبل الموعد (طبقتا: نحو 24 ساعة، ونحو ساعة).
 * للجلسة المرئية: رابط الدخول يصل في بريد منفصل قبل الموعد بـ5 دقائق.
 */
class ConsultReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public string $remainingLabel
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير بموعد استشارتك ('.$this->remainingLabel.') — '.$this->consult->ref);
    }

    public function content(): Content
    {
        $c = $this->consult;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $video = $c->channel === 'مرئية'
            ? '<p>جلستك <b>مرئية</b> — سيصلك رابط الدخول قبل الموعد بـ<b>5 دقائق</b> ويُفعَّل زر الدخول في حسابك.</p>'
            : '';

        $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
            .'<h2 style="color:#0b5">تذكير بموعد استشارتك</h2>'
            .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
            .'<p>نذكّرك بأنّ موعد استشارتك رقم <b>'.e($c->ref).'</b> بعد <b>'.e($this->remainingLabel).'</b>.</p>'
            .'<ul>'
            .'<li><b>الموضوع:</b> '.e($c->subject).'</li>'
            .'<li><b>الموعد:</b> '.e($c->when_label).'</li>'
            .'<li><b>القناة:</b> '.e($c->channel).'</li>'
            .'<li><b>المستشار:</b> '.e($c->lawyer).'</li>'
            .'</ul>'
            .$video
            .'<p><a href="'.e($baseUrl.'/myconsults').'" style="background:#0b5;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;display:inline-block">استشاراتي</a></p>'
            .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}

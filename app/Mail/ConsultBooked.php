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
 * تأكيد فوري بحجز الاستشارة (بريد) — يُرسَل عند إتمام الحجز.
 * زر الدخول يبقى معطّلاً؛ رابط الجلسة المرئية يصل قبل الموعد بـ5 دقائق (بريد منفصل).
 */
class ConsultBooked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consult $consult,
        public bool $forLawyer = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->forLawyer
            ? 'موعد استشارة جديد مُسند إليك — '.$this->consult->ref
            : 'تأكيد حجز استشارتك — '.$this->consult->ref;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $c = $this->consult;
        if ($this->forLawyer) {
            $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
                .'<h2 style="color:#0b5">تم إسناد حجز استشارة جديد إليك</h2>'
                .'<p>مرحباً سعادة المستشار/ <b>'.e($c->lawyer).'</b>،</p>'
                .'<p>تم إسناد حجز استشارة جديد إليك برقم <b>'.e($c->ref).'</b> ('.e($c->channel).').</p>'
                .'<ul>'
                .'<li><b>العميل:</b> '.e($c->user?->name ?? 'عميل').'</li>'
                .'<li><b>الموضوع:</b> '.e($c->subject).'</li>'
                .'<li><b>الموعد:</b> '.e($c->when_label).'</li>'
                .'<li><b>الفرع/القناة:</b> '.e($c->branch ?: $c->channel).'</li>'
                .'</ul>'
                .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
                .'</div>';
        } else {
            $video = $c->channel === 'مرئية'
                ? '<p>سيصلك رابط جلستك المرئية قبل الموعد بـ<b>5 دقائق</b>، ويُفعَّل زر الدخول في حسابك عندها.</p>'
                : '';

            $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9;color:#222">'
                .'<h2 style="color:#0b5">تم تأكيد حجز استشارتك</h2>'
                .'<p>مرحباً '.e($c->user?->name ?? 'عميلنا الكريم').'،</p>'
                .'<p>تم تأكيد حجز استشارتك رقم <b>'.e($c->ref).'</b> ('.e($c->channel).').</p>'
                .'<ul>'
                .'<li><b>الموضوع:</b> '.e($c->subject).'</li>'
                .'<li><b>الموعد:</b> '.e($c->when_label).'</li>'
                .'<li><b>المستشار:</b> '.e($c->lawyer).'</li>'
                .'</ul>'
                .$video
                .'<p style="color:#777;margin-top:24px">مكتب سلاسل بابل للمحاماة والاستشارات القانونية</p>'
                .'</div>';
        }

        return new Content(htmlString: $html);
    }
}

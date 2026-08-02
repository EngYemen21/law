<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * خدمة بريد موحّدة قابلة لإعادة الاستخدام عبر المشروع — تُرسل أيّ Mailable لأيّ مستلم (عميل/موظف/محامي/إدارة).
 * نقطة إرسال واحدة: أفضل-جهد (try/catch + تسجيل بلا رمي)، فلا يتعطّل منطق الأعمال إن فشل البريد.
 * الاستخدام: app(MailService::class)->send($user, new MeetingScheduledMail(...))
 */
class MailService
{
    /**
     * إرسال Mailable لمستلم واحد أو أكثر. المستلم: User أو بريد نصّي أو مصفوفة منهما.
     *
     * @param  User|string|array<int,User|string>  $to
     */
    public function send(User|string|array $to, Mailable $mail): bool
    {
        $recipients = $this->recipients($to);

        if (empty($recipients)) {
            return false;
        }

        try {
            Mail::to($recipients)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::warning('mail.send.failed', [
                'mail' => $mail::class,
                'count' => count($recipients),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** توحيد المستلمين إلى قائمة عناوين بريد صالحة (يتجاهل من بلا بريد). */
    private function recipients(User|string|array $to): array
    {
        $list = is_array($to) ? $to : [$to];

        return collect($list)
            ->map(fn ($r) => $r instanceof User ? $r->email : $r)
            ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }
}

<?php

namespace App\Support;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * **عقدُ غرفة الجلسة — بانٍ واحد للغرف الأربع** (استشارة/اجتماع × عميل/طاقم).
 *
 * كانت كلّ غرفة تبني ما تعرضه بنفسها من بطاقةٍ مختلفة (`toCard` · `toClientCard` · `toFullCard`)،
 * فتسمّي الغرفةُ نفسها المحامي «المستشار» هنا و«المحامي» هناك، وتعرض «المدّة» الاسميّة كأنّها
 * عمر الجلسة، ويُحسب «جارية/منتهية» في الواجهة بقواعد غير قواعد الخادم. هنا تُبنى الغرفة مرّةً:
 *
 * - `for()` — خاصيّة Inertia `room` لكلّ صفحة غرفة.
 * - `state()` — الحال الحيّة نفسها، ويبثّها `RoomStateChanged` بالمفاتيح ذاتها فلا تتباعد الصفحة
 *   المحمّلة عن البثّ.
 *
 * **والعميل لا يُخبَر بالتسجيل** (قرار المالك): `recording` له `false` دائماً، وقناته لا تحمله.
 */
final class RoomDetails
{
    public static function kind(Consult|Meeting $session): string
    {
        return $session instanceof Consult ? 'consult' : 'meeting';
    }

    /** قناة الغرفة المشتركة (العميل المالك + الطاقم المخوّل) — `routes/channels.php`. */
    public static function channel(Consult|Meeting $session): string
    {
        return 'room.'.self::kind($session).'.'.$session->getKey();
    }

    /** قناة الطاقم وحده — تحمل ما لا يُقال للعميل (التسجيل). */
    public static function staffChannel(Consult|Meeting $session): string
    {
        return self::channel($session).'.staff';
    }

    /**
     * **الحال الحيّة** — ما تحمّله الصفحة وما يبثّه `RoomStateChanged` بالمفاتيح نفسها.
     *
     * @return array{live: bool, ended: bool, statusLabel: string, measuredDuration: ?string, participants: ?int, recording?: bool}
     */
    public static function state(Consult|Meeting $session, bool $staff): array
    {
        $live = $session->isLive();
        $ended = self::ended($session);

        $state = [
            'live' => $live,
            'ended' => $ended,
            'statusLabel' => self::statusLabel($session, $live),
            'measuredDuration' => $ended ? self::measuredDuration($session) : null,
            'participants' => $live ? RoomPresence::participants($session) : null,
        ];

        if ($staff) {
            $state['recording'] = $live && RoomPresence::recording($session);
        }

        return $state;
    }

    /** @return array<string, mixed> خاصيّة `room` لصفحة الغرفة — العقد مع الواجهة */
    public static function for(Consult|Meeting $session, User $viewer): array
    {
        $staff = $viewer->role !== Role::Client;
        $state = self::state($session, $staff);

        return [
            'kind' => self::kind($session),
            'ref' => (string) $session->ref,
            'title' => $session instanceof Consult
                ? ((string) $session->subject ?: 'استشارة قانونية')
                : ((string) $session->title ?: 'اجتماع'),
            'statusLabel' => $state['statusLabel'],
            'live' => $state['live'],
            'ended' => $state['ended'],
            'rows' => self::rows($session, $staff),
            'recording' => $staff ? $state['recording'] : false,
            'measuredDuration' => $state['measuredDuration'],
            'endAction' => $staff ? self::endAction($session, $viewer, $state['live']) : null,
            'summaryHref' => $state['ended'] && self::wasHeld($session) ? self::summaryHref($session, $viewer) : null,
            'back' => self::back($session, $viewer),
            'channel' => self::channel($session),
            'staffChannel' => $staff ? self::staffChannel($session) : null,
            // لا `hostUrl`: كان ملاذاً للطاقم يفتح الغرفة في Zoom خارج المنصّة — والدخول من غرفة المنصّة
            // وحدها (قرار المالك 2026-09-29). رابط المضيف لا يغادر الخادم
        ];
    }

    /**
     * **لماذا لا تُفتح صفحة الغرفة لهذا الزائر — `null` = تُفتح.** قاعدةٌ واحدة للغرف الأربع.
     *
     * السبب نفسه `joinBlocker()` (ونصّه) للنوعين والدورين، بفارقٍ واحد مقصود: الطاقم يفتح صفحة
     * الغرفة قبل أن تُفتح نافذة الدخول (يتهيّأ ويرى تفاصيلها) — والدخول الفعليّ إلى Zoom تحرسه
     * نقطة التوقيع بالقاعدة كاملة فترفضه بسببه. كانت غرفة الطاقم للاستشارة بلا حارسٍ إطلاقاً
     * (تُفتح لجلسةٍ منتهية أو فائتة)، وغرفة الاجتماع تحرس «فات» وحده بنصٍّ آخر.
     */
    public static function entryBlocker(Consult|Meeting $session, User $viewer): ?string
    {
        $why = $session->joinBlocker();

        if ($viewer->role !== Role::Client && $why === SessionWindow::refuseNotOpen()) {
            return null;
        }

        return $why;
    }

    /**
     * **ردُّ صفحة الغرفة حين لا تُفتح** — للغرف الأربع من موضعٍ واحد.
     *
     * كانت المتحكّمات ترمي `abort(422|403, $why)`: زيارةُ Inertia يلتقطها معالج الأخطاء فيُشعر، لكنّ
     * **فتحَ الرابط مباشرةً** (نسخُه، أو إعادة تحميل الغرفة بعد انتهاء الجلسة — وهو ما تعرضه النافذة
     * العائمة) يُسقط المستخدم على صفحة خطأٍ تقنيّة خام. رُصد في المتصفّح 2026-09-26 على M-26642.
     * فالصفحة تعود إلى صفحة الجلسة بالسبب نفسه إشعاراً، وطلب JSON وحده يأخذ الرمز والرسالة.
     *
     * **وهو المُحوِّل العامّ نفسه** (`ErrorResponse`) بوجهةٍ أدقّ: المُحوِّل يعيد صاحب الصفحة المرفوضة
     * إلى حيث جاء أو إلى لوحته، والغرفة تعرف وجهةً أنسب — صفحة الجلسة. وما ليس فتحَ صفحة (JSON،
     * فعل Inertia) يمرّ بـ`abort` إلى المُحوِّل فيأخذ شكله من هناك، لا من نسخةٍ ثانية هنا.
     */
    public static function refuse(Request $request, Consult|Meeting $session, string $why, int $status): RedirectResponse
    {
        abort_unless(ErrorResponse::isPageVisit($request) && ! ErrorResponse::wantsJson($request), $status, $why);

        return ErrorResponse::redirectWithError($request, $why, self::back($session, $request->user()))
            ?? abort($status, $why);
    }

    /**
     * **بعد زرّ الإنهاء:** عودةٌ إلى حيث ضُغط — إلّا إن ضُغط من الغرفة نفسها، فالغرفة المنتهية
     * تردّ داخلها بسبب رفضٍ (`joinBlocker`)، ومعالج الرفض يعيد إلى «السابق» وهو الغرفة ذاتها ⇒
     * حلقة. فمن الغرفة يُذهب إلى صفحة الجلسة حيث يُكتب الملخّص (`endAction.redirect` نفسه).
     */
    public static function afterEnd(Consult|Meeting $session, User $actor, string $flash): RedirectResponse
    {
        $previous = (string) parse_url(url()->previous(), PHP_URL_PATH);

        // والنجاح يُقال — صفحة الغرفة لا تعرض إشعاراً بنفسها، فالرسالة في التحويل
        return (str_ends_with($previous, '/videoroom') || str_ends_with($previous, '/meetingroom')
            ? redirect(self::staffDetail($session, $actor))
            : back())->with('flash', $flash);
    }

    private static function ended(Consult|Meeting $session): bool
    {
        if ($session instanceof Meeting) {
            return $session->liveState()[0] === 'past';
        }

        $state = SessionState::tryFrom((string) $session->session);

        return $state === SessionState::Ended
            || $state === SessionState::NotHeld
            || ConsultStatus::tryFrom((string) $session->status) === ConsultStatus::Cancelled
            || $session->isMissed();
    }

    /** انعقدت فعلاً؟ — لا رابط ملخّصٍ لجلسةٍ لم تُعقد. */
    private static function wasHeld(Consult|Meeting $session): bool
    {
        return $session instanceof Consult
            ? $session->session === SessionState::Ended->value
            : $session->status === MeetingStatus::Ended->value;
    }

    /** وصفٌ واحد للنوعين — الغرفة لا تعرض «جارٍ» لاجتماع و«جلسة جارية» لاستشارة. */
    private static function statusLabel(Consult|Meeting $session, bool $live): string
    {
        if ($live) {
            return 'جارية الآن';
        }

        // سببُ رفض الدخول هو نفسه وصفُ الحال — مصدرٌ واحد (`joinBlocker`) لا تصنيفٌ ثانٍ يتباعد عنه
        return match ($session->joinBlocker()) {
            SessionWindow::REFUSE_ENDED => 'انتهت الجلسة',
            SessionWindow::REFUSE_MISSED => 'فاتت الجلسة',
            SessionWindow::REFUSE_CANCELLED => 'أُلغيت الجلسة',
            SessionWindow::REFUSE_NOT_VIDEO => 'ليست جلسةً مرئية',
            default => 'بانتظار البدء',
        };
    }

    /** المدّة التي قاسها Zoom (`duration_sec`) — لا الاسميّة؛ بلا قياسٍ ⇒ `null` لا صفر. */
    private static function measuredDuration(Consult|Meeting $session): ?string
    {
        $seconds = (int) ($session->duration_sec ?? 0);

        return $seconds > 0 ? ArabicCount::duration(max(1, (int) round($seconds / 60))) : null;
    }

    /** @return list<array{label: string, value: string}> */
    private static function rows(Consult|Meeting $session, bool $staff): array
    {
        if ($session instanceof Consult) {
            $rows = [
                'المحامي' => $staff
                    ? ($session->assignedLawyer?->name ?: (string) $session->lawyer)
                    : $session->lawyerForClient('لم يُسنَد بعد'),
                'العميل' => $staff ? (string) $session->user?->name : '',
                'الموعد' => $session->whenLabel(),
                'المرجع' => (string) $session->ref,
                'القناة' => (string) $session->channel,
            ];
        } else {
            $rows = [
                'المحامي' => $staff
                    ? (string) $session->assignedLawyer?->name
                    : LawyerName::forClient($session->assignedLawyer, $session->assignedLawyer?->name, 'مستشار المكتب'),
                'العميل' => $staff ? ((string) $session->client_name ?: 'داخلي') : '',
                'الموعد' => (string) $session->when_label,
                'المرجع' => (string) $session->ref,
                'المشاركون' => $staff ? (string) $session->participants : '',
            ];
        }

        $out = [];
        foreach ($rows as $label => $value) {
            if (trim($value) !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }

        return $out;
    }

    /** @return array{url: string, label: string, enabled: bool, redirect: string, placeholder: string} */
    private static function endAction(Consult|Meeting $session, User $viewer, bool $live): array
    {
        $prefix = self::prefix($viewer);
        $consult = $session instanceof Consult;

        return [
            'url' => $consult
                ? route($prefix.'.consults.end', $session, absolute: false)
                : route($prefix.'.meetings.end', $session, absolute: false),
            'label' => $consult ? 'إنهاء الجلسة وكتابة الملخّص' : 'إنهاء الاجتماع',
            // الزرّ يتبع حارس الخادم نفسه (`isLive` في `EndSession`/`EndMeeting`) — لا يُعرض فعلٌ يُرفض
            'enabled' => $live,
            'redirect' => self::staffDetail($session, $viewer),
            'placeholder' => $consult
                ? 'ملاحظات الجلسة (اختياريّة) — تُبنى عليها مسوّدة الملخّص'
                : 'ملاحظات الاجتماع (اختياريّة) — تُبنى عليها مسوّدة المحضر',
        ];
    }

    private static function summaryHref(Consult|Meeting $session, User $viewer): string
    {
        if ($viewer->role === Role::Client) {
            return $session instanceof Consult ? '/myconsults' : '/meetings';
        }

        return self::staffDetail($session, $viewer);
    }

    private static function back(Consult|Meeting $session, User $viewer): string
    {
        if ($viewer->role === Role::Client) {
            return $session instanceof Consult ? '/myconsults' : '/meetings';
        }

        return self::staffDetail($session, $viewer);
    }

    /** صفحة الجلسة لدى الطاقم — حيث يُكتب الملخّص/المحضر. */
    private static function staffDetail(Consult|Meeting $session, User $viewer): string
    {
        $prefix = '/'.self::prefix($viewer);

        return $session instanceof Consult
            ? $prefix.'/consult?ref='.rawurlencode((string) $session->ref)
            : $prefix.'/meeting?id='.rawurlencode((string) $session->ref);
    }

    private static function prefix(User $viewer): string
    {
        return match ($viewer->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }
}

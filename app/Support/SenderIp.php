<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

/**
 * **عنوانُ IP لمُرسِل رسالة المحادثة — متى يُسجَّل، ومن يراه.** (طلب المالك 2026-09-25)
 *
 * مصدرٌ واحد للقاعدتين، يقرؤه `App\Models\Concerns\RecordsSenderIp` في نماذج الرسائل الثلاثة.
 *
 * **يُسجَّل** عنوانُ من كتب الرسالة بنفسه فقط: طلبُ HTTP فيه مستخدمٌ مسجَّل، والرسالة من بشر.
 * فلا عنوان لرسالة المساعد ولا لرسالة النظام، ولا لما يُكتب داخل مهمّة طابور — حتى المهمّة
 * المتزامنة التي تجري داخل طلب مستخدم: الطلب هنا ليس مُرسِلها، ونسبتُها إليه تزويرٌ لسجلّ تدقيق.
 *
 * **ويُرى** للطاقم وحده، والحجب خادميّ: العميل لا يستلم العنوان في أيّ حمولة، لا تُخفيه الواجهة.
 */
final class SenderIp
{
    /** كُتّابٌ آليّون: رسائلهم بلا مُرسِل بشريّ وإن كُتبت أثناء طلب مستخدم (اعتمادٌ يولّد رسالة نظام). */
    private const MACHINE_AUTHORS = ['ai', 'system'];

    /** عمق المهامّ الجارية الآن — في الحاوية لا متغيّرٍ ثابت (كـ`Live`): جديدةٌ لكلّ طلبٍ واختبار. */
    private const JOB_DEPTH = 'sender_ip.job_depth';

    /**
     * عنوانُ من يكتب هذه الرسالة الآن، أو null إن لم يكن لها مُرسِلٌ بشريّ في طلبٍ حيّ.
     *
     * لا يُسأل `runningInConsole()`: الاختبارات تجري من سطر الأوامر أيضاً فيتعطّل التسجيل فيها
     * كلّه، والإشارة الصادقة هي المستخدم المسجَّل — وهو غائبٌ في العامل والجدولة وأوامر artisan.
     */
    public static function forNewMessage(?string $who): ?string
    {
        if (in_array($who, self::MACHINE_AUTHORS, true) || self::insideJob() || ! Auth::hasUser()) {
            return null;
        }

        return request()->ip();
    }

    /**
     * هل يرى المشاهد الحاليّ عنوان المُرسِل؟ الطاقم نعم، والعميل والزائر لا.
     *
     * المنح صريحٌ بالأدوار الثلاثة لا «كلّ من ليس عميلاً»: دورٌ جديد يُضاف لا يرث رؤية العناوين
     * حتى يُقرَّر له ذلك.
     */
    public static function visibleToViewer(): bool
    {
        $viewer = Auth::user();

        return $viewer instanceof User && ($viewer->isAdmin() || $viewer->isEmployee() || $viewer->isLawyer());
    }

    /**
     * يتتبّع المهامّ الجارية ليعرف `forNewMessage` أنّه داخل مهمّة.
     *
     * أحداث الطابور تُطلَق للمهمّة المتزامنة (`dispatchSync` وطابور `sync`) كما للعامل، فيُغطّى
     * الطريقان بموضعٍ واحد. والعدّ لا العَلَم: مهمّةٌ تستدعي أخرى متزامنةً لا تُنهي سياق أمّها.
     */
    public static function trackJobs(): void
    {
        Event::listen(JobProcessing::class, fn () => app()->instance(self::JOB_DEPTH, self::depth() + 1));

        $leave = fn () => app()->instance(self::JOB_DEPTH, max(0, self::depth() - 1));
        Event::listen(JobProcessed::class, $leave);
        Event::listen(JobExceptionOccurred::class, $leave);
    }

    private static function insideJob(): bool
    {
        return self::depth() > 0;
    }

    private static function depth(): int
    {
        return app()->bound(self::JOB_DEPTH) ? (int) app(self::JOB_DEPTH) : 0;
    }
}

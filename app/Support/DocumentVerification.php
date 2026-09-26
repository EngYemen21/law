<?php

namespace App\Support;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Ticket;
use Illuminate\Support\Facades\URL;

/**
 * **رابط التحقّق من الوثيقة المطبوعة — المصدر الواحد لبنائه وقراءته.**
 *
 * رمز الاستجابة على بطاقة الموعد وتقرير الاستشارة وتقرير الملخّص وعرض التنفيذ يحمل رابطاً إلى
 * `/verify/{kind}/{ref}` يفتحه أيّ ماسح: موظّف الاستقبال، أو جهةٌ قُدّمت إليها الوثيقة. وتردّ
 * الصفحة بما يثبت أنّ المكتب أصدرها وبحالتها **الحيّة الآن** — فتقريرٌ طُبع قبل الاعتماد لا
 * يُقدَّم بعد ذلك كأنّه معتمد، وموعدٌ أُلغي لا يُحضَر ببطاقته.
 *
 * **لماذا رابطٌ موقَّع لا المرجع وحده؟** المراجع متسلسلة (`CN-2026-1042`) تُخمَّن بالعدّ، ولو
 * كفى المرجع لصار الفضوليّ يستعرض مواعيد المكتب كلّها. التوقيع (`APP_KEY`) لا يُصنع إلّا في
 * الخادم، فلا يفتح الصفحةَ إلّا من يحمل الوثيقة نفسها. ولا يحمل الرابط اسماً ولا رقم هويّة.
 *
 * **ولماذا توقيعٌ نسبيّ والأصل من `APP_URL`؟** التوقيع المطلق يُحسب على المضيف والبروتوكول
 * كما رآهما الطلب؛ وخلف الوسيط (nginx ينهي TLS) يرى التطبيق `http` فيرفض رابطاً طُبع بـ`https`.
 * والنسبيّ يوقّع المسار والاستعلام وحدهما. والأصل هو ما تبني به رسائل البريد روابطها
 * (`config('app.url')`) — فالرمز يحيل حيث تحيل الرسائل.
 *
 * **وما يظهر في الصفحة حدٌّ أدنى غير شخصيّ**: نوع الوثيقة ومرجعها وحالتها وتاريخها. لا اسم عميل
 * ولا محامٍ ولا مبلغ ولا مضمون — من يحمل الوثيقة يقرؤها منها، والصفحة تصدّقها فقط.
 */
final class DocumentVerification
{
    public const APPOINTMENT = 'appointment';

    public const CONSULT = 'consult';

    public const SUMMARY = 'summary';

    public const EXECUTION = 'execution';

    /** @var list<string> */
    public const KINDS = [self::APPOINTMENT, self::CONSULT, self::SUMMARY, self::EXECUTION];

    public const ROUTE = 'documents.verify';

    /** الرابط المطلق الموقَّع الذي يُرمَّز في رمز الاستجابة. */
    public static function url(string $kind, string $ref): string
    {
        $relative = URL::signedRoute(self::ROUTE, ['kind' => $kind, 'ref' => $ref], null, false);

        return rtrim((string) config('app.url'), '/').$relative;
    }

    /**
     * ما تعرضه صفحة التحقّق عن الوثيقة، أو `null` حين لا وثيقة بهذا المرجع (حُذفت أو لم تكن).
     *
     * @return array{label:string, rows:list<array{0:string,1:string}>}|null
     */
    public static function facts(string $kind, string $ref): ?array
    {
        return match ($kind) {
            self::APPOINTMENT => self::appointment($ref),
            self::CONSULT => self::consult($ref),
            self::SUMMARY => self::summary($ref),
            self::EXECUTION => self::execution($ref),
            default => null,
        };
    }

    /** @return array{label:string, rows:list<array{0:string,1:string}>}|null */
    private static function appointment(string $ref): ?array
    {
        $a = Appointment::with('consult')->where('ext_id', $ref)->first();
        if (! $a) {
            return null;
        }

        return ['label' => 'بطاقة موعد', 'rows' => [
            ['رقم الموعد', (string) $a->ext_id],
            ['نوع الموعد', (string) $a->type],
            ['اليوم والوقت', trim($a->dayLabel().' · '.$a->timeLabel(), ' ·')],
            // الحالة الحيّة لا المخزَّنة: «لم يحضر» و«تم الحضور» تُشتقّان عند القراءة
            ['حالة الموعد', $a->liveState()[1]],
        ]];
    }

    /** @return array{label:string, rows:list<array{0:string,1:string}>}|null */
    private static function consult(string $ref): ?array
    {
        $c = Consult::where('ref', $ref)->first();
        if (! $c) {
            return null;
        }

        return ['label' => 'تقرير استشارة قانونيّة', 'rows' => [
            ['رقم الاستشارة', (string) $c->ref],
            ['نوع الاستشارة', (string) ($c->channel ?: '—')],
            // تسمية العميل — الصفحة عامّة، و«بانتظار اعتماد الموعد» شأنٌ داخليّ
            ['حالة الاستشارة', ConsultStatus::tryFrom((string) $c->status)?->clientLabel() ?? (string) $c->status],
        ]];
    }

    /** @return array{label:string, rows:list<array{0:string,1:string}>}|null */
    private static function summary(string $ref): ?array
    {
        $ticket = Ticket::with('summary')->where('number', $ref)->first();
        $summary = $ticket?->summary;
        if (! $ticket || ! $summary) {
            return null;
        }

        // **هذا ما يُتحقَّق منه فعلاً:** نسخةٌ طُبعت «قيد الدراسة» تبقى كذلك هنا حتى يُعتمد الملخّص
        return ['label' => 'تقرير دراسة الملفّ والرأي القانونيّ', 'rows' => [
            ['المرجع', 'REF-'.$ticket->number],
            ['حالة الاعتماد', $summary->isApproved() ? 'معتمد' : 'لم يُعتمد بعد'],
            ['تاريخ الاعتماد', $summary->isApproved() ? ($summary->approved_at?->format('Y-m-d') ?? '—') : '—'],
        ]];
    }

    /** @return array{label:string, rows:list<array{0:string,1:string}>}|null */
    private static function execution(string $ref): ?array
    {
        $e = Execution::where('number', $ref)->first();
        if (! $e) {
            return null;
        }

        return ['label' => 'عرض/فاتورة خدمة التنفيذ', 'rows' => [
            ['رقم الطلب', (string) $e->number],
            ['حالة الطلب', (string) ($e->status ?: '—')],
        ]];
    }
}

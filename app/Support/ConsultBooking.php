<?php

namespace App\Support;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Consult\PriceConsult;
use App\Domain\Journey\Transitions\Consult\RepriceConsult;
use App\Domain\Journey\Transitions\Consult\SettlePayment;
use App\Domain\Journey\Transitions\Ticket\RequestConsultBooking;
use App\Domain\Journey\Workflow;
use App\Enums\BusyKind;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Mail\ConsultBooked;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MoyasarService;
use App\Services\ZoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\OptionalProp;

/**
 * دورة حياة حجز الاستشارة (مطابقة لتصميم رحلة التذكرة) — مصدر موحّد:
 *   request()  → طلب الاستشارة (نوعها فقط) بحالة «بانتظار التسعير» بلا موعد ولا دفع.
 *   setPrice() → الإدارة تُسعّر الطلب، تُصدر فاتورة، وتنقله إلى «بانتظار السداد».
 *   markPaid() → بعد سداد الفاتورة عبر ميسّر (webhook/callback → PaymentReconciler) ينقل إلى «بانتظار تحديد الموعد» (idempotent).
 *   schedule() → اختيار الموعد (بعد الدفع فقط) → Appointment + Zoom + «جديدة» (دخول الرحلة).
 *
 * create() يبقى مساراً فورياً «مدفوعاً مسبقاً» لحجز الموظف نيابةً عن العميل (بلا بوّابة).
 * الأسعار تُقرأ من إعدادات الإدارة (Setting) وتُستخدم قيمةً ابتدائية مقترحة يعتمدها/يعدّلها المسعّر.
 */
class ConsultBooking
{
    /** تنبيه الحاجز حين يُقبل موعدٌ فوق انشغالٍ آخر للمحامي (`conflictVerdict`). */
    public const OVERLAP_NOTICE = '⚠️ تنبيه: للمحامي ارتباطٌ آخر في هذا الوقت.';

    /**
     * أنواع الاستشارة ومكانها المكتوب على الموعد.
     *
     * دالة لا ثابتاً: عنوان المكتب إعدادٌ (`office_address` في `SettingsRegistry`) — كان
     * مصلَّباً هنا، فتغييره يغيّر ما يُعرض ولا يغيّر ما يُكتب على الموعد المحجوز.
     *
     * @return array<string, array{label:string, ico:string, place:string}>
     */
    private static function map(): array
    {
        return [
            'office' => ['label' => 'حضورية', 'ico' => 'office', 'place' => SettingsRegistry::str('office_address')],
            'video' => ['label' => 'مرئية', 'ico' => 'video', 'place' => 'اجتماع إلكتروني'],
            'phone' => ['label' => 'هاتفية', 'ico' => 'phone', 'place' => 'مكالمة هاتفية'],
        ];
    }

    /**
     * أنواع الاستشارة للنماذج — المفتاح والاسم والأيقونة من `map()` نفسها، فلا تُكتب في الواجهة.
     *
     * @return list<array{key:string, label:string, ico:string}>
     */
    public static function typeOptions(): array
    {
        return collect(self::map())->map(fn (array $t, string $key) => ['key' => $key, 'label' => $t['label'], 'ico' => $t['ico']])->values()->all();
    }

    /**
     * **بيانات نموذج «طلب استشارة نيابةً عن العميل»** — خاصّيّةٌ اختياريّة لا تُحمَّل إلّا حين يُفتح النموذج
     * (`router.reload({ only: ['consultRequestForm'] })`)، فلا يُثقل دليلُ العملاء الصفحاتِ الأربع التي تعرض الزرّ.
     */
    public static function onBehalfForm(): OptionalProp
    {
        return Inertia::optional(fn () => ['clients' => ClientDirectory::list(), 'types' => self::typeOptions()]);
    }

    /**
     * تسجيل صريح حين تُجدوَل جلسة مرئية بلا اجتماع Zoom.
     *
     * ZoomService لا يرمي أبداً — كل إخفاق يتحوّل إلى null، وبعضه بلا سطر سجلّ أصلاً
     * (كابح التهدئة 60 ثانية يُصمت الخدمة كلياً بعد أول فشل رمز). فبلا هذا التسجيل
     * على مستوى العمل لا يبقى أي أثر يربط «العميل لا يستطيع الدخول» بسببه الحقيقي.
     *
     * @param  array<string, mixed>|null  $zoom
     */
    private static function logMissingMeeting(bool $isVideo, ?array $zoom, string $ref): void
    {
        if ($isVideo && empty($zoom['id'])) {
            Log::error('Zoom: تعذّر إنشاء اجتماع الاستشارة — تُجدوَل بلا رابط جلسة', [
                'consult' => $ref,
            ]);
        }
    }

    /**
     * الخطوة 1 — طلب استشارة (النوع فقط). لا Appointment ولا Zoom ولا حارس تعارض بعد.
     *
     * @param  array{type:string,lawyer_id?:int,lawyer?:string,subject?:string,specialty?:string,department?:string}  $data
     */
    public static function request(User $client, array $data, ?Ticket $ticket = null): Consult
    {
        $ctx = self::resolveContext($client, $data, $ticket);
        $m = $ctx['meta'];

        // تُفتح داخل المحرّك: سطرُ فتحٍ في سجلّ رحلتها بالطالب ونوع الجلسة
        $consult = Workflow::open('consult.request', fn () => Consult::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket?->id,
            'ref' => $ctx['ref'],
            'subject' => $ctx['subject'],
            'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة',
            'type' => $ctx['type'],
            'specialty' => $ctx['specialty'],
            'priority' => 'متوسطة',
            'received_label' => 'الآن',
            'channel' => $m['label'],
            'lawyer' => $ctx['lawyer'],
            'assigned_lawyer_id' => $ctx['lawyerId'],
            'phone' => $data['type'] === 'phone' ? $client->phone : null,
            // سعر ابتدائي مقترح من إعدادات الإدارة — لا يُفعِّل السداد حتى يعتمده المسعّر
            'price' => $ctx['price'],
            'vat' => $ctx['vat'],
            'total' => $ctx['price'] + $ctx['vat'],
            'audit' => [['user' => 'النظام', 'field' => 'الاستقبال', 'before' => '—', 'after' => 'طلب تسعير — '.$m['label'], 'time' => now()->format('Y/m/d h:i')]],
        ]), $client, array_filter(['type' => $data['type'], 'ticket' => $ticket?->number]));

        // إشعار المحامي المسند (إن وُجد) بطلب تسعير جديد — قائمة التسعير تغطّي بقية الموظفين
        if ($ctx['lawyerId']) {
            Notify::send($ctx['lawyerId'], 'card', 't-amber', "طلب تسعير استشارة جديد ({$consult->ref}) — {$m['label']}.");
        }

        if ($ticket) {
            // المنادون جميعاً فحصوا `consultRequestBlocker` قبل الطلب؛ والانتقال يضمنه لغيرهم
            Workflow::run(new RequestConsultBooking, $ticket, $client, ['consult' => $consult->ref]);
        }

        Live::push(new ConsultStatusBroadcast($consult));

        return $consult;
    }

    /**
     * الخطوة 2 — الإدارة/المكتب تُسعّر الطلب وتُصدر فاتورة الاستشارة.
     */
    public static function setPrice(Consult $consult, int $price, User $actor, ?string $channel = null): Invoice
    {
        abort_unless($consult->status === 'بانتظار التسعير', 422, 'لا يمكن تسعير هذا الطلب في حالته الحالية.');

        /*
         * **لا فاتورةَ بصفر.** كان التحقّق `min:0` والواجهة تسمح بالصفر من مودال
         * الجدول، فتُنشأ فاتورةٌ بـ٠ ر.س حالتُها «مستحقّة» وتنتقل الاستشارة إلى
         * «بانتظار السداد» — فلا تعود قابلةً للتسعير (الحارس أعلاه)، ولا يستطيع
         * العميل سداد صفر، **وتعرضها الشاشة «لم تُسعر بعد»** لأن `total` صفرٌ falsy.
         * طلبٌ عالقٌ بلا مخرجٍ إلّا الإلغاء.
         *
         * والحدّ هنا لا في التحقّق وحده: `setPrice` مدخلٌ عامّ، والحارس عند الكاتب
         * لا عند أحد نداءاته.
         */
        abort_if($price < 1, 422, 'أقلّ سعرٍ للاستشارة ريالٌ واحد — استعمل الإلغاء إن كانت بلا مقابل.');

        $prices = Setting::consultPrices();
        $vat = (int) round($price * $prices['vat'] / 100);
        $total = $price + $vat;

        // السعر والفاتورة والحالة في معاملة المحرّك الواحدة — انظر `PriceConsult`
        $pricing = new PriceConsult;
        Workflow::run($pricing, $consult, $actor, array_filter([
            'price' => $price,
            'vat' => $vat,
            'total' => $total,
            'channel' => $channel,
        ], fn ($v) => $v !== null));
        $invoice = $pricing->invoice();

        $fresh = $consult->fresh();
        Notify::send($consult->user_id, 'card', 't-amber', "صدرت فاتورة استشارتك ({$consult->ref}) بمبلغ {$total} ر.س شامل الضريبة — سدّدها ليُحدَّد موعد جلستك.");

        Audit::log(
            action: 'تسعير استشارة',
            description: "سعّر {$actor->name} الاستشارة {$fresh->ref} ({$fresh->channel}) بمبلغ {$price} ر.س (الإجمالي {$total} ر.س) وصدرت الفاتورة {$invoice->number}.",
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $fresh,
            beforeState: ['الحالة' => 'بانتظار التسعير'],
            afterState: ['السعر' => $price, 'الإجمالي' => $total, 'القناة' => $fresh->channel, 'الحالة' => 'بانتظار السداد'],
            user: $actor,
        );

        Live::push(new ConsultStatusBroadcast($fresh));

        return $invoice;
    }

    /**
     * **إلغاء تسعيرٍ خاطئ وإعادة الطلب إلى الطابور.**
     *
     * الفاتورة تُلغى ولا تُحذف: صفٌّ صدر باسم عميلٍ وأُشعر به، فمحوُه يُخفي ما وقع.
     * وتُصفَّر `priced_at` وحدها — لا `price`/`total`، فيرى المسعّر رقمه السابق
     * ويصحّحه بدل أن يبدأ من فراغ. والحالة تعود «بانتظار التسعير» فيُقبل `setPrice`.
     *
     * والإشعار لازم: العميل تلقّى فاتورةً وقد يكون في طريقه إلى سدادها.
     */
    public static function reprice(Consult $consult, User $actor): void
    {
        $before = (string) $consult->total;

        // الفاتورة تُلغى والحالة تعود داخل المحرّك في معاملةٍ واحدة — انظر `RepriceConsult`
        Workflow::run(new RepriceConsult, $consult, $actor, ['before' => $before]);

        Notify::send(
            $consult->user_id,
            'card',
            't-amber',
            "أُلغيت فاتورة استشارتك ({$consult->ref}) لمراجعة السعر — تصلك فاتورةٌ محدَّثة قريباً."
        );

        Audit::log(
            action: 'إلغاء تسعير استشارة',
            description: "ألغى {$actor->name} تسعير الاستشارة {$consult->ref} (كان الإجمالي {$before} ر.س) وأعادها إلى التسعير.",
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الإجمالي' => $before, 'الحالة' => 'بانتظار السداد'],
            afterState: ['الحالة' => 'بانتظار التسعير'],
            user: $actor,
        );

        Live::push(new ConsultStatusBroadcast($consult->fresh()));
    }

    /**
     * يعلّم الاستشارة مدفوعة وينقلها لاختيار الموعد — **idempotent** (يخدم الـwebhook والـcallback).
     * يعود فورًا إن كانت مدفوعة أصلًا (تكرار إشعار ميسّر لا يُحدث أثرًا مزدوجًا).
     */
    public static function markPaid(Consult $consult, string $actor = 'النظام', string $auditNote = 'مدفوع', ?Invoice $invoice = null): bool
    {
        // **الفاتورة التي دُفعت بعينها** — كانت `$consult->invoice` (أحدث فاتورة) فتُعلَّم الجديدة
        // مدفوعةً بدفعة القديمة الملغاة (ع٢)؛ والانتقال لا يقع إلا من «بانتظار السداد» فلا يُحيي ملغاةً (ع١).
        // `false` حين لا يقبل الملفّ السداد — والمتّصل يقرّر ماذا يفعل بمبلغٍ حُصّل.
        $invoice ??= Invoice::where('consult_id', $consult->id)
            ->where('paid', false)
            ->whereIn('status', [InvoiceStatus::Due->value, InvoiceStatus::ProofReview->value])
            ->latest('id')
            ->first();

        if ($invoice === null) {
            return false;
        }

        try {
            Workflow::run(new SettlePayment, $consult, null, [
                'invoice_id' => $invoice->id,
                'note' => $auditNote,
                'actor_name' => $actor,
            ]);
        } catch (TransitionDenied) {
            return false;
        }

        return true;
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة الاستشارة ويعيد رابط صفحة الدفع (أو null عند التعذّر).
     * يخزّن معرّف فاتورة البوّابة على الفاتورة للمطابقة عند العودة/الـwebhook.
     */
    public static function initiatePayment(Consult $consult, string $callbackUrl): ?string
    {
        $invoice = $consult->invoice;

        return $invoice ? app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    /** إرسال إشعارات البريد الإلكتروني لتأكيد الموعد للعميل والمحامي المسند */
    public static function sendBookingEmails(Consult $consult): void
    {
        if (! $consult->relationLoaded('user') && $consult->user_id) {
            $consult->load('user');
        }

        // 1. بريد العميل
        if ($consult->user?->email) {
            try {
                Mail::to($consult->user->email)->send(new ConsultBooked($consult, forLawyer: false));
            } catch (\Throwable $e) {
                Log::warning('ConsultBooking client email failed: '.$e->getMessage());
            }
        }

        // 2. بريد المحامي المسند
        $lawyerUser = $consult->assignedLawyer ?? ($consult->assigned_lawyer_id ? User::find($consult->assigned_lawyer_id) : null);
        if ($lawyerUser?->email && $lawyerUser->id !== $consult->user_id) {
            try {
                Mail::to($lawyerUser->email)->send(new ConsultBooked($consult, forLawyer: true));
            } catch (\Throwable $e) {
                Log::warning('ConsultBooking lawyer email failed: '.$e->getMessage());
            }
        }
    }

    /**
     * حلّ سياق الحجز المشترك (المحامي/الموضوع/التخصّص/السعر الابتدائي/المرجع).
     *
     * @return array{meta:array,type:string,ref:string,lawyer:string,lawyerId:?int,subject:string,specialty:?string,price:int,vat:int}
     */
    private static function resolveContext(User $client, array $data, ?Ticket $ticket): array
    {
        $m = self::map()[$data['type']];

        // **محامٍ لا أيّ مستخدم.** كان `User::find()` بلا فحص دور، فمعرّفُ إداريٍّ يُكتب اسمه في
        // حقل المستشار ويقرأ العميل «المستشار: الإدارة العليا». والدور يُفحَص هنا عند المصدر لا
        // عند العرض، كي لا يُخزَّن في الصفّ ما ليس صحيحاً أصلاً.
        $lawyerOnly = fn (?int $id) => $id
            ? User::where('id', $id)->where('role', Role::Lawyer)->first()
            : null;

        $lawyerUser = ! empty($data['lawyer_id'])
            ? $lawyerOnly((int) $data['lawyer_id'])
            : $lawyerOnly($ticket?->assigned_lawyer_id);
        $lawyer = $lawyerUser?->name
            ?: (($data['lawyer'] ?? null) ?: ($ticket?->assigned_lawyer ?: 'المستشار القانوني'));

        $subject = $data['subject'] ?? $ticket?->type ?? 'استشارة قانونية';
        $specialty = Specialties::normalize($data['specialty'] ?? $lawyerUser?->department) ?: null;
        $dept = $data['department'] ?? $ticket?->department;

        $prices = Setting::consultPrices();
        $price = (int) $prices[$data['type']];
        $vat = (int) round($price * $prices['vat'] / 100);

        return [
            'meta' => $m,
            'type' => preg_replace('/^القسم\s+/u', '', (string) $dept) ?: 'عام',
            'ref' => ReferenceNumber::next(Consult::class, 'ref', 'CN'),
            'lawyer' => $lawyer,
            'lawyerId' => $lawyerUser?->id,
            'subject' => $subject,
            'specialty' => $specialty,
            'price' => $price,
            'vat' => $vat,
        ];
    }

    /** يرمي خطأ تحقّق إن كان الموعد يتعارض مع موعد مؤكد آخر للمحامي (مع قفل السجلات). */
    /**
     * الحارس داخل المعاملة — **المصدر نفسه** الذي يفحصه ما قبله.
     *
     * كان يقرأ `appointments` وحده بينما الفحص الأوّليّ (`isBusy`) يقرأ أربعة جداول،
     * فهو أضيق من الحاجز الذي سبقه: اجتماعٌ أو دعوةٌ في الوقت نفسه لا يمنعانه.
     * وهو خطّ الدفاع الأخير في السباق، فضِيقُه يعني أن سباقاً بين حجزٍ واجتماع يمرّ.
     *
     * والآن يستعمل `conflictVerdict` نفسها (فوق `LawyerAvailability::conflictAt`) — فلا يمكن للحاجزين
     * أن يفترقا مستقبلاً لأنهما صارا شيفرةً واحدة.
     *
     * ويبقى القفل: `lockForUpdate` على مواعيد اليوم يُسلسل المتسابقين على المحامي
     * نفسه. (توسيع القفل ليشمل الجداول الأربعة شأنُ الدفعة ٧ — قفلُ صفّ المحامي.)
     *
     * @return bool قُبل الموعد فوق انشغالٍ آخر (خيار الحجز المتداخل) — يسجّله الانتقال `overlap`
     */
    public static function guardNoConflict(int $lawyerId, Carbon $start, int $duration): bool
    {
        // القفل أوّلاً: يُسلسل الحجوزات المتزامنة على هذا المحامي في هذا اليوم
        Appointment::where('lawyer_id', $lawyerId)
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$start->copy()->startOfDay(), $start->copy()->endOfDay()])
            ->lockForUpdate()
            ->get(['id']);

        return self::conflictVerdict($lawyerId, $start, $duration, 'starts_at', 'هذا الموعد محجوز لدى المستشار، فضلاً اختر موعداً آخر.');
    }

    /**
     * **قرار الحجز على انشغال المحامي — من هنا وحده** (الفحص المسبق في `Employee\ScheduleController::store`،
     * و`guardNoConflict` داخل معاملة الاقتراح والنشر — وهذا ما يُسجَّل `overlap` في الرحلة):
     *
     *   • جلسة محكمة ← رفضٌ دائماً.
     *   • انشغالٌ آخر ← رفضٌ، إلّا إن سمحت الإدارة بالحجز المتداخل (`consult_allow_overlap`).
     *
     * @return bool `true` حين يُقبل الحجز رغم انشغالٍ آخر — ليُنبَّه الحاجز (`ScheduledConsult::notice`)
     *
     * @throws ValidationException
     */
    public static function conflictVerdict(int $lawyerId, Carbon $start, int $duration, string $field, string $busyMessage): bool
    {
        $kind = LawyerAvailability::conflictAt($lawyerId, $start, $duration);

        if ($kind === BusyKind::Hearing) {
            throw ValidationException::withMessages([$field => 'للمحامي جلسة محكمة في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.']);
        }

        if ($kind === BusyKind::Busy && ! SettingsRegistry::bool('consult_allow_overlap')) {
            throw ValidationException::withMessages([$field => $busyMessage]);
        }

        return $kind === BusyKind::Busy;
    }

    /**
     * مفتاح النوع (office/video/phone) من القناة المكتوبة على الاستشارة («حضورية»…) —
     * عكس meta() من الخريطة نفسها، فلا تتباعد التسميتان. القناة المجهولة تُعامل «حضورية».
     */
    public static function typeOfChannel(?string $channel): string
    {
        foreach (self::map() as $type => $meta) {
            if ($meta['label'] === $channel) {
                return $type;
            }
        }

        return 'office';
    }

    /** بيانات العرض للنوع (label/ico/place الافتراضي). */
    public static function meta(string $type): array
    {
        return self::map()[$type] ?? self::map()['office'];
    }
}

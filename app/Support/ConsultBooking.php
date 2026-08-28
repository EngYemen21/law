<?php

namespace App\Support;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\TicketStatusBroadcast;
use App\Mail\ConsultBooked;
use App\Mail\ConsultPaidMail;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MailService;
use App\Services\MoyasarService;
use App\Services\ZoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

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
    /**
     * أنواع الاستشارة ومكانها المكتوب على الموعد.
     *
     * دالة لا ثابتاً: عنوان المكتب يأتي من config('office.address') — كان مصلَّباً هنا،
     * فتغيير OFFICE_ADDRESS يغيّر ما يُعرض ولا يغيّر ما يُكتب على الموعد المحجوز.
     *
     * @return array<string, array{label:string, ico:string, place:string}>
     */
    private static function map(): array
    {
        return [
            'office' => ['label' => 'حضورية', 'ico' => 'office', 'place' => (string) config('office.address')],
            'video' => ['label' => 'مرئية', 'ico' => 'video', 'place' => 'اجتماع إلكتروني'],
            'phone' => ['label' => 'هاتفية', 'ico' => 'phone', 'place' => 'مكالمة هاتفية'],
        ];
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

        $consult = Consult::create([
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
        ]);

        // إشعار المحامي المسند (إن وُجد) بطلب تسعير جديد — قائمة التسعير تغطّي بقية الموظفين
        if ($ctx['lawyerId']) {
            Notify::send($ctx['lawyerId'], 'card', 't-amber', "طلب تسعير استشارة جديد ({$consult->ref}) — {$m['label']}.");
        }

        if ($ticket) {
            $ticket->update([
                'status' => 'بانتظار حجز الاستشارة',
                'tone' => TicketJourney::toneFor('بانتظار حجز الاستشارة'),
                'last_message' => 'تم فتح طلب حجز استشارة بانتظار استكمال الخطوات.',
                'date_label' => 'الآن',
            ]);
            Live::push(new TicketStatusBroadcast($ticket));
        }

        Live::push(new ConsultStatusBroadcast($consult));

        return $consult;
    }

    /**
     * الخطوة 2 — الإدارة/المكتب تُسعّر الطلب وتُصدر فاتورة الاستشارة.
     */
    public static function setPrice(Consult $consult, int $price, User $actor): Invoice
    {
        abort_unless($consult->status === 'بانتظار التسعير', 422, 'لا يمكن تسعير هذا الطلب في حالته الحالية.');

        $prices = Setting::consultPrices();
        $vat = (int) round($price * $prices['vat'] / 100);
        $total = $price + $vat;

        $invoice = DB::transaction(function () use ($consult, $actor, $price, $vat, $total) {
            $consult->logAudit($actor->name, 'التسعير', (string) $consult->total, (string) $total);
            $consult->update([
                'price' => $price,
                'vat' => $vat,
                'total' => $total,
                'priced_at' => now(),
                'status' => 'بانتظار السداد',
                'audit' => $consult->audit,
            ]);

            return Invoice::create([
                'user_id' => $consult->user_id,
                'consult_id' => $consult->id,
                'number' => InvoiceNumber::next(),
                'description' => "استشارة {$consult->ref} — {$consult->channel}",
                'amount' => $total,
                'status' => 'مستحقة',
                'tone' => 'b-amber',
                'due_label' => 'خلال 3 أيام',
                'due_at' => now()->addDays(3)->toDateString(),
                'paid' => false,
            ]);
        });

        Notify::send($consult->user_id, 'card', 't-amber', "صدرت فاتورة استشارتك ({$consult->ref}) بمبلغ {$total} ر.س شامل الضريبة — سدّدها لاختيار الموعد.");

        Audit::log(
            action: 'تسعير استشارة',
            description: "سعّر {$actor->name} الاستشارة {$consult->ref} بمبلغ {$price} ر.س (الإجمالي {$total} ر.س) وصدرت الفاتورة {$invoice->number}.",
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $consult,
            beforeState: ['الحالة' => 'بانتظار التسعير'],
            afterState: ['السعر' => $price, 'الإجمالي' => $total, 'الحالة' => 'بانتظار السداد'],
            user: $actor,
        );

        Live::push(new ConsultStatusBroadcast($consult->fresh()));

        return $invoice;
    }

    /**
     * يعلّم الاستشارة مدفوعة وينقلها لاختيار الموعد — **idempotent** (يخدم الـwebhook والـcallback).
     * يعود فورًا إن كانت مدفوعة أصلًا (تكرار إشعار ميسّر لا يُحدث أثرًا مزدوجًا).
     */
    public static function markPaid(Consult $consult, string $actor = 'النظام', string $auditNote = 'مدفوع'): void
    {
        // قفل الصفّ داخل المعاملة ثم إعادة فحص paid_at — يمنع تسابق webhook+callback (لا إشعار مزدوج)
        $didPay = DB::transaction(function () use ($consult, $actor, $auditNote) {
            $locked = Consult::whereKey($consult->id)->lockForUpdate()->first();
            if ($locked === null || $locked->paid_at !== null) {
                return false; // مدفوعة مسبقًا أو تسابق — لا تكرار
            }

            $locked->logAudit($actor, 'السداد', 'بانتظار السداد', $auditNote);
            $locked->update([
                'paid_at' => now(),
                'status' => 'بانتظار تحديد الموعد',
                'audit' => $locked->audit,
            ]);
            $locked->invoice?->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);

            return true;
        });

        if (! $didPay) {
            return;
        }

        $consult->refresh();

        // إشعار العميل بنجاح الدفع (لحظيّ)
        $icon = $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office');
        Notify::send($consult->user_id, $icon, 't-green', "تمّت عملية الدفع بنجاح — استشارتك ({$consult->ref}). اختر الآن موعد الجلسة.");

        // بريد تأكيد الدفع للعميل (أفضل-جهد — لا يعطّل مسار الدفع إن فشل)
        if (! $consult->relationLoaded('user') && $consult->user_id) {
            $consult->load('user');
        }
        if ($consult->user?->email) {
            app(MailService::class)->send($consult->user, new ConsultPaidMail($consult));
        }

        // إشعار الإدارة العليا بعملية الدفع (لحظيّ)
        foreach (User::where('role', Role::Admin)->get() as $admin) {
            Notify::send($admin->id, 'card', 't-green', "تمّت عملية دفع استشارة {$consult->ref} بمبلغ {$consult->total} ر.س من العميل.");
        }

        Live::push(new ConsultStatusBroadcast($consult));
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

    /**
     * الخطوة 4 — اختيار الموعد (يُحظر قبل السداد). يُنشئ Appointment + Zoom + حارس التعارض.
     *
     * @param  array{lawyer_id?:int,day:string,time:string,starts_at?:string,duration?:int,place?:string}  $slot
     */
    public static function schedule(Consult $consult, array $slot): Consult
    {
        abort_unless($consult->status === 'بانتظار تحديد الموعد', 422, 'يلزم سداد الاستشارة قبل اختيار الموعد.');

        $type = array_search($consult->channel, array_map(fn ($x) => $x['label'], self::map()), true) ?: 'office';
        $m = self::map()[$type];

        // المحامي المختار عند الجدولة (يُحدّث الإسناد ويمنع الحجز المزدوج)
        $lawyerUser = ! empty($slot['lawyer_id']) ? User::find((int) $slot['lawyer_id']) : ($consult->assigned_lawyer_id ? User::find($consult->assigned_lawyer_id) : null);
        $lawyer = $lawyerUser?->name ?: ($consult->lawyer ?: 'المستشار القانوني');
        $lawyerId = $lawyerUser?->id;
        $place = ($type === 'office' && ! empty($slot['place'])) ? $slot['place'] : $m['place'];
        $startsAt = ! empty($slot['starts_at']) ? Carbon::parse($slot['starts_at']) : null;
        $duration = (int) ($slot['duration'] ?? LawyerAvailability::slotMinutes());

        // إنشاء اجتماع Zoom (للمرئية) خارج المعاملة تفادياً لحبس القفل أثناء نداء الشبكة
        $zoom = $consult->channel === 'مرئية'
            ? app(ZoomService::class)->createMeeting("استشارة {$consult->ref} — {$consult->subject}", $duration, false, $startsAt)
            : null;

        // إخفاق Zoom لا يوقف الحجز (العميل سدّد، والجلسة قد تُدار يدوياً) — لكنه لا يُبتلع:
        // بلا هذا السطر تُجدوَل الاستشارة وتُفوتَر ويُرسَل بريدها بـmeet_id فارغ، ولا أثر في
        // السجلّ يدلّ على السبب، فيصل العميل الغرفة ويحصل على 422 بلا تفسير ولا استدراك.
        self::logMissingMeeting($consult->channel === 'مرئية', $zoom, $consult->ref);

        try {
            DB::transaction(function () use ($consult, $slot, $m, $place, $lawyer, $lawyerId, $startsAt, $duration, $zoom) {
                if ($lawyerId && $startsAt) {
                    self::guardNoConflict($lawyerId, $startsAt, $duration);
                }

                $appt = Appointment::create([
                    'user_id' => $consult->user_id,
                    'ticket_id' => $consult->ticket_id,
                    'ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999),
                    'type' => 'استشارة '.$m['label'],
                    'ico' => $m['ico'],
                    'lawyer' => $lawyer,
                    'lawyer_id' => $lawyerId,
                    'day' => $slot['day'],
                    'time' => $slot['time'],
                    'starts_at' => $startsAt,
                    'duration_min' => $duration,
                    'place' => $place,
                    'status' => 'مؤكد',
                    'tone' => 'b-green',
                    'when_kind' => 'up',
                ]);

                $consult->logAudit($consult->user?->name ?? 'العميل', 'الموعد', '—', $slot['day'].' · '.$slot['time']);
                $consult->update([
                    'appointment_id' => $appt->id,
                    'lawyer' => $lawyer,
                    'assigned_lawyer_id' => $lawyerId,
                    'day' => $slot['day'],
                    'time' => $slot['time'],
                    'starts_at' => $startsAt,
                    'duration_min' => $duration,
                    'when_label' => $slot['day'].' · '.$slot['time'],
                    'meet_id' => $zoom['id'] ?? null,
                    'meet_link' => $zoom['join_url'] ?? null,
                    'host_link' => $zoom['start_url'] ?? null,
                    'meet_password' => $zoom['password'] ?? null,
                    'status' => 'جديدة',
                    'audit' => $consult->audit,
                ]);

                Notify::send($consult->user_id, $m['ico'], 't-green', "تم تأكيد موعد استشارتك ({$m['label']}) {$slot['day']} الساعة {$slot['time']} — رقم الاستشارة {$consult->ref}.");
            });
        } catch (\Throwable $e) {
            // تنظيف اجتماع Zoom اليتيم إن أُنشئ ثمّ فشلت المعاملة (تعارض موعد مثلاً)
            if (! empty($zoom['id'])) {
                app(ZoomService::class)->deleteMeeting((string) $zoom['id']);
            }
            throw $e;
        }

        $consult->refresh();

        if ($consult->ticket) {
            $consult->ticket->update([
                'status' => 'موعد مؤكد',
                'tone' => TicketJourney::toneFor('موعد مؤكد'),
                'last_message' => "تم تأكيد موعد الجلسة: {$consult->when_label}",
                'date_label' => 'الآن',
            ]);
            Live::push(new TicketStatusBroadcast($consult->ticket));
        }

        self::sendBookingEmails($consult);

        GoogleCalendarService::syncConsult($consult);

        Live::push(new ConsultStatusBroadcast($consult));

        return $consult;
    }

    /**
     * مسار فوري «مدفوع مسبقاً» لحجز الموظف نيابةً عن العميل (بلا بوّابة دفع) — يُنشئ الحجز
     * كاملاً (Appointment + Consult + Zoom + فاتورة مدفوعة) بحالة «جديدة» مباشرةً.
     *
     * @param  array{type:string,day:string,time:string,place?:string,lawyer?:string,lawyer_id?:int,specialty?:string,starts_at?:string,duration?:int,subject?:string,department?:string}  $data
     */
    public static function create(User $client, array $data, ?Ticket $ticket = null): Consult
    {
        $ctx = self::resolveContext($client, $data, $ticket);
        $m = $ctx['meta'];
        $place = ($data['type'] === 'office' && ! empty($data['place'])) ? $data['place'] : $m['place'];
        $startsAt = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $duration = (int) ($data['duration'] ?? LawyerAvailability::slotMinutes());
        $total = $ctx['price'] + $ctx['vat'];

        $zoom = $data['type'] === 'video'
            ? app(ZoomService::class)->createMeeting("استشارة {$ctx['ref']} — {$ctx['subject']}", $duration, false, $startsAt)
            : null;

        self::logMissingMeeting($data['type'] === 'video', $zoom, (string) $ctx['ref']);

        $consult = DB::transaction(function () use (
            $client, $ticket, $data, $ctx, $m, $place, $startsAt, $duration, $total, $zoom
        ) {
            if ($ctx['lawyerId'] && $startsAt) {
                self::guardNoConflict($ctx['lawyerId'], $startsAt, $duration);
            }

            $appt = Appointment::create([
                'user_id' => $client->id,
                'ticket_id' => $ticket?->id,
                'ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999),
                'type' => 'استشارة '.$m['label'],
                'ico' => $m['ico'],
                'lawyer' => $ctx['lawyer'],
                'lawyer_id' => $ctx['lawyerId'],
                'day' => $data['day'],
                'time' => $data['time'],
                'starts_at' => $startsAt,
                'duration_min' => $duration,
                'place' => $place,
                'status' => 'مؤكد',
                'tone' => 'b-green',
                'when_kind' => 'up',
            ]);

            $consult = Consult::create([
                'user_id' => $client->id,
                'ticket_id' => $ticket?->id,
                'appointment_id' => $appt->id,
                'ref' => $ctx['ref'],
                'subject' => $ctx['subject'],
                'status' => 'جديدة',
                'session' => 'بانتظار الجلسة',
                'type' => $ctx['type'],
                'specialty' => $ctx['specialty'],
                'priority' => 'متوسطة',
                'received_label' => 'الآن',
                'audit' => [['user' => 'النظام', 'field' => 'الاستقبال', 'before' => '—', 'after' => 'حجز مدفوع — '.$m['label'], 'time' => now()->format('Y/m/d h:i')]],
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
                'meet_password' => $zoom['password'] ?? null,
                'channel' => $m['label'],
                'lawyer' => $ctx['lawyer'],
                'assigned_lawyer_id' => $ctx['lawyerId'],
                'day' => $data['day'],
                'time' => $data['time'],
                'starts_at' => $startsAt,
                'duration_min' => $duration,
                'when_label' => $data['day'].' · '.$data['time'],
                'phone' => $data['type'] === 'phone' ? $client->phone : null,
                'price' => $ctx['price'],
                'vat' => $ctx['vat'],
                'total' => $total,
                'priced_at' => now(),
                'paid_at' => now(),
            ]);

            // فاتورة مدفوعة للحجز الفوري (اتساقاً مع احتساب الإيراد ومسار الفواتير)
            Invoice::create([
                'user_id' => $client->id,
                'consult_id' => $consult->id,
                'number' => InvoiceNumber::next(),
                'description' => "استشارة {$consult->ref} — {$m['label']}",
                'amount' => $total,
                'status' => 'مدفوعة',
                'tone' => 'b-green',
                'due_label' => '—',
                'paid' => true,
            ]);

            Notify::send($client->id, $m['ico'], 't-green', "تم تأكيد موعد استشارتك ({$m['label']}) {$data['day']} الساعة {$data['time']} — رقم الاستشارة {$consult->ref}.");

            return $consult;
        });

        // تقديم حالة التذكرة كما تفعل schedule() — بدونها تبقى «بانتظار حجز الاستشارة»
        // وهي ضمن AWAITING_OTHERS فلا يتقدّم بها الموظف، ومخرجها الوحيد مشروط بـticket_id.
        if ($ticket) {
            $ticket->update([
                'status' => 'موعد مؤكد',
                'tone' => TicketJourney::toneFor('موعد مؤكد'),
                'last_message' => "تم تأكيد موعد الجلسة: {$consult->when_label}",
                'date_label' => 'الآن',
            ]);
            Live::push(new TicketStatusBroadcast($ticket));
        }

        self::sendBookingEmails($consult);

        GoogleCalendarService::syncConsult($consult);

        // بثّ لحظي — شاشات المكتب المفتوحة (استقبال الاستشارات/الطلبات) كانت لا تعلم بالحجز الفوري
        Live::push(new ConsultStatusBroadcast($consult));

        return $consult;
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

        $lawyerUser = ! empty($data['lawyer_id'])
            ? User::find((int) $data['lawyer_id'])
            : ($ticket?->assigned_lawyer_id ? User::find($ticket->assigned_lawyer_id) : null);
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
    private static function guardNoConflict(int $lawyerId, Carbon $start, int $duration): void
    {
        $end = $start->copy()->addMinutes($duration);

        $conflict = Appointment::where('lawyer_id', $lawyerId)
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', $start->toDateString())
            ->lockForUpdate()
            ->get()
            ->contains(function (Appointment $a) use ($start, $end) {
                $aStart = $a->starts_at;
                $aEnd = $aStart->copy()->addMinutes((int) ($a->duration_min ?: 60));

                return $start->lt($aEnd) && $end->gt($aStart);
            });

        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'هذا الموعد محجوز لدى المستشار، فضلاً اختر موعداً آخر.',
            ]);
        }
    }

    /** بيانات العرض للنوع (label/ico/place الافتراضي). */
    public static function meta(string $type): array
    {
        return self::map()[$type] ?? self::map()['office'];
    }
}

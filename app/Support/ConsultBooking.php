<?php

namespace App\Support;

use App\Mail\ConsultBooked;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ZoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء حجز استشارة حقيقي (Appointment + Consult + Zoom للمرئية + إشعار) — مصدر موحّد
 * يستخدمه: الحجز من التذكرة، حجز العميل المباشر، وحجز الموظف نيابةً عن العميل.
 * الأسعار تُقرأ من إعدادات الإدارة (Setting) بدل تثبيتها بالكود.
 */
class ConsultBooking
{
    private const MAP = [
        'office' => ['label' => 'حضورية', 'ico' => 'office', 'branch' => 'الرياض — حي العليا'],
        'video' => ['label' => 'مرئية', 'ico' => 'video', 'branch' => 'اجتماع إلكتروني'],
        'phone' => ['label' => 'هاتفية', 'ico' => 'phone', 'branch' => 'مكالمة هاتفية'],
    ];

    /**
     * @param  array{type:string,day:string,time:string,branch?:string,lawyer?:string,lawyer_id?:int,specialty?:string,starts_at?:string,duration?:int,subject?:string,department?:string}  $data
     */
    public static function create(User $client, array $data, ?Ticket $ticket = null): Consult
    {
        $m = self::MAP[$data['type']];
        $branch = ($data['type'] === 'office' && ! empty($data['branch'])) ? $data['branch'] : $m['branch'];

        // حلّ المحامي: بالمعرّف إن مُرّر (الحجز الذكي)، وإلا من المحامي المسند للتذكرة المصدر (FK)،
        // وإلا بالاسم النصّي (توافق رجعي). ربط الـid دائماً حين يتوفّر لتفعيل العزل ومنع الحجز المزدوج.
        $lawyerUser = ! empty($data['lawyer_id'])
            ? User::find((int) $data['lawyer_id'])
            : ($ticket?->assigned_lawyer_id ? User::find($ticket->assigned_lawyer_id) : null);
        $lawyer = $lawyerUser?->name
            ?: (($data['lawyer'] ?? null) ?: ($ticket?->assigned_lawyer ?: 'المستشار القانوني'));
        $lawyerId = $lawyerUser?->id;

        $subject = $data['subject'] ?? $ticket?->type ?? 'استشارة قانونية';
        $specialty = $data['specialty'] ?? $lawyerUser?->department;
        // فرع المؤسسة لعزل رؤية الموظف (مصدر الحقيقة: فرع المحامي المسند، وإلا فرع التذكرة)
        $scopeBranch = $lawyerUser?->branch ?: $ticket?->branch;
        $startsAt = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $duration = (int) ($data['duration'] ?? LawyerAvailability::slotMinutes());

        $prices = Setting::consultPrices();
        $price = $prices[$data['type']];
        $vat = (int) round($price * $prices['vat'] / 100);
        $ref = 'CN-'.now()->format('Y').'-'.random_int(1000, 9999);

        // إنشاء اجتماع Zoom مجدول بالوقت (للمرئية) خارج المعاملة تفادياً لحبس القفل أثناء نداء الشبكة
        $zoom = $data['type'] === 'video'
            ? app(ZoomService::class)->createMeeting("استشارة {$ref} — {$subject}", $duration, false, $startsAt)
            : null;

        $dept = $data['department'] ?? $ticket?->department;

        $consult = DB::transaction(function () use (
            $client, $ticket, $data, $m, $branch, $lawyer, $lawyerId, $subject,
            $specialty, $scopeBranch, $startsAt, $duration, $price, $vat, $ref, $zoom, $dept
        ) {
            // منع الحجز المزدوج: حارس تعارض على مواعيد المحامي المتقاطعة زمنياً (ضمن قفل)
            if ($lawyerId && $startsAt) {
                self::guardNoConflict($lawyerId, $startsAt, $duration);
            }

            $appt = Appointment::create([
                'user_id' => $client->id,
                'ticket_id' => $ticket?->id,
                'ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999),
                'type' => 'استشارة '.$m['label'],
                'ico' => $m['ico'],
                'lawyer' => $lawyer,
                'lawyer_id' => $lawyerId,
                'day' => $data['day'],
                'time' => $data['time'],
                'starts_at' => $startsAt,
                'duration_min' => $duration,
                'branch' => $branch,
                'status' => 'مؤكد',
                'tone' => 'b-green',
                'when_kind' => 'up',
            ]);

            $consult = Consult::create([
                'user_id' => $client->id,
                'ticket_id' => $ticket?->id,
                'appointment_id' => $appt->id,
                'ref' => $ref,
                'subject' => $subject,
                'status' => 'جديدة',
                'type' => preg_replace('/^القسم\s+/u', '', (string) $dept) ?: 'عام',
                'specialty' => $specialty,
                'priority' => 'متوسطة',
                'received_label' => 'الآن',
                'audit' => [['user' => 'النظام', 'field' => 'الاستقبال', 'before' => '—', 'after' => 'حجز مدفوع — '.$m['label'], 'time' => 'الآن']],
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
                'meet_password' => $zoom['password'] ?? null,
                'channel' => $m['label'],
                'lawyer' => $lawyer,
                'assigned_lawyer_id' => $lawyerId,
                'day' => $data['day'],
                'time' => $data['time'],
                'starts_at' => $startsAt,
                'duration_min' => $duration,
                'when_label' => $data['day'].' · '.$data['time'],
                // فرع المؤسسة للعزل (لا موقع المكتب — الموقع يبقى في الموعد Appointment.branch)
                'branch' => $scopeBranch,
                'phone' => $data['type'] === 'phone' ? $client->phone : null,
                'price' => $price,
                'vat' => $vat,
                'total' => $price + $vat,
            ]);

            UserNotification::create([
                'user_id' => $client->id,
                'icon' => $m['ico'],
                'tone' => 't-green',
                'body' => "تم تأكيد موعد استشارتك ({$m['label']}) {$data['day']} الساعة {$data['time']} — رقم الاستشارة {$consult->ref}.",
                'time_label' => 'الآن',
                'is_read' => false,
            ]);

            return $consult;
        });

        // إشعار بريدي فوري بتأكيد الحجز (بعد اعتماد المعاملة) — بريد فقط، مطابور فلا يحبس الحجز
        if ($client->email) {
            Mail::to($client->email)->queue(new ConsultBooked($consult));
        }

        return $consult;
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

    /** بيانات العرض للنوع (label/ico/branch الافتراضي). */
    public static function meta(string $type): array
    {
        return self::MAP[$type] ?? self::MAP['office'];
    }
}

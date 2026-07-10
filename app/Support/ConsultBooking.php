<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ZoomService;

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
     * @param  array{type:string,day:string,time:string,branch?:string,lawyer?:string,subject?:string,department?:string}  $data
     */
    public static function create(User $client, array $data, ?Ticket $ticket = null): Consult
    {
        $m = self::MAP[$data['type']];
        $branch = ($data['type'] === 'office' && ! empty($data['branch'])) ? $data['branch'] : $m['branch'];
        $lawyer = ($data['lawyer'] ?? null) ?: ($ticket?->assigned_lawyer ?: 'المستشار القانوني');
        $subject = $data['subject'] ?? $ticket?->type ?? 'استشارة قانونية';

        $appt = Appointment::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket?->id,
            'ext_id' => 'AP-'.now()->format('y').'-'.random_int(1000, 9999),
            'type' => 'استشارة '.$m['label'],
            'ico' => $m['ico'],
            'lawyer' => $lawyer,
            'day' => $data['day'],
            'time' => $data['time'],
            'branch' => $branch,
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $prices = Setting::consultPrices();
        $price = $prices[$data['type']];
        $vat = (int) round($price * $prices['vat'] / 100);
        $ref = 'CN-'.now()->format('Y').'-'.random_int(1000, 9999);

        $zoom = $data['type'] === 'video'
            ? app(ZoomService::class)->createMeeting("استشارة {$ref} — {$subject}")
            : null;

        $dept = $data['department'] ?? $ticket?->department;

        $consult = Consult::create([
            'user_id' => $client->id,
            'ticket_id' => $ticket?->id,
            'appointment_id' => $appt->id,
            'ref' => $ref,
            'subject' => $subject,
            'status' => 'جديدة',
            'type' => preg_replace('/^القسم\s+/u', '', (string) $dept) ?: 'عام',
            'priority' => 'متوسطة',
            'received_label' => 'الآن',
            'audit' => [['user' => 'النظام', 'field' => 'الاستقبال', 'before' => '—', 'after' => 'حجز مدفوع — '.$m['label'], 'time' => 'الآن']],
            'meet_id' => $zoom['id'] ?? null,
            'meet_link' => $zoom['join_url'] ?? null,
            'host_link' => $zoom['start_url'] ?? null,
            'channel' => $m['label'],
            'lawyer' => $lawyer,
            'day' => $data['day'],
            'time' => $data['time'],
            'when_label' => $data['day'].' · '.$data['time'],
            'branch' => $data['type'] === 'office' ? $branch : null,
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
    }

    /** بيانات العرض للنوع (label/ico/branch الافتراضي). */
    public static function meta(string $type): array
    {
        return self::MAP[$type] ?? self::MAP['office'];
    }
}

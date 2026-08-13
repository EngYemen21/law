<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * مواعيد استشارة حقيقية للعميل التجريبي — تمرّ عبر نفس مسار الحجز الفعلي
 * (ConsultBooking::create نفسه المستخدَم لحجز الموظف نيابةً عن العميل)، فتُنشئ
 * Appointment + Consult + Invoice مدفوعة ومترابطة حقيقياً (لا صفوف خام منفصلة).
 */
class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        // العميل التجريبي مُعرَّف برقم الهوية الثابت في DatabaseSeeder (لا بالبريد — قد يتغيّر).
        $client = User::where('national_id', '1000000002')->where('role', Role::Client)->first();
        $jeddahLawyer = User::where('email', 'lawyer.jeddah@salasel.sa')->first();
        $dammamLawyer = User::where('email', 'lawyer.dammam@salasel.sa')->first();

        if (! $client || ! $jeddahLawyer || ! $dammamLawyer) {
            return;
        }

        // 1) استشارة مرئية قادمة (بعد يومين)
        $this->bookIfMissing($client, 'نزاع تجاري مع مورّد', function () use ($client, $jeddahLawyer) {
            $starts = Carbon::today()->addDays(2)->setTime(11, 0);

            return ConsultBooking::create($client, [
                'type' => 'video',
                'day' => $starts->format('Y-m-d'),
                'time' => $starts->format('H:i'),
                'starts_at' => $starts->toDateTimeString(),
                'lawyer_id' => $jeddahLawyer->id,
                'specialty' => 'القضايا التجارية',
                'subject' => 'نزاع تجاري مع مورّد',
            ]);
        });

        // 2) استشارة حضورية قادمة (بعد 5 أيام) — فرع الدمام
        $this->bookIfMissing($client, 'مراجعة عقد بيع عقاري', function () use ($client, $dammamLawyer) {
            $starts = Carbon::today()->addDays(5)->setTime(13, 0);

            return ConsultBooking::create($client, [
                'type' => 'office',
                'day' => $starts->format('Y-m-d'),
                'time' => $starts->format('H:i'),
                'starts_at' => $starts->toDateTimeString(),
                'lawyer_id' => $dammamLawyer->id,
                'branch' => 'فرع الدمام',
                'specialty' => 'العقارات',
                'subject' => 'مراجعة عقد بيع عقاري',
            ]);
        });

        // 3) استشارة هاتفية سابقة ومنتهية (قبل أسبوع) — لعرض حالة «منتهية» + الملخص + طباعة PDF
        $this->bookIfMissing($client, 'استفسار حول شرط جزائي في عقد', function () use ($client, $jeddahLawyer) {
            $starts = Carbon::today()->subDays(7)->setTime(10, 0);
            $consult = ConsultBooking::create($client, [
                'type' => 'phone',
                'day' => $starts->format('Y-m-d'),
                'time' => $starts->format('H:i'),
                'starts_at' => $starts->toDateTimeString(),
                'lawyer_id' => $jeddahLawyer->id,
                'specialty' => 'القضايا التجارية',
                'subject' => 'استفسار حول شرط جزائي في عقد',
            ]);

            $consult->update([
                'session' => 'منتهية',
                'status' => 'منتهية',
                'duration_label' => '18:42',
                'summary' => "ملخص استشارة — {$consult->ref}\n\nتمّت مراجعة الشرط الجزائي الوارد في العقد محل الاستفسار. الرأي القانوني: الشرط نافذ ما لم يثبت غبن فاحش، ويُنصح بتوثيق أي إخلال كتابياً قبل المطالبة به.",
            ]);

            return $consult;
        });
    }

    /** يحجز فقط إن لم توجد استشارة بنفس الموضوع لهذا العميل مسبقاً — يمنع تكرار البذر عند إعادة التشغيل. */
    private function bookIfMissing(User $client, string $subject, \Closure $book): void
    {
        // appointment_id غير فارغ تحديداً — يميّز استشارة محجوزة فعلياً هنا عن أي سجلّ آخر
        // (من بذور أخرى) قد يتشارك نصّ الموضوع مصادفة بلا حجز حقيقي.
        if (Consult::where('user_id', $client->id)->where('subject', $subject)->whereNotNull('appointment_id')->exists()) {
            return;
        }

        $book();
    }
}

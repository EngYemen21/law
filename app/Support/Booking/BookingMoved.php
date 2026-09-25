<?php

namespace App\Support\Booking;

use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Services\ZoomService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * تحريكُ موعدٍ محجوز — **الأفعال الملازمة في موضعٍ واحد**.
 *
 * لموعدٍ يتحرّك أربعةُ لوازم، وكلٌّ منها كان متروكاً لتذكّر كاتب المسار:
 *
 * 1. **مزامنة Zoom** بالوقت **والمدّة** الفعليّين.
 * 2. **تصفير `link_released_at`** (الاستشارات) — وإلّا أُقصي الصفّ من
 *    `zoom:release-links` إلى الأبد، فيبقى زرّ الدخول محكوماً بختمٍ بائت ولا يصل
 *    بريد «الرابط جاهز».
 * 3. **إعادة تسليح أختام التذكير** — وإلّا لم يصل الموعدَ الجديد تذكيرٌ أبداً.
 * 4. تحديث سجلّ الشرائح (يأتي في دفعة التزامن).
 *
 * **ولماذا صنفٌ لا سطورٌ متكرّرة:** ثلاثة مسارات كانت تُحرّك الوقت وتنسى بعضها.
 * وأوضحُها دلالةً `MeetInvitation`: تعليقٌ فيه يقول إن إعادة الإرسال «كانت تُبقي
 * الاجتماع على موعده القديم» — أي أن أحداً أصلح **نصف** العطل (جانب قاعدة البيانات)
 * وظنّه تامّاً، وبقي Zoom على الموعد القديم سنةً بعده. الجمعُ هنا يجعل نسيان أحدها
 * غير ممكن.
 *
 * وكلّ ما فيه أفضل-جهد: تعثّر Zoom لا يُسقط تحريك الموعد — المستخدم حرّك موعده فعلاً،
 * ومنعُه لأن مزوّداً خارجياً تعثّر أسوأ. لكنّه **لا يُبتلع**: يُسجَّل بمفتاحٍ مميَّز.
 */
class BookingMoved
{
    /**
     * **موعدٌ تحرّك** — يُنادى بعد حفظ الوقت الجديد على الكيان.
     *
     * ولا يقبل `null`: كان يقبله بمعنى «أُلغي»، فأخطأ خطأً خطيراً — إعادةُ جدولةٍ
     * بتاريخٍ نثريّ لا يُفكّ تُنتج `startsAt = null` وتعني «أُجّل بلا موعد»، لا
     * «أُلغي». فكان الاجتماع **يُحذف من Zoom**. كشفه حارسٌ قائم، وفَصلُ النيّتين
     * يمنع تكراره: لكلِّ معنىً دالّةٌ باسمه.
     */
    public static function apply(Model $entity, CarbonInterface $startsAt): void
    {
        $meetId = (string) ($entity->meet_id ?? '');

        if ($meetId !== '') {
            self::syncZoom($entity, $meetId, $startsAt);
        }

        // إعادة التسليح تقع دائماً — حتى بلا اجتماع Zoom، فالتذكيرات لا تخصّ Zoom
        self::rearm($entity);
    }

    private static function rearm(Model $entity): void
    {
        $attrs = self::markers($entity);

        if ($attrs !== []) {
            $entity->forceFill($attrs)->saveQuietly();
        }
    }

    /** **موعدٌ أُلغي** — يُحذف اجتماع Zoom وتُصفَّر الأختام. */
    public static function cancelled(Model $entity): void
    {
        $meetId = (string) ($entity->meet_id ?? '');

        if ($meetId !== '') {
            self::dropZoom($entity, $meetId);
        }

        self::rearm($entity);
    }

    /** مدّة الكيان **الحقيقيّة** — لا رقمٌ مثبَّت. */
    public static function durationOf(Model $entity): int
    {
        return match (true) {
            $entity instanceof Meeting => $entity->durationMinutes() ?: 60,
            $entity instanceof Consult => (int) ($entity->duration_min ?: 60),
            default => 60,
        };
    }

    private static function syncZoom(Model $entity, string $meetId, CarbonInterface $startsAt): void
    {
        $duration = self::durationOf($entity);

        $ok = app(ZoomService::class)->updateMeeting($meetId, [
            'start_time' => $startsAt->format('Y-m-d\TH:i:s'),
            'duration' => $duration,
            'topic' => (string) ($entity->title ?? $entity->subject ?? 'جلسة'),
        ]);

        if (! $ok) {
            // تباعُدٌ صامت: قاعدة البيانات على الموعد الجديد وZoom على القديم.
            // يُسجَّل بمفتاحٍ مميَّز كي يُلتقَط، فالمستخدم لن يكتشفه إلّا في الموعد.
            Log::warning('booking.zoom_desync', [
                'entity' => $entity::class,
                'ref' => $entity->ref ?? $entity->id,
                'meet_id' => $meetId,
                'starts_at' => $startsAt->toDateTimeString(),
                'duration' => $duration,
            ]);
        }
    }

    private static function dropZoom(Model $entity, string $meetId): void
    {
        // **يُحذف قبل تصفير `meet_id`** وإلّا بقي اجتماعٌ يتيم على الحساب لا مرجع له،
        // وتسجيله السحابيّ مُفعَّل، وأيّ ويبهوك متأخّر عنه يصير غير قابلٍ للتوجيه.
        if (! app(ZoomService::class)->deleteMeeting($meetId)) {
            Log::warning('booking.zoom_orphan', [
                'entity' => $entity::class,
                'ref' => $entity->ref ?? $entity->id,
                'meet_id' => $meetId,
            ]);
        }
    }

    /**
     * أختام التذكير وإطلاق الرابط — تُصفَّر ليُعاد احتسابها للموعد الجديد.
     *
     * **عامّةٌ عمداً:** هي المصدر الوحيد لـ«أيّ الأختام تتبع الموعد». `RescheduleConsult` يدمجها
     * في كتابته داخل المعاملة، وكانت قبلها تُكتب هناك نسخةً ثانية، و`updateHearing` نسخةً ثالثة.
     *
     * @return array<string,mixed>
     */
    public static function markers(Model $entity): array
    {
        return match (true) {
            $entity instanceof Consult => [
                // وأيّ مسارٍ يُحرّك الوقت بلا تصفير هذا الختم يُقصي الصفّ من
                // `zoom:release-links` بلا رجعة — فلا يصل الموعدَ الجديد رابطٌ ولا بريده.
                'link_released_at' => null,
                'reminder_24h_sent_at' => null,
                'reminder_30m_sent_at' => null,
            ],
            $entity instanceof Meeting => ['reminder_sent_at' => null],
            $entity instanceof CaseHearing => [
                'reminder_24h_sent_at' => null,
                'reminder_1h_sent_at' => null,
            ],
            default => [],
        };
    }
}

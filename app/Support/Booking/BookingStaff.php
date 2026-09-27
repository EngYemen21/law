<?php

namespace App\Support\Booking;

use App\Enums\Role;
use App\Models\User;
use App\Support\Notify;
use App\Support\Permissions;
use Illuminate\Support\Collection;

/**
 * **من يحجز مواعيد الاستشارات — مصدرٌ واحد.**
 *
 * الحجز بيد الطاقم (قرار المالك 2026-09-14): الإدارة العليا، والموظّف الذي يملك «جدولة
 * المواعيد» — وهي الصلاحيّة التي يشترطها مسار الحجز نفسه في `routes/web.php`. كان هذا
 * الاستعلام مكتوباً في مستمع السداد وحده، فلمّا أُعيدت جدولة استشارة لم يُنبَّه أحدٌ في المكتب
 * أنّ عليه حجز موعدٍ جديد: أُبلغ العميل وحده، وبقيت الاستشارة معلّقة حتى ينتبه أحد.
 */
final class BookingStaff
{
    /** الصلاحيّة التي تحجز الموعد — يشترطها مسار الحجز للموظّف. */
    public const PERMISSION = Permissions::SCHEDULE_APPOINTMENTS;

    /** @return Collection<int, User> */
    public static function recipients(): Collection
    {
        $admins = User::where('role', Role::Admin)->get();

        $employees = User::where('role', Role::Employee)->get()
            ->filter(fn (User $u) => $u->can(self::PERMISSION));

        return $admins->concat($employees)->unique('id')->values();
    }

    /** يُنبّه طاقم الحجز — عدا من فعل الفعلَ بنفسه: لا يُخبَر المرء بما فعله للتوّ. */
    public static function notify(string $icon, string $tone, string $message, ?int $exceptUserId = null): void
    {
        foreach (self::recipients() as $user) {
            if ($user->id !== $exceptUserId) {
                Notify::send($user->id, $icon, $tone, $message);
            }
        }
    }
}

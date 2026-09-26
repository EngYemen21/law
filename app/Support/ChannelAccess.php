<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;

/**
 * قاعدة تفويض موحّدة لقنوات البثّ الخاصة (نفس مبدأ عزل HTTP) — مصدر واحد قابل للاختبار.
 * تمنع اشتراك العميل بقناة داخلية، ومحامٍ غير مسنَد بسجلٍّ لا يخصّه.
 */
class ChannelAccess
{
    /** الموظف المخوّل: الإدارة وموظف المكتب مطلقاً، والمحامي لسجلّه المسند وحده. */
    public static function staffCanSee(User $user, object $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        // الموظّف يرى كلّ الملفّات (قرار إزالة الفروع) — **بصلاحيّة الكيان** التي تفتح له صفحته.
        // كان أيّ موظّفٍ يشترك في بثّ كلّ المحادثات ولو لم يملك صلاحيّة شاشتها.
        if ($user->role === Role::Employee) {
            $permission = self::employeePermissionFor($model);

            return $permission === null || $user->can($permission);
        }
        if ($user->role === Role::Lawyer) {
            return (int) ($model->assigned_lawyer_id ?? 0) === (int) $user->id;
        }

        return false;
    }

    /** الصلاحيّة التي تفتح للموظّف شاشة هذا الكيان — نفسُها في `routes/web.php`. */
    private static function employeePermissionFor(object $model): ?string
    {
        return match (true) {
            $model instanceof Ticket => 'إدارة التذاكر',
            $model instanceof LegalCase, $model instanceof Execution => 'إدارة القضايا والأتعاب',
            $model instanceof Consult => 'استقبال الاستشارات',
            default => null,
        };
    }

    /** العميل المالك أو موظف مخوّل — للقنوات المشتركة بين العميل وفريقه. */
    public static function ownerOrStaff(User $user, object $model): bool
    {
        return ($model->user_id ?? null) === $user->id || self::staffCanSee($user, $model);
    }

    /**
     * **من الطاقم يدخل غرفة الجلسة** — نفس `staffCanSee`، ويُضاف الموظّف الذي تفتح له صلاحيّة
     * «إجراء الجلسات المرئية» غرفةَ الاستشارة (`/employee/videoroom` يقبلها بديلاً عن «استقبال
     * الاستشارات»). بدونها يدخل الغرفة ولا يصله بثّها.
     */
    public static function roomStaff(User $user, object $model): bool
    {
        return self::staffCanSee($user, $model)
            || ($model instanceof Consult && $user->role === Role::Employee && $user->can('إجراء الجلسات المرئية'));
    }

    /** العميل المالك أو من يدخل الغرفة من الطاقم — قناة الغرفة المشتركة. */
    public static function roomMember(User $user, object $model): bool
    {
        return ($model->user_id ?? null) === $user->id || self::roomStaff($user, $model);
    }

    /**
     * بيانات عضو قناة الحضور (presence) لمنع الردّ المزدوج، أو null لمنع الانضمام.
     * نفس عزل الملاحظات الداخلية: العميل لا ينضم إطلاقاً (الحضور شأن داخلي).
     *
     * @return array{id:int,name:string,role:string}|null
     */
    public static function presenceMember(User $user, object $model): ?array
    {
        if (! self::staffCanSee($user, $model)) {
            return null;
        }

        return ['id' => (int) $user->id, 'name' => $user->name, 'role' => $user->role->label()];
    }
}

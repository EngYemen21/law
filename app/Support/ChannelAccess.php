<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * قاعدة تفويض موحّدة لقنوات البثّ الخاصة (نفس مبدأ عزل HTTP) — مصدر واحد قابل للاختبار.
 * تمنع اشتراك موظف/محامٍ من فرع آخر أو غير مسنَد بقناة عميل لا يخصّه (تسرّب عابر للفروع).
 */
class ChannelAccess
{
    /** الموظف المخوّل: الإدارة مطلقاً، المحامي لسجلّه المسند، الموظف لسجلّات فرعه. */
    public static function staffCanSee(User $user, object $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->role === Role::Lawyer) {
            return (int) ($model->assigned_lawyer_id ?? 0) === (int) $user->id;
        }
        if ($user->role === Role::Employee) {
            // بلا فرع = المجمّع المشترك قبل الإسناد — نفس قاعدة حراس HTTP (guardMeeting/guardConsult)؛
            // كان البثّ أشدّ من HTTP فيرى الموظف السجلّ ولا يصله تحديثه اللحظي
            return $model->branch === null || $model->branch === $user->branch;
        }

        return false;
    }

    /** العميل المالك أو موظف مخوّل — للقنوات المشتركة بين العميل وفريقه. */
    public static function ownerOrStaff(User $user, object $model): bool
    {
        return ($model->user_id ?? null) === $user->id || self::staffCanSee($user, $model);
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

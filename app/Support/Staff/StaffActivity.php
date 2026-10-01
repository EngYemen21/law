<?php

namespace App\Support\Staff;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Finance\StaffEarnings;
use App\Support\LawyerWorkload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * **ملفّ نشاط الموظّف** — نافذة «ملفّ النشاط» في تبويب الموظفين (قرار المالك 2026-10-01).
 *
 * حلّت محلّ «الملف الوظيفي» الذي كان يكرّر ما في الجدول ونموذج التعديل. كلّ رقمٍ هنا من مصدره
 * الواحد: الحِمل من `LawyerWorkload` (صفحة «المحامون»)، والمستحقّات من `StaffEarnings` («مستحقاتي»)،
 * والعمليّات من سجلّ التدقيق. فلا يختلف ما تعرضه النافذة عمّا تعرضه تلك الصفحات.
 */
final class StaffActivity
{
    /** عدد القيود في كلّ قائمة — آخرها أوّلاً. */
    public const RECENT = 10;

    /**
     * @return array{workload: array<string, int|string>|null, earnings: array<string, int|string>|null, actions: list<array<string, string|null>>, account: list<array<string, string|null>>}
     */
    public static function for(User $user): array
    {
        // الإدارة لا حِمل لها ولا مستحقّات — صلاحيّاتها كاملة وأجرها خارج دفتر الموظفين
        $staff = $user->isLawyer() || $user->isEmployee();

        return [
            'workload' => $staff ? LawyerWorkload::forMany([$user->id])[$user->id] : null,
            'earnings' => $staff ? self::earnings($user) : null,
            // ما فعله الموظّف نفسه
            'actions' => self::rows(AuditLog::query()->where('user_id', $user->id)),
            // ما أجراه غيره على حسابه: التسجيل، وتعديل الدور والصلاحيّات والأجر، والإيقاف والتفعيل.
            // قيود صاحب الحساب على نفسه (كدخوله، ويُقيَّد على حسابه) في «آخر عمليّاته» لا هنا
            'account' => self::rows(AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))),
        ];
    }

    /** @return array{monthLabel: string, monthEarned: int, monthPaid: int, balance: int} */
    private static function earnings(User $user): array
    {
        $e = StaffEarnings::for($user);

        return [
            // التسمية بدالّة `StaffEarnings` نفسها التي تسمّي الشهر في «مستحقاتي»
            'monthLabel' => StaffEarnings::monthLabel(CarbonImmutable::parse($e['month'].'-01')),
            'monthEarned' => (int) $e['totals']['monthEarned'],
            'monthPaid' => (int) $e['totals']['monthPaid'],
            'balance' => (int) $e['totals']['balance'],
        ];
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return list<array<string, string|null>>
     */
    private static function rows($query): array
    {
        $rows = [];
        foreach ($query->latest('id')->limit(self::RECENT)->get() as $l) {
            $rows[] = [
                'id' => (string) $l->id,
                'action' => $l->action,
                'description' => $l->description,
                'ref' => $l->auditable_ref,
                'by' => $l->user_name,
                'severity' => $l->severity,
                'at' => $l->created_at?->format('Y-m-d H:i'),
            ];
        }

        return $rows;
    }
}

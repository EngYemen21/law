<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LawyerSpecialties;
use App\Support\LegalCatalogue;
use App\Support\Specialties;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة المحامين (يطابق adLawyers) — من جدول users بدور lawyer + عدد التذاكر المحالة.
 * يعرض وضع التوزيع لكل محامٍ (تلقائي/يدوي) ويتيح تبديله عبر toggleMode.
 */
class LawyerController extends Controller
{
    public function index(): Response
    {
        // withCount بدل COUNT لكل محامٍ (N+1 → استعلام واحد)
        $lawyers = User::where('role', Role::Lawyer)
            // «نشطة» بنطاق التذاكر المفتوحة نفسه (`Ticket::open`) — كان يستثني «مكتملة» وحدها،
            // فتُعدّ المغلقة والمحوّلة إلى قضيّة أو تنفيذ حِملاً قائماً على المحامي
            ->withCount(['assignedTickets as active' => fn ($q) => $q->open()])
            // الأحدث أوّلاً كجدول الموظّفين المجاور (`StaffController`) — كان تصاعديّاً
            // فيظهر المحامي المضاف حديثاً في آخر صفّ، وشاشتان متجاورتان بسلوكين متعاكسين.
            ->orderByDesc('id')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                // تخصّصات المحامي في الكتالوج (قد تكون عدّة)، أو «كل الأقسام»
                'depts' => LawyerSpecialties::coversAll($u)
                    ? [Specialties::ALL_DEPARTMENTS]
                    : LegalCatalogue::departments(activeOnly: false)->whereIn('id', LawyerSpecialties::departmentIds($u))->pluck('name')->values()->all(),
                'active' => $u->active,
                // الموقوف لا يُسنَد إليه (`ActiveLawyer`) — تُظهره الشاشة كي لا يُظنّ متاحاً
                'suspended' => $u->status === 'suspended',
                'mode' => $u->distribution_mode === 'manual' ? 'يدوي' : 'تلقائي',
            ]);

        return Inertia::render('admin/lawyers', ['lawyers' => $lawyers]);
    }

    /**
     * تبديل وضع توزيع المحامي بين تلقائي (يدخل في محرك التوزيع العادل) ويدوي (مستثنى).
     * مخصّص للمحامين فقط؛ ضمن قفل صف لتجنّب تبديل متزامن.
     */
    public function toggleMode(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === Role::Lawyer, 422, 'تبديل وضع التوزيع مخصّص للمحامين فقط.');

        DB::transaction(function () use ($user): void {
            $locked = User::lockForUpdate()->find($user->id);
            $locked->update([
                'distribution_mode' => $locked->distribution_mode === 'manual' ? 'auto' : 'manual',
            ]);
        });

        $newMode = $user->fresh()->distribution_mode === 'manual' ? 'يدوي' : 'تلقائي';

        return back()->with('flash', "تم تبديل وضع التوزيع للمحامي {$user->name} إلى: {$newMode}.");
    }
}

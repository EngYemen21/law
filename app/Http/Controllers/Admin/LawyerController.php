<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerSpecialties;
use App\Support\LawyerWorkload;
use App\Support\LegalCatalogue;
use App\Support\Specialties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة المحامين (يطابق adLawyers) — من جدول users بدور lawyer + حِمله بأنواعه (`LawyerWorkload`).
 * يعرض وضع التوزيع لكل محامٍ (تلقائي/يدوي) ويتيح تبديله عبر toggleMode، وملفّه المفتوح عبر show.
 */
class LawyerController extends Controller
{
    public function index(): Response
    {
        // الأحدث أوّلاً كجدول الموظّفين المجاور (`StaffController`) — كان تصاعديّاً
        // فيظهر المحامي المضاف حديثاً في آخر صفّ، وشاشتان متجاورتان بسلوكين متعاكسين.
        $users = User::where('role', Role::Lawyer)->orderByDesc('id')->get();
        // الحِمل من المصدر الواحد الذي تقرؤه شاشة «التوزيع» — كانت هذه الصفحة تعدّ التذاكر وحدها
        $workload = LawyerWorkload::forMany($users->pluck('id')->map(fn ($id) => (int) $id)->all());

        $lawyers = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            // تخصّصات المحامي في الكتالوج (قد تكون عدّة)، أو «كل الأقسام»
            'depts' => LawyerSpecialties::coversAll($u)
                ? [Specialties::ALL_DEPARTMENTS]
                : LegalCatalogue::departments(activeOnly: false)->whereIn('id', LawyerSpecialties::departmentIds($u))->pluck('name')->values()->all(),
            // الموقوف لا يُسنَد إليه (`ActiveLawyer`) — تُظهره الشاشة كي لا يُظنّ متاحاً
            'suspended' => $u->status === 'suspended',
            'manual' => $u->distribution_mode === 'manual',
            'load' => $workload[(int) $u->id],
        ]);

        return Inertia::render('admin/lawyers', [
            'lawyers' => $lawyers,
            'weights' => LawyerWorkload::WEIGHTS,
        ]);
    }

    /**
     * **ملفّ المحامي المفتوح** — نافذة التفاصيل: أعماله المفتوحة بأنواعها مع روابطها، واجتماعاته القادمة،
     * ومهامه المتأخّرة. بتعريفات «المفتوح» نفسها التي يعدّ بها `LawyerWorkload`، فتطابق القوائمُ أرقامَ الجدول.
     */
    public function show(User $user): JsonResponse
    {
        abort_unless($user->role === Role::Lawyer, 404);
        $id = (int) $user->id;

        $tickets = Ticket::open()->where('assigned_lawyer_id', $id)->latest('id')->get(['number', 'type', 'subject', 'status'])
            ->map(fn (Ticket $t) => ['ref' => $t->number, 'title' => $t->subject ?: $t->type, 'status' => $t->status, 'href' => '/admin/tickets/'.rawurlencode($t->number)]);
        $cases = LegalCase::active()->where('assigned_lawyer_id', $id)->latest('id')->get(['number', 'type', 'status'])
            ->map(fn (LegalCase $c) => ['ref' => $c->number, 'title' => $c->type, 'status' => $c->status, 'href' => '/admin/cases/'.rawurlencode($c->number)]);
        $executions = Execution::whereNotIn('status', Execution::CLOSED_STATUSES)->where('assigned_lawyer_id', $id)->latest('id')->get(['number', 'subject', 'status'])
            ->map(fn (Execution $e) => ['ref' => $e->number, 'title' => $e->subject, 'status' => $e->status, 'href' => '/admin/execs?id='.rawurlencode($e->number)]);
        $consults = Consult::whereNotIn('status', Consult::CLOSED_STATUSES)->where('assigned_lawyer_id', $id)->latest('id')->get(['ref', 'subject', 'status'])
            ->map(fn (Consult $c) => ['ref' => $c->ref, 'title' => $c->subject, 'status' => $c->status, 'href' => '/admin/consult?ref='.rawurlencode((string) $c->ref)]);
        $meetings = Meeting::where('assigned_lawyer_id', $id)
            ->whereNotIn('status', [MeetingStatus::Ended->value, MeetingStatus::Cancelled->value, MeetingStatus::Missed->value])
            ->orderBy('starts_at')->get()
            ->filter(fn (Meeting $m) => $m->isUpcoming())
            ->map(fn (Meeting $m) => ['ref' => $m->ref, 'title' => $m->title, 'status' => $m->when_label ?: $m->status, 'href' => '/admin/meeting?id='.rawurlencode((string) $m->ref)])
            ->values();
        $overdue = Task::where('assigned_to', $id)->get()->filter(fn (Task $t) => $t->isOverdue())
            ->map(fn (Task $t) => ['ref' => $t->ref ?: '—', 'title' => $t->title, 'status' => $t->due_at?->toDateString() ?? '', 'href' => '/admin/tasks'])
            ->values();

        return response()->json([
            'id' => $id,
            'name' => $user->name,
            'load' => LawyerWorkload::forMany([$id])[$id],
            'groups' => [
                ['key' => 'tickets', 'label' => 'التذاكر', 'items' => $tickets->values()],
                ['key' => 'cases', 'label' => 'القضايا', 'items' => $cases->values()],
                ['key' => 'executions', 'label' => 'ملفّات التنفيذ', 'items' => $executions->values()],
                ['key' => 'consults', 'label' => 'الاستشارات', 'items' => $consults->values()],
                ['key' => 'meetings', 'label' => 'الاجتماعات القادمة', 'items' => $meetings],
                ['key' => 'overdueTasks', 'label' => 'المهام المتأخّرة', 'items' => $overdue],
            ],
            // روابط ما له صفحته: التعديل والأقسام في «فريق العمل»، والمستحقّات، وسجلّ نشاطه في سجلّ الرحلة
            'links' => [
                'staff' => '/admin/staff',
                'earnings' => "/admin/staff/{$id}/earnings",
                'activity' => "/admin/journey-transitions?actor_id={$id}",
            ],
        ]);
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

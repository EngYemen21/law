<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\LawyerInBranch;
use App\Support\TicketAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * توزيع التذاكر — الإدارة تُسند التذاكر النشطة إلى المحامين (يكتب assigned_lawyer_id فعلياً).
 * توفر إسناداً يدوياً (assign) وتلقائياً (auto) عبر محرك TicketAssignment (تخصّص + حمل + أقدمية + AI).
 */
class DistributeController extends Controller
{
    private const CLOSED = ['مكتملة', 'مغلقة'];

    public function index(): Response
    {
        $tickets = Ticket::with('user')->whereNotIn('status', self::CLOSED)->latest('id')->get()
            ->map(fn (Ticket $t) => array_merge($t->toEmployeeCard(), ['no' => $t->number]));

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            // الإدارة تُسند لمحامٍ نشط — Rule موحَّد يرفض غير المحامين والموقوفين. (الإدارة صلاحيات مطلقة فلا تقييد بالفرع)
            'lawyer_id' => ['required', 'integer', new LawyerInBranch],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);

        $ticket->update([
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'branch' => $lawyer->branch ?: $ticket->branch,
        ]);
        // انتشار المحامي/الفرع الجديد إلى استشارات التذكرة المفتوحة
        TicketAssignment::syncRelatedConsults($ticket->fresh());
        $ticket->messages()->create([
            'who' => 'note', 'name' => $request->user()->name, 'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة التذكرة إلى '.e($lawyer->name).'.</p>', 'time_label' => 'الآن',
        ]);

        return back()->with('flash', "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.");
    }

    /**
     * التوزيع التلقائي: يُسند المحرك العادل (TicketAssignment) كل التذاكر النشطة غير المسندة.
     * يعالج فقط assigned_lawyer_id = null؛ التذاكر المسندة سابقاً لا تُلمَس. محميٌّ بقفل الصفوف
     * لمنع التوزيع المتزامن من مديرَين، ويعيد التحقق بعد القفل لتخطّي ما قد أُسند أثناء الانتظار.
     */
    public function auto(Request $request): RedirectResponse
    {
        $assigned = 0;
        $skipped = 0;
        $actorName = $request->user()->name;

        DB::transaction(function () use (&$assigned, &$skipped, $actorName): void {
            // قفل دفعة التذاكر غير المسندة دفعة واحدة (لا قفل صف بمفرده في حلقة)
            $tickets = Ticket::whereNotIn('status', self::CLOSED)
                ->whereNull('assigned_lawyer_id')
                ->lockForUpdate()
                ->get();

            foreach ($tickets as $ticket) {
                // إعادة فحص بعد القفل: قد يكون أُسندت أثناء الانتظار (race) — لا خطأ، فقط تخطٍّ
                if ($ticket->fresh()->assigned_lawyer_id) {
                    $skipped++;

                    continue;
                }

                // pickLawyer/assign يستدعيان LegalAiService::chooseLawyer الذي له fallback حتمي
                // (LegalAiService.php يرجع أول المرشحين عند تعذّر AI) — لا يفشل ما دام هناك مرشّح.
                $lawyer = TicketAssignment::assign($ticket);

                if (! $lawyer) {
                    $skipped++;

                    continue;
                }

                // syncRelatedConsults يقرأ ticket->fresh() — ضمن نفس الاتصال داخل الـ transaction
                // لا deadlock (Laravel يستخدم savepoint للـ nested transactions على نفس الاتصال).
                TicketAssignment::syncRelatedConsults($ticket->fresh());

                $ticket->messages()->create([
                    'who' => 'note',
                    'name' => $actorName,
                    'role' => 'توزيع آلي',
                    'body' => '<p>توزيع تلقائي إلى '.e($lawyer->name).'.</p>',
                    'time_label' => 'الآن',
                ]);

                $assigned++;
            }
        });

        $msg = "تم توزيع {$assigned} تذكرة تلقائياً";
        $msg .= $skipped ? "، وتخطّي {$skipped} (مسندة سابقاً أو بلا محامٍ متاح)." : '.';

        return back()->with('flash', $msg);
    }
}

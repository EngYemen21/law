<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * يُرقّي «ملخص الملف» القالبي إلى تحليل حقيقي بالذكاء الاصطناعي بعد الإحالة.
 *
 * مرونة إنتاجية: عند تعذّر الـAI (نفاد حصّة/ازدحام) لا يُختلَق محتوى — يبقى النائب «بانتظار»،
 * وتُعيد المهمّة المحاولة تلقائياً حتى retryUntil (شفاء ذاتي) فتُرقّى فور عودة الحصّة؛
 * وعند استنفاد المدّة يُصعَّد الأمر لبشر (المحامي + موظفو الفرع).
 */
class GenerateTicketSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public bool $force = false
    ) {}

    // نافذة إعادة المحاولة: 24 ساعة (تتجاوز التجدّد اليومي لحصّة Gemini)
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(LegalAiService $ai): void
    {
        $ticket = $this->ticket->fresh();
        if ($ticket === null) {
            return;
        }

        $summary = $ticket->summary;
        // لا نكتب فوق ملخّص اعتمده المحامي، ولا على تذكرة منتهية
        if ($summary === null
            || $summary->approved_at !== null
            || ($summary->ai_generated && ! $this->force)
            || in_array($ticket->status, ['مكتملة', 'مغلقة'], true)) {
            return;
        }

        // قاطع الدائرة: مزوّد الـAI مهدّأ الآن (نفاد حصّة) → أعد المحاولة لاحقاً بلا استهلاك نداء
        if ($ai->isConfigured() && ! $ai->available()) {
            $this->release(now()->addMinutes(30));

            return;
        }

        // summarize() يبني على تحليلات المستندات الفعلية + المحادثة
        $parts = $ai->summarize($ticket);

        if ($parts['ai_generated'] ?? false) {
            // نجح التحليل الحقيقي — شفاء تمّ
            $summary->update([
                'case_summary' => $parts['case_summary'],
                'attachments_summary' => $parts['attachments_summary'],
                'facts' => $parts['facts'],
                'key_points' => $parts['key_points'],
                'ai_generated' => true,
            ]);

            return;
        }

        // فشل الآن وأصبح المزوّد مهدّأً (السبب حصّة) → أعد المحاولة لاحقاً (النائب «بانتظار» يبقى)
        if ($ai->isConfigured() && ! $ai->available()) {
            $this->release(now()->addMinutes(30));

            return;
        }

        // لا مفتاح مُهيّأ أصلاً، أو فشل غير عابر → يبقى النائب الأمين بلا محاولات لا نهائية.
    }

    /**
     * استُنفدت نافذة إعادة المحاولة والـAI ما زال متعذّراً → تصعيد بشري (لا شيء عالق بصمت).
     */
    public function failed(\Throwable $e): void
    {
        $ticket = $this->ticket->fresh();
        if ($ticket === null) {
            return;
        }

        $summary = $ticket->summary;
        // نجح متأخّراً أو أُغلقت التذكرة → لا تصعيد
        if ($summary === null || $summary->ai_generated || $summary->approved_at !== null
            || in_array($ticket->status, ['مكتملة', 'مغلقة'], true)) {
            return;
        }

        // (1) مهمّة يدوية للمحامي المسند + إشعاره
        if ($ticket->assigned_lawyer_id) {
            Task::create([
                'assigned_to' => $ticket->assigned_lawyer_id,
                'title' => "إعداد ملخّص ملف يدوياً — تعذّر التحليل الذكي ({$ticket->number})",
                'ref' => $ticket->number,
                'due' => 'اليوم',
                'due_at' => now()->toDateString(),
                'status' => 'مفتوحة',
                'tone' => 'b-red',
            ]);
            Notify::send($ticket->assigned_lawyer_id, 'scale', 't-red', "تعذّر التحليل الذكي لملخّص التذكرة {$ticket->number} — يلزم إعداد الملخّص يدوياً.");
        }

        // (2) إشعار موظفي فرع التذكرة بالمشكلة (لا موظف مثبّت لكل تذكرة، فيُخطَر موظفو الفرع)
        if ($ticket->branch) {
            $employees = User::where('role', Role::Employee)->where('branch', $ticket->branch)->get();
            foreach ($employees as $employee) {
                Notify::send($employee->id, 'user', 't-amber', "تعذّر التحليل الذكي لملخّص التذكرة {$ticket->number} — يلزم متابعة يدوية.");
            }
        }

        Log::warning("AI summary escalated to humans after retry window ({$ticket->number}) — lawyer + branch employees notified for manual preparation.");
    }
}

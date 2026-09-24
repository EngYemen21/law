<?php

namespace App\Support;

use App\Domain\Journey\Workflow;
use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فتح طلب تنفيذ من قضية بلغت «صدر الحكم» أو من تذكرة استقر مسارها على التنفيذ.
 */
class ExecutionCreation
{
    public static function isEligible(LegalCase $case): bool
    {
        // «مغلقة» أيضاً (قرار المالك 2026-09-11): الإغلاق بعد الحكم كان يمنع فتح التنفيذ
        // نهائياً، ونصُّه يقول «بعد صدور الحكم وتنفيذه» ولو لم يُفتح تنفيذ. والمؤرشفة لا.
        return in_array($case->status, ['صدر الحكم', 'مغلقة'], true) && ! $case->execution()->exists();
    }

    public static function fromCase(LegalCase $case, User $actor): Execution
    {
        // محامي التنفيذ: محامي القضية إن كان حساباً حقيقياً، وإلا المحامي الذي فتح الطلب
        $lawyer = $case->assignedLawyer ?? $actor;

        /*
         * **جوهر القضيّة ينتقل مع ملفّها إلى التنفيذ.**
         *
         * كان النسخ يقتصر على الموضوع ونوع السند، فيفتح المحامي ملفّاً يُطلب منه تسعيره
         * وفيه «قيمة المطالبة 0» و«المنفَّذ ضده —» — والبيانات موجودةٌ كاملةً في تذكرة
         * القضيّة (المبلغ والخصم والمحكمة) وفي حقل الحكم. فتُنقل كما هي.
         *
         * **ولا يُختلق ما ليس موجوداً**: كلّ حقلٍ يُنسخ بشرط وجوده، وما نقص يبقى فارغاً
         * كما كان — لا صفراً مُدَّعىً ولا اسماً مُستنتَجاً.
         */
        $ticket = $case->ticket;
        $ruling = trim((string) $case->ruling);
        $court = trim((string) ($ticket?->court_name ?? ''));

        $notes = trim(implode("\n", array_filter([
            $ruling !== '' ? 'منطوق الحكم في القضية '.$case->number.': '.$ruling : '',
            $court !== '' ? 'المحكمة التي أصدرت الحكم: '.$court : '',
        ])));

        $exec = DB::transaction(function () use ($case, $lawyer, $ticket, $notes, $actor) {
            $number = ReferenceNumber::next(Execution::class, 'number', 'EXE');

            // يُفتح داخل المحرّك: سطرُ فتحٍ في سجلّ الانتقالات بالفاعل ومصدره
            $exec = Workflow::open('exec.open_from_case', fn () => Execution::create([
                'user_id' => $case->user_id,
                'client_code' => 'CL-'.str_pad((string) $case->user_id, 6, '0', STR_PAD_LEFT),
                'case_id' => $case->id,
                'ticket_id' => $ticket?->id,
                'number' => $number,
                'subject' => 'تنفيذ حكم — '.$case->type,
                'sanad' => 'حكم قضائي',
                'defendant' => (string) ($ticket?->opponent_name ?? ''),
                'amount' => (int) ($ticket?->claim_amount ?? 0),
                'notes' => $notes,
                'docs' => $case->documents->map(fn ($d) => (string) ($d->doc_type ?: $d->name))->filter()->unique()->values()->all(),
                'assigned_lawyer' => $lawyer->name,
                'assigned_lawyer_id' => $lawyer->id,
                'decision' => 'مقبول',
                'stage' => 3,
                'status' => ExecFlow::label(3),
                'tone' => ExecFlow::tone(3),
                'last_action' => 'فتح طلب التنفيذ بعد صدور الحكم — بانتظار تحديد الأتعاب',
            ]), $actor, ['case' => $case->number]);

            $exec->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'فتح',
                'body' => '<p>تم فتح طلب تنفيذ الحكم الصادر في القضية '.e($case->number).'، وإسناده إلى قسم التنفيذ ('.e($lawyer->name).') — بانتظار تحديد أتعاب التنفيذ.</p>',
                'time_label' => self::clock(),
            ]);

            return $exec;
        });

        AnalyzeExecutionJob::dispatch($exec)->afterCommit();

        // رسالة داخل محادثة القضية يراها العميل (بثّ تلقائي عبر CaseMessage)
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $actor->name, 'role' => 'تنفيذ',
            'body' => "<p>تم فتح طلب تنفيذ الحكم رقم <b>{$exec->number}</b>. يمكنكم متابعته من قسم «طلبات التنفيذ».</p>",
            'time_label' => self::clock(),
        ]);

        Notify::send($case->user_id, 'exec', 't-blue', "تم فتح طلب تنفيذ الحكم {$exec->number} لقضيتك {$case->number}، وسيصلك عرض أتعاب التنفيذ. تابعه من «طلبات التنفيذ».");

        if ((int) $lawyer->id !== (int) $actor->id) {
            Notify::send($lawyer->id, 'exec', 't-blue', "أُسند إليك طلب تنفيذ الحكم {$exec->number} — بانتظار تحديد الأتعاب.");
            ExecService::mailAssignedLawyer($exec);
        }
        ExecService::notifyAdmins($exec, 't-blue', "فُتح طلب تنفيذ الحكم {$exec->number} من القضية {$case->number} — بانتظار تحديد الأتعاب واعتمادها.");

        Audit::log(
            action: 'تحويل قضية إلى تنفيذ',
            description: "فتح {$actor->name} ملف التنفيذ {$exec->number} من القضية {$case->number} وأُسند إلى {$exec->assigned_lawyer}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $exec,
            auditableRef: $exec->number,
            beforeState: ['القضية' => $case->number],
            afterState: ['ملف التنفيذ' => $exec->number, 'المحامي' => $exec->assigned_lawyer],
            user: $actor,
        );

        return $exec;
    }

    /**
     * فتح ملف تنفيذ قضائي مباشرة من التذكرة عند اعتماد مسار التنفيذ.
     */
    public static function fromTicket(Ticket $ticket, User $actor, ?string $reason = null): Execution
    {
        $lawyer = $ticket->assignedLawyer ?? (TicketAssignment::pickLawyer($ticket) ?? $actor);

        $notes = trim(implode("\n", array_filter([
            'طلب تنفيذ قضائي محال من التذكرة رقم '.$ticket->number,
            $reason ? 'سبب التوجيه والتنفيذ: '.$reason : '',
            $ticket->court_name ? 'المحكمة المختصة: '.$ticket->court_name : '',
        ])));

        $detectedSanad = $ticket->documents->pluck('doc_type')->first(fn ($t) => in_array($t, ExecFlow::SANADS, true));
        $sanad = $detectedSanad ?: 'سند تنفيذي';

        $exec = DB::transaction(function () use ($ticket, $lawyer, $notes, $actor, $reason, $sanad) {
            $number = ReferenceNumber::next(Execution::class, 'number', 'EXE');

            $exec = Workflow::open('exec.open_from_ticket', fn () => Execution::create([
                'user_id' => $ticket->user_id,
                'client_code' => 'CL-'.str_pad((string) $ticket->user_id, 6, '0', STR_PAD_LEFT),
                'ticket_id' => $ticket->id,
                'number' => $number,
                'subject' => 'تنفيذ سند — '.($ticket->subject ?: $ticket->type),
                'sanad' => $sanad,
                'defendant' => (string) ($ticket->opponent_name ?? ''),
                'amount' => (int) ($ticket->claim_amount ?? 0),
                'notes' => $notes,
                'docs' => $ticket->documents->map(fn ($d) => (string) ($d->doc_type ?: $d->name))->filter()->unique()->values()->all(),
                'assigned_lawyer' => $lawyer->name,
                'assigned_lawyer_id' => $lawyer->id,
                'decision' => 'مقبول',
                'stage' => 3,
                'status' => ExecFlow::label(3),
                'tone' => ExecFlow::tone(3),
                'last_action' => 'فتح طلب التنفيذ بعد اعتماد مسار التنفيذ — بانتظار تحديد الأتعاب',
            ]), $actor, array_filter(['ticket' => $ticket->number, 'reason' => $reason]));

            self::migrateTicketDocuments($exec, $ticket->documents);
            $exec->load('documents');

            $exec->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'فتح',
                'body' => '<p>تم فتح طلب التنفيذ رقم '.e($number).' المحال من التذكرة '.e($ticket->number).' وإسناده إلى قسم التنفيذ ('.e($lawyer->name).') — بانتظار تحديد الأتعاب.</p>',
                'time_label' => self::clock(),
            ]);

            return $exec;
        });

        AnalyzeExecutionJob::dispatch($exec)->afterCommit();

        /*
         * **الإعلام بعد ختم الحفظ.** هذا المسار يُنادى من داخل معاملة المحرّك
         * (`ApproveOutcomeTrack::applyExecution`)، وكان الإشعار **والبريد للمحامي** يخرجان قبل
         * ختمها: لو أُلغيت، يبقى بريدٌ عن ملفّ تنفيذٍ لا وجود له. والبريد لا يُسترجع.
         */
        DB::afterCommit(function () use ($ticket, $exec, $lawyer, $actor) {
            Notify::send($ticket->user_id, 'exec', 't-amber', "تم فتح ملف تنفيذ قضائي برقم {$exec->number} لطلبك {$ticket->number}، وسيتم تحديد الأتعاب وإشعارك.");

            if ((int) $lawyer->id !== (int) $actor->id) {
                Notify::send($lawyer->id, 'exec', 't-amber', "أُسند إليك ملف التنفيذ {$exec->number} (محال من التذكرة {$ticket->number}) — بانتظار تحديد الأتعاب.");
                ExecService::mailAssignedLawyer($exec);
            }
            ExecService::notifyAdmins($exec, 't-amber', "فُتح ملف التنفيذ {$exec->number} من التذكرة {$ticket->number} — بانتظار تحديد الأتعاب.");
        });

        Audit::log(
            action: 'تحويل تذكرة إلى تنفيذ',
            description: "فتح {$actor->name} ملف التنفيذ {$exec->number} من التذكرة {$ticket->number} وأُسند إلى {$exec->assigned_lawyer}.",
            category: 'تذاكر وتنفيذ',
            severity: 'warning',
            auditable: $exec,
            auditableRef: $exec->number,
            beforeState: ['التذكرة' => $ticket->number],
            afterState: ['ملف التنفيذ' => $exec->number, 'المحامي' => $exec->assigned_lawyer],
            user: $actor,
        );

        return $exec;
    }

    /**
     * نقل مستندات التذكرة إلى جدول مستندات التنفيذ بنسخ آمن للملفات المخزّنة.
     */
    private static function migrateTicketDocuments(Execution $exec, iterable $documents): void
    {
        $disk = Storage::disk('local');

        foreach ($documents as $td) {
            $path = (string) ($td->path ?? '');
            $newPath = null;

            if ($path !== '') {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $newFilename = Str::random(40).($ext ? ".{$ext}" : '');
                $targetPath = "exec-docs/{$exec->id}/{$newFilename}";

                try {
                    if ($disk->exists($path)) {
                        $disk->copy($path, $targetPath);
                        $newPath = $targetPath;
                    } else {
                        $newPath = $path;
                    }
                } catch (\Throwable) {
                    $newPath = $path;
                }
            }

            $exec->documents()->create([
                'label' => (string) ($td->name ?: ($td->doc_type ?: 'مستند التنفيذ')),
                'path' => $newPath,
                'mime' => $td->mime ?? 'application/pdf',
                'size' => (int) ($td->size ?? 0),
                'status' => 'مرفوع',
                'uploaded_at' => now(),
                'doc_type' => $td->doc_type ?? null,
                'summary' => $td->summary ?? null,
            ]);
        }
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}

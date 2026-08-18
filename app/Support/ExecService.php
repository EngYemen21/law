<?php

namespace App\Support;

use App\Events\ExecStatusBroadcast;
use App\Jobs\AnalyzeExecutionJob;
use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Services\MailService;
use App\Services\MoyasarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تنسيق تدفّق طلب التنفيذ التجاريّ (10 مراحل) — نظير دوالّ execSubmit/execAI/…/execClose في التصميم.
 * كلّ انتقال: يحرس المرحلة، يحدّث الأعمدة + الحالة/النغمة، يضيف رسالة، يُشعر العميل، ويبثّ لحظيّاً.
 */
class ExecService
{
    // ── التقديم + التحليل ──

    /** @param array{sanad:string,subject:string,defendant?:string,amount?:int,notes?:string} $data */
    public static function submit(User $client, array $data, array $files = []): Execution
    {
        $docs = ['السند التنفيذي', 'الهوية'];
        if (! empty($data['notes'])) {
            $docs[] = 'مستند داعم';
        }

        $exec = Execution::create([
            'user_id' => $client->id,
            'client_code' => 'CL-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT),
            'number' => 'EXE-'.now()->year.'-'.random_int(1000, 9999),
            'subject' => $data['subject'],
            'sanad' => $data['sanad'],
            'defendant' => $data['defendant'] ?? '',
            'amount' => (int) ($data['amount'] ?? 0),
            'notes' => $data['notes'] ?? '',
            'docs' => $docs,
            'stage' => 1, // «تحليل ذكي» — بانتظار مهمّة التحليل بالذكاء الاصطناعي
            'status' => ExecFlow::label(1),
            'tone' => ExecFlow::tone(1),
            'ai_done' => false,
            'last_action' => 'فتح الطلب — جارٍ التحليل الذكيّ للمستندات',
        ]);

        // حفظ الملفات المرفقة كمستندات رسمية لطلب التنفيذ
        $attachedNames = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $path = $file->store("executions/{$exec->id}", 'local');
                $origName = $file->getClientOriginalName();
                $attachedNames[] = $origName;
                $exec->documents()->create([
                    'label' => $origName,
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'status' => 'مرفوع',
                    'uploaded_at' => now(),
                ]);
            }
        }

        $attachMsg = ! empty($attachedNames)
            ? ' (مرفق: '.implode('، ', $attachedNames).')'
            : '';

        $exec->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>طلب تنفيذ '.e($exec->sanad).' — '.e($exec->subject).e($attachMsg).'.</p>',
            'time_label' => self::clock(),
        ]);

        // التحليل الذكيّ يجري بالخلفية (لا يُحبَس طلب التقديم) — LegalAiService::analyzeExecution
        AnalyzeExecutionJob::dispatch($exec);

        return $exec->refresh();
    }

    /**
     * يطبّق نتيجة التحليل الذكيّ (من LegalAiService::analyzeExecution): يحدّث الحقول، يقدّم المرحلة
     * (2 عند الاكتمال / 1 عند وجود نواقص)، يضيف رسالة، يُشعر العميل، ويبثّ. يستدعيها AnalyzeExecutionJob.
     *
     * @param  array{summary:string,missing:array<int,string>,procedures:array<int,string>}  $result
     */
    public static function applyAnalysis(Execution $exec, array $result): void
    {
        $missing = array_values($result['missing']);
        $procedures = array_values($result['procedures']);
        $summary = (string) $result['summary'];
        $complete = count($missing) === 0;

        $exec->update([
            'ai_done' => true, 'ai_summary' => $summary, 'ai_missing' => $missing, 'ai_procedures' => $procedures,
        ]);
        self::sync($exec, $complete ? 2 : 1, $complete ? 'اكتمل التحليل الذكيّ — بانتظار الدراسة' : 'التحليل الذكيّ: نواقص مطلوبة');

        $exec->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'تحليل',
            'body' => '<p>'.e($summary).'</p>'.($missing ? '<p><b>نواقص مطلوبة:</b> '.e(implode(' · ', $missing)).'</p>' : ''),
            'time_label' => self::clock(),
        ]);

        self::notify($exec, 'exec', 't-blue', "تم تحليل طلب التنفيذ {$exec->number} بالذكاء الاصطناعي.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    // ── الإدارة ──

    public static function refer(Execution $exec): void
    {
        self::guard($exec, [0, 1, 2], 'لا يمكن إحالة هذا الطلب في مرحلته الحالية.');
        self::sync($exec, 2, 'أُحيل الطلب لقسم التنفيذ');
        self::adminMsg($exec, 'إحالة', 'أُحيل الطلب إلى قسم التنفيذ للدراسة.');
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function approveFee(Execution $exec, ?int $adjustedFee = null): void
    {
        self::guard($exec, self::feeStages($exec), 'لا توجد أتعاب بانتظار الاعتماد.');
        $fee = $adjustedFee && $adjustedFee > 0 ? $adjustedFee : (int) $exec->fee;
        $exec->update(['fee' => $fee, 'vat' => (int) round($fee * 0.15), 'fee_approved' => true, 'offer_status' => null]);
        self::sync($exec, 5, 'اعتمدت الإدارة الأتعاب وأُرسل العرض');
        self::adminMsg($exec, 'اعتماد', 'اعتمدت الإدارة أتعاب التنفيذ وأُرسل العرض للعميل.');
        self::notify($exec, 'card', 't-blue', "عرض خدمة التنفيذ لطلبك {$exec->number} جاهز — بانتظار قبولك.");
        self::mail($exec, 'feeApproved');
        Live::push(new ExecStatusBroadcast($exec));
    }

    /**
     * تسعير الإدارة المباشر (execfeeset): الإدارة تحدّد الأتعاب وتعتمدها وترسل العرض في خطوة واحدة
     * (المراحل 2‑4 قبل الاعتماد، أو 5 لإعادة تسعير عرض رفضه/استفسر عنه العميل). الأتعاب النهائيّة
     * تُحسب في الواجهة (ثابت/نسبة) وتُمرَّر رقماً.
     */
    public static function setFee(Execution $exec, int $fee, string $duration, string $payMethod): void
    {
        self::guard($exec, self::feeStages($exec), 'لا يمكن تسعير الطلب في مرحلته الحالية.');
        $exec->update([
            'fee' => $fee, 'vat' => (int) round($fee * 0.15),
            'duration' => $duration ?: '30-45 يوم',
            'pay_method' => in_array($payMethod, ExecFlow::PAYM, true) ? $payMethod : ExecFlow::PAYM[0],
            'fee_approved' => true, 'offer_status' => null,
        ]);
        self::sync($exec, 5, 'حدّدت الإدارة الأتعاب واعتمدتها وأُرسل العرض');
        self::adminMsg($exec, 'تسعير', 'حدّدت الإدارة أتعاب التنفيذ واعتمدتها وأُرسل العرض للعميل.');
        self::notify($exec, 'card', 't-blue', "عرض خدمة التنفيذ لطلبك {$exec->number} جاهز — بانتظار قبولك.");
        self::mail($exec, 'feeApproved');
        Live::push(new ExecStatusBroadcast($exec));
    }

    /** مراحل التسعير المسموحة: 2‑4 عادةً، وتضمّ 5 أيضاً إن رفض العميل العرض أو استفسر عنه (لإعادة العرض). */
    private static function feeStages(Execution $exec): array
    {
        return in_array($exec->offer_status, ['مرفوض', 'استفسار'], true) ? [2, 3, 4, 5] : [2, 3, 4];
    }

    // ── المحامي ──

    public static function accept(Execution $exec): void
    {
        self::guard($exec, [2], 'لا يمكن قبول هذا الطلب في مرحلته الحالية.');
        abort_if($exec->decision === 'مرفوض', 422, 'هذا الطلب مرفوض بالفعل.');
        $exec->update(['decision' => 'مقبول']);
        self::sync($exec, 3, 'قبل المحامي الطلب — بانتظار تحديد الأتعاب');
        self::lawyerMsg($exec, 'قبول', 'قُبل الطلب، ويجري تحديد أتعاب التنفيذ.');
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function requestDocs(Execution $exec): void
    {
        // الاستقبال (0‑1) أو الدراسة (2) — قبل فتح الملفّ
        self::guard($exec, [0, 1, 2], 'لا يمكن طلب مستندات في مرحلته الحالية.');

        // مستندات مطلوبة من العميل: النواقص من التحليل الذكيّ إن وُجدت، وإلا قائمة افتراضيّة
        $labels = ! empty($exec->ai_missing) ? array_values($exec->ai_missing) : ['السند التنفيذي', 'الهوية الوطنية', 'مستند داعم'];
        foreach ($labels as $label) {
            $exec->documents()->firstOrCreate(['label' => (string) $label], ['status' => 'مطلوب']);
        }

        self::lawyerMsg($exec, 'نواقص', 'يرجى تزويدنا بمستندات إضافية لاستكمال دراسة الطلب.');
        self::notify($exec, 'upload', 't-amber', "طلب قسم التنفيذ مستندات إضافية على طلبك {$exec->number}.");
    }

    public static function reject(Execution $exec): void
    {
        self::guard($exec, [2, 3], 'لا يمكن رفض هذا الطلب في مرحلته الحالية.');
        $exec->update(['decision' => 'مرفوض']);
        self::lawyerMsg($exec, 'رفض', 'تعذّر قبول الطلب بعد الدراسة.');
        self::notify($exec, 'exec', 't-red', "تعذّر قبول طلب التنفيذ {$exec->number} بعد الدراسة.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function saveFee(Execution $exec, int $fee, string $duration, string $payMethod): void
    {
        self::guard($exec, [3], 'لا يمكن تحديد الأتعاب في مرحلته الحالية.');
        abort_if($exec->decision === 'مرفوض', 422, 'هذا الطلب مرفوض بالفعل.');
        $exec->update([
            'fee' => $fee, 'vat' => (int) round($fee * 0.15),
            'duration' => $duration ?: '30-45 يوم',
            'pay_method' => in_array($payMethod, ExecFlow::PAYM, true) ? $payMethod : ExecFlow::PAYM[0],
        ]);
        self::sync($exec, 4, 'أُرسلت الأتعاب لاعتماد الإدارة');
        self::lawyerMsg($exec, 'أتعاب', 'حُدّدت أتعاب التنفيذ وأُرسلت لاعتماد الإدارة.');
        Live::push(new ExecStatusBroadcast($exec));
    }

    // ── العميل ──

    public static function acceptOffer(Execution $exec): void
    {
        self::guard($exec, [5], 'لا يوجد عرض بانتظار القبول.');
        abort_unless($exec->fee_approved, 422, 'العرض غير معتمد بعد.');

        $number = 'INV-'.now()->year.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($exec, $number) {
            Invoice::create([
                'user_id' => $exec->user_id, 'exec_id' => $exec->id, 'number' => $number,
                'description' => 'أتعاب تنفيذ · '.$exec->number,
                'amount' => (int) $exec->fee + (int) $exec->vat,
                'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام',
                'due_at' => now()->addDays(3)->toDateString(), 'paid' => false,
            ]);
            $exec->update(['offer_status' => 'مقبول', 'invoice_no' => $number]);
        });
        self::sync($exec, 6, 'قبل العميل العرض وصدرت الفاتورة');
        self::notify($exec, 'card', 't-blue', "صدرت فاتورة أتعاب التنفيذ لطلبك {$exec->number} — بانتظار السداد.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function inquire(Execution $exec): void
    {
        self::guard($exec, [5], 'لا يوجد عرض للاستفسار عنه.');
        $exec->update(['offer_status' => 'استفسار']);
        $exec->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>لديّ استفسار حول عرض خدمة التنفيذ.</p>', 'time_label' => self::clock(),
        ]);
        self::notifyOffice($exec, 't-amber', "استفسار العميل حول عرض التنفيذ {$exec->number}.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function rejectOffer(Execution $exec): void
    {
        self::guard($exec, [5], 'لا يوجد عرض للرفض.');
        $exec->update(['offer_status' => 'مرفوض']);
        $exec->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => '<p>رفضتُ عرض خدمة التنفيذ.</p>', 'time_label' => self::clock(),
        ]);
        self::notifyOffice($exec, 't-red', "رفض العميل عرض خدمة التنفيذ {$exec->number}.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    /** يُشعر المحامي المسنَد بحدث من العميل يخصّ العرض؛ بلا إسناد يبقى مرئياً للإدارة عبر القائمة فقط. */
    private static function notifyOffice(Execution $exec, string $tone, string $body): void
    {
        if ($exec->assigned_lawyer_id !== null) {
            Notify::send($exec->assigned_lawyer_id, 'exec', $tone, $body);
        }
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة أتعاب التنفيذ (محروس بالمرحلة 6) ويعيد رابط الدفع أو null.
     * التأكيد يتمّ عبر webhook/callback → PaymentReconciler::settle → markPaid.
     */
    public static function initiatePayment(Execution $exec, string $callbackUrl): ?string
    {
        self::guard($exec, [6], 'لا يمكن السداد قبل قبول العرض.');

        $invoice = Invoice::where('exec_id', $exec->id)->where('paid', false)->latest('id')->first();

        return $invoice ? app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    /** تسوية سداد الأتعاب وفتح ملف التنفيذ (idempotent) — يستدعيها PaymentReconciler عند تأكيد ميسّر. */
    public static function markPaid(Execution $exec): void
    {
        $didPay = DB::transaction(function () use ($exec) {
            $locked = Execution::whereKey($exec->id)->lockForUpdate()->first();
            if ($locked === null || $locked->paid) {
                return false;
            }
            Invoice::where('exec_id', $locked->id)->where('paid', false)
                ->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);
            $locked->update([
                'paid' => true, 'paid_at' => now(),
                'exec_no' => random_int(70, 99).'-'.now()->year.'-تنفيذ',
                'offer_status' => 'مقبول',
            ]);

            return true;
        });

        if (! $didPay) {
            return;
        }

        $exec->refresh();
        self::sync($exec, 8, 'سُدّدت الأتعاب وفُتح ملف التنفيذ');
        $exec->procedures()->create(['title' => 'فتح ملف التنفيذ وتقديم الطلب إلكترونياً', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
        $exec->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => '<p>تم سداد أتعاب التنفيذ وفتح ملف التنفيذ رقم <b>'.e((string) $exec->exec_no).'</b>.</p>',
            'time_label' => self::clock(),
        ]);
        self::notify($exec, 'check', 't-green', "سُدّدت أتعاب التنفيذ وفُتح ملف التنفيذ {$exec->exec_no} لطلبك {$exec->number}.");
        self::mail($exec, 'paid');
        Live::push(new ExecStatusBroadcast($exec));
    }

    // ── إجراءات ما بعد فتح الملف (محامي/إدارة) ──

    public static function addProcedure(Execution $exec, string $title): void
    {
        self::guard($exec, [7, 8], 'يلزم فتح ملف التنفيذ أولاً.');
        $t = trim($title);
        abort_if($t === '', 422, 'أدخل وصف الإجراء.');
        $exec->procedures()->create(['title' => $t, 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
        $exec->update(['last_action' => $t]);
        self::lawyerMsg($exec, 'إجراء', 'إجراء تنفيذ جديد: '.$t.'.');
        self::notify($exec, 'exec', 't-blue', "تحديث على ملف تنفيذك {$exec->exec_no}: {$t}.");
        Live::push(new ExecStatusBroadcast($exec));
    }

    public static function requestCorr(Execution $exec): void
    {
        self::guard($exec, [7, 8], 'يلزم فتح ملف التنفيذ أولاً.');
        abort_if($exec->assigned_lawyer_id === null, 422, 'يلزم إسناد محامٍ للملف قبل طلب مخاطبة.');

        // إنشاء مخاطبة رسميّة حقيقيّة مرتبطة بملفّ التنفيذ (تُتابَع في وحدة المخاطبات)
        $client = $exec->user;
        $lawyer = $exec->assignedLawyer;
        if ($client) {
            $no = $exec->exec_no ?: $exec->number;
            CorrespondenceFlow::create($lawyer, $client, [
                'entity' => 'محكمة التنفيذ',
                'subject' => 'مخاطبة بخصوص ملفّ التنفيذ '.$no.' — '.$exec->subject,
                'body' => 'بالإشارة إلى ملفّ التنفيذ رقم '.$no.'، نطلب من الجهة اتّخاذ اللازم بخصوص: '.$exec->subject.'.',
                'execution_id' => $exec->id,
            ]);
        }

        $exec->procedures()->create(['title' => 'إنشاء مخاطبة رسميّة لمحكمة التنفيذ مرتبطة بالملفّ', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
        self::lawyerMsg($exec, 'مخاطبة', 'أُنشئت مخاطبة رسميّة لمحكمة التنفيذ مرتبطة بالملف.');
        $exec->update(['last_action' => 'طلب مخاطبة محكمة التنفيذ']);
    }

    public static function close(Execution $exec): void
    {
        self::guard($exec, [7, 8], 'لا يمكن إغلاق الملف في مرحلته الحالية.');
        self::sync($exec, 9, 'أُغلق ملف التنفيذ وأُرشف');
        self::adminMsg($exec, 'إغلاق', 'أُغلق ملف التنفيذ بعد استكمال الإجراءات وأُرشف.');
        self::notify($exec, 'check', 't-green', "أُغلق ملف التنفيذ {$exec->exec_no} لطلبك {$exec->number}.");
        self::mail($exec, 'closed');
        Live::push(new ExecStatusBroadcast($exec));
    }

    // ── مساعدات ──

    /**
     * @param  array<int>  $allowed
     *
     * يستخدم المرحلة الفعّالة: التنفيذات القديمة (stage=null) تُعامَل كملفّات مفتوحة (8) أو مغلقة (9)
     * وفق حالتها، فتقبل إجراءات ما بعد فتح الملف (إجراء/مخاطبة/إغلاق) دون أن تلتبس بالمراحل المبكّرة.
     */
    private static function guard(Execution $exec, array $allowed, string $message): void
    {
        if (! in_array($exec->effectiveStage(), $allowed, true)) {
            throw ValidationException::withMessages(['stage' => $message]);
        }
    }

    private static function sync(Execution $exec, int $stage, string $lastAction): void
    {
        $exec->update([
            'stage' => $stage,
            'status' => ExecFlow::label($stage),
            'tone' => ExecFlow::tone($stage),
            'last_action' => $lastAction,
        ]);
    }

    private static function adminMsg(Execution $exec, string $role, string $text): void
    {
        $exec->messages()->create(['who' => 'admin', 'name' => 'الإدارة العليا', 'role' => $role, 'body' => '<p>'.e($text).'</p>', 'time_label' => self::clock()]);
    }

    private static function lawyerMsg(Execution $exec, string $role, string $text): void
    {
        $exec->messages()->create(['who' => 'lawyer', 'name' => $exec->assigned_lawyer ?: 'قسم التنفيذ', 'role' => $role, 'body' => '<p>'.e($text).'</p>', 'time_label' => self::clock()]);
    }

    private static function notify(Execution $exec, string $icon, string $tone, string $body): void
    {
        Notify::send($exec->user_id, $icon, $tone, $body);
    }

    /** بريد أفضل-جهد لحدث على طلب/ملف التنفيذ — بجانب إشعار النظام، لا بدلاً عنه. */
    private static function mail(Execution $exec, string $event): void
    {
        if ($exec->user) {
            app(MailService::class)->send($exec->user, new ExecutionEventMail($exec, $event));
        }
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}

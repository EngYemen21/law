<?php

namespace App\Support\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * **مصروفات المكتب** — المصدر الواحد لتسجيلها واعتمادها ورفضها وإلغائها (المرحلة ب).
 *
 * قرار المالك (2026-09-29): يسجّل الموظّف بصلاحيّة «تسجيل المصروفات» فيبقى المصروف «بانتظار
 * الاعتماد»؛ وما تسجّله الإدارة معتمدٌ فوراً. والاعتماد يمنح سند الصرف (`PaymentVoucher`)، والمعتمد
 * يُلغى بسببٍ ولا يُحذف. وكلّ خطوةٍ في سجلّ التدقيق بفئة «مالية وفواتير».
 */
final class Expenses
{
    private const AUDIT_CATEGORY = 'مالية وفواتير';

    /**
     * @param  array{spent_on:string, category:string, description:string, amount:string|float|int, vat?:string|float|int|null, vendor?:string|null, paid_from:string, reference?:string|null}  $data
     */
    public static function record(User $by, array $data, ?UploadedFile $document = null): Expense
    {
        $expense = DB::transaction(function () use ($by, $data, $document) {
            $expense = Expense::create([
                'spent_on' => $data['spent_on'],
                'category' => ExpenseCategory::from($data['category']),
                'description' => $data['description'],
                'amount_halalas' => Money::halalas($data['amount']),
                'vat_halalas' => Money::halalas($data['vat'] ?? 0),
                'vendor' => $data['vendor'] ?? null,
                'paid_from' => $data['paid_from'],
                'reference' => $data['reference'] ?? null,
                'status' => ExpenseStatus::Pending,
                'created_by' => $by->id,
            ]);

            if ($document !== null) {
                $expense->update(['document_path' => $document->store("expenses/{$expense->id}")]);
            }

            // ما تسجّله الإدارة معتمدٌ فوراً — المسجِّل هو المعتمِد، فلا خطوة ثانية بلا معنى
            if ($by->isAdmin()) {
                self::markApproved($expense, $by);
            }

            return $expense;
        });

        Audit::log(
            action: 'تسجيل مصروف',
            description: "سجّل {$by->name} مصروف {$expense->category->label()} بـ".VoucherFormat::sar($expense->amount_halalas)." — {$expense->description}.",
            category: self::AUDIT_CATEGORY,
            auditable: $expense,
            auditableRef: $expense->voucher_no ?? '#'.$expense->id,
            afterState: ['الحالة' => $expense->status->label(), 'المبلغ' => VoucherFormat::sar($expense->amount_halalas)],
        );

        if ($expense->isPending()) {
            foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
                Notify::send($adminId, 'card', 't-amber', "مصروف بانتظار الاعتماد: {$expense->description} — ".VoucherFormat::sar($expense->amount_halalas).'، سجّله '.$by->name.'.');
            }
        }

        return $expense;
    }

    public static function approve(Expense $expense, User $admin): Expense
    {
        abort_unless($expense->isPending(), 422, 'لا يُعتمد إلا مصروفٌ بانتظار الاعتماد.');

        DB::transaction(fn () => self::markApproved($expense, $admin));

        Audit::log(
            action: 'اعتماد مصروف',
            description: "اعتمد {$admin->name} المصروف {$expense->voucher_no} — {$expense->description}.",
            category: self::AUDIT_CATEGORY,
            auditable: $expense,
            auditableRef: (string) $expense->voucher_no,
            beforeState: ['الحالة' => ExpenseStatus::Pending->label()],
            afterState: ['الحالة' => ExpenseStatus::Approved->label()],
        );
        self::notifyCreator($expense, $admin, 't-green', "اعتُمد مصروفك «{$expense->description}» وصدر له سند الصرف {$expense->voucher_no}.");

        return $expense;
    }

    public static function reject(Expense $expense, User $admin, string $reason): Expense
    {
        abort_unless($expense->isPending(), 422, 'لا يُرفض إلا مصروفٌ بانتظار الاعتماد.');

        $expense->update(['status' => ExpenseStatus::Rejected, 'reject_reason' => $reason]);

        Audit::log(
            action: 'رفض مصروف',
            description: "رفض {$admin->name} المصروف «{$expense->description}»: {$reason}",
            category: self::AUDIT_CATEGORY,
            severity: 'warning',
            auditable: $expense,
            auditableRef: '#'.$expense->id,
            beforeState: ['الحالة' => ExpenseStatus::Pending->label()],
            afterState: ['الحالة' => ExpenseStatus::Rejected->label(), 'السبب' => $reason],
        );
        self::notifyCreator($expense, $admin, 't-red', "رُفض مصروفك «{$expense->description}»: {$reason}");

        return $expense;
    }

    public static function void(Expense $expense, User $admin, string $reason): Expense
    {
        abort_unless($expense->isApproved(), 422, 'لا يُلغى إلا مصروفٌ معتمد.');

        $expense->update([
            'status' => ExpenseStatus::Voided,
            'voided_by' => $admin->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);

        Audit::log(
            action: 'إلغاء مصروف',
            description: "ألغى {$admin->name} المصروف {$expense->voucher_no} (".VoucherFormat::sar($expense->amount_halalas)."): {$reason}",
            category: self::AUDIT_CATEGORY,
            severity: 'warning',
            auditable: $expense,
            auditableRef: (string) $expense->voucher_no,
            beforeState: ['الحالة' => ExpenseStatus::Approved->label()],
            afterState: ['الحالة' => ExpenseStatus::Voided->label(), 'السبب' => $reason],
        );

        return $expense;
    }

    /**
     * قواعد إدخال المصروف — مصدرٌ واحد لشاشة الإدارة وصفحة الموظّف.
     *
     * @return array{0: array<string, list<mixed>>, 1: array<string, string>}
     */
    public static function rules(): array
    {
        return [[
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000000'],
            'vat' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lte:amount'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'paid_from' => ['required', Rule::in(array_keys(Expense::PAID_FROM))],
            'reference' => ['nullable', 'string', 'max:100'],
            'document' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'spent_on.before_or_equal' => 'تاريخ الصرف لا يكون في المستقبل.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'amount.decimal' => 'المبلغ بالريال بمنزلتين عشريّتين على الأكثر.',
            'vat.lte' => 'الضريبة جزءٌ من المبلغ فلا تتجاوزه.',
            'description.required' => 'اكتب بيان المصروف.',
            'document.mimes' => 'المرفق PDF أو صورة.',
        ]];
    }

    private static function markApproved(Expense $expense, User $admin): void
    {
        $expense->fill(['status' => ExpenseStatus::Approved, 'approved_by' => $admin->id, 'approved_at' => now()]);
        PaymentVoucher::issue($expense);
    }

    private static function notifyCreator(Expense $expense, User $actor, string $tone, string $text): void
    {
        if ($expense->created_by !== $actor->id) {
            Notify::send($expense->created_by, 'card', $tone, $text);
        }
    }
}

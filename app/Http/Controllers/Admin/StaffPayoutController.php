<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayoutKind;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\StaffPayout;
use App\Models\User;
use App\Support\Audit;
use App\Support\Finance\PaymentVoucher;
use App\Support\Finance\PaymentVoucherDocument;
use App\Support\Finance\StaffEarnings;
use App\Support\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * **مستحقّات الموظّف وسجلّ صرفه — درج الإدارة** (تبويب الموظّفين، صلاحيّة `إدارة الموظفين`).
 *
 * الحساب من `Finance\StaffEarnings` وحده — الدرج يعرض ما تعرضه صفحة «مستحقاتي» للموظّف نفسه.
 * والقيد يُسجَّل ويُلغى بسببٍ، ولا مسار لتعديله أو حذفه: الأثر الماليّ يبقى قابلاً للتتبّع.
 */
class StaffPayoutController extends Controller
{
    /** ملخّص المستحقّات + ملفّات الموظّف التي يُسجَّل عليها نصيب — لدرج الإدارة. */
    public function show(Request $request, User $user): JsonResponse
    {
        self::guardStaff($user);

        return response()->json(self::payload($user, $request->query('month')));
    }

    public function store(Request $request, User $user): JsonResponse
    {
        self::guardStaff($user);

        $data = $request->validate([
            'kind' => ['required', Rule::in(PayoutKind::values())],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'period' => ['required', 'date_format:Y-m'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'file_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.min' => 'مبلغ الصرف يجب أن يكون أكبر من صفر.',
            'period.date_format' => 'اختر الشهر الذي يخصّه الصرف.',
            'paid_at.before_or_equal' => 'تاريخ الصرف لا يكون في المستقبل.',
        ]);

        $kind = PayoutKind::from($data['kind']);
        $file = $kind->needsFile() ? self::fileOf($user, $kind, (int) ($data['file_id'] ?? 0), $data['period']) : null;
        abort_if($kind->needsFile() && $file === null, 422, 'اختر '.($kind === PayoutKind::CaseShare ? 'القضيّة' : 'ملفّ التنفيذ').' التي للموظّف نصيبٌ فيها.');

        // القيد وسند صرفه معاً — لا قيدَ بلا سند (دفتر سندات الصرف واحدٌ مع المصروفات)
        $payout = DB::transaction(fn () => PaymentVoucher::issue(StaffPayout::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'amount' => (int) $data['amount'],
            'period' => $data['period'],
            'case_id' => $file instanceof LegalCase ? $file->id : null,
            'execution_id' => $file instanceof Execution ? $file->id : null,
            'note' => $data['note'] ?? null,
            'paid_at' => $data['paid_at'] ?? today(),
            'recorded_by' => $request->user()->id,
        ])));

        Audit::log(
            action: 'تسجيل صرف لموظف',
            description: "سجّل {$request->user()->name} صرف {$payout->amount} ر.س ({$kind->label()}) لـ{$user->name} عن {$payout->period}.",
            category: 'مالية وفواتير',
            auditable: $payout,
            auditableRef: $user->name,
            afterState: ['البند' => $kind->label(), 'المبلغ' => $payout->amount, 'الشهر' => $payout->period, 'الملفّ' => $file?->number],
        );

        $payload = self::payload($user, $data['period']);
        // تنبيهٌ لا منع: قد يُصرف مقدَّماً أو يُسوّى ما استُحقّ قبل بداية السجلّ
        $balance = $payload['earnings']['totals']['byKind'][$kind->value]['balance'];

        return response()->json($payload + [
            'message' => 'سُجّل الصرف.',
            'warning' => $balance < 0 ? 'المصروف في هذا البند تجاوز المستحقّ المحسوب بـ'.number_format(-$balance).' ر.س — تأكّد أنّه مقصود.' : null,
        ]);
    }

    public function void(Request $request, User $user, StaffPayout $payout): JsonResponse
    {
        self::guardStaff($user);
        abort_unless($payout->user_id === $user->id, 404);
        abort_if($payout->isVoided(), 422, 'هذا القيد ملغى بالفعل.');

        $reason = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'اكتب سبب إلغاء القيد.', 'reason.min' => 'اكتب سبب الإلغاء بوضوح (5 أحرف على الأقل).'],
        )['reason'];

        $payout->update(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $request->user()->id]);

        Audit::log(
            action: 'إلغاء قيد صرف',
            description: "ألغى {$request->user()->name} قيد صرف {$payout->amount} ر.س ({$payout->kind->label()}) لـ{$user->name}: {$reason}",
            category: 'مالية وفواتير',
            severity: 'warning',
            auditable: $payout,
            auditableRef: $user->name,
            beforeState: ['الحالة' => 'ساري'],
            afterState: ['الحالة' => 'ملغى', 'السبب' => $reason],
        );

        return response()->json(self::payload($user, $payout->period) + ['message' => 'أُلغي القيد.']);
    }

    /** سند صرف القيد PDF — والملغى يُطبع بحالته وسببه. */
    public function voucher(User $user, StaffPayout $payout): Response
    {
        self::guardStaff($user);
        abort_unless($payout->user_id === $user->id && $payout->voucher_no !== null, 404);

        return PdfRenderer::render(PaymentVoucherDocument::forPayout($payout), $payout->voucher_no.'.pdf');
    }

    /** المستحقّات لموظّفٍ أو محامٍ وحدهما — لا عميل ولا إدارة. */
    private static function guardStaff(User $user): void
    {
        abort_unless($user->isLawyer() || $user->isEmployee(), 404);
    }

    /**
     * الملفّ الذي يخصّه صرف النصيب — من ملفّات الموظّف في حساب مستحقّاته نفسه (`StaffEarnings`): المسند
     * إليه الآن، أو ما حُصّل منه في عهده قبل أن يُسند إلى غيره. وما سواهما مرفوض.
     */
    private static function fileOf(User $user, PayoutKind $kind, int $id, string $period): LegalCase|Execution|null
    {
        $fileKind = $kind === PayoutKind::CaseShare ? 'case' : 'exec';
        $ours = collect(StaffEarnings::for($user, $period)['shares'])->contains(fn (array $r) => $r['kind'] === $fileKind && $r['id'] === $id);
        $model = $fileKind === 'case' ? LegalCase::class : Execution::class;

        return $ours ? $model::find($id) : null;
    }

    private static function payload(User $user, ?string $month): array
    {
        return [
            'earnings' => StaffEarnings::for($user, $month),
            'kinds' => array_map(fn (PayoutKind $k) => ['id' => $k->value, 'label' => $k->label(), 'needsFile' => $k->needsFile()], PayoutKind::cases()),
        ];
    }
}

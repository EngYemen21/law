<?php

namespace App\Http\Controllers\Admin;

use App\Events\CaseStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\UserNotification;
use App\Support\CaseJourney;
use App\Support\Live;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الإدارة العليا — تحديد أتعاب القضايا المحوّلة من الاستشارات وتفعيل سدادها (يطابق cfFees).
 */
class CaseController extends Controller
{
    private const VAT = 0.15;

    public function fees(): Response
    {
        $cases = LegalCase::with('user')->latest('id')->get()
            ->map(fn (LegalCase $c) => [
                'no' => $c->number,
                'type' => $c->type,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'lawyer' => $c->assigned_lawyer ?: '—',
                'status' => $c->status,
                'tone' => $c->tone,
                'fee' => $c->fee,
                'lawyerFee' => $c->lawyer_fee,
                'lawyerPct' => $c->lawyer_pct,
                'feeStatus' => $c->fee_status,
            ]);

        return Inertia::render('admin/casefees', ['cases' => $cases]);
    }

    // تحديد قيمة الأتعاب → القضية بانتظار سداد العميل
    public function setFee(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'بانتظار اعتماد الأتعاب', 422);

        $data = $request->validate([
            'fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lawyer_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $vat = (int) round($data['fee'] * self::VAT);
        $total = $data['fee'] + $vat;
        $pct = $data['lawyer_pct'] ?? 0;
        $lawyerFee = (int) round($data['fee'] * $pct / 100);

        $case->update([
            'fee' => $data['fee'],
            'lawyer_fee' => $lawyerFee,
            'lawyer_pct' => $pct,
            'fee_status' => 'pending_payment',
            'status' => 'بانتظار سداد الأتعاب',
            'tone' => CaseJourney::toneFor('بانتظار سداد الأتعاب'),
            'invoice_text' => "أتعاب القضية {$data['fee']} ر.س + ضريبة {$vat} = {$total} ر.س",
            'update_text' => 'حدّدت الإدارة الأتعاب، بانتظار سداد العميل لتفعيل القضية',
        ]);

        // فاتورة أتعاب حقيقية للعميل (يطابق cfInvoice)
        Invoice::create([
            'user_id' => $case->user_id,
            'case_id' => $case->id,
            'number' => 'INV-'.now()->year.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'description' => "أتعاب قضية {$case->number} — {$case->type}",
            'amount' => $total,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 14 يوماً',
            'paid' => false,
        ]);

        $lawyerLine = $pct > 0 ? " (نصيب المحامي {$lawyerFee} ر.س بنسبة {$pct}%)" : '';
        $case->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة العليا',
            'role' => 'أتعاب',
            'body' => "<p>تم تحديد أتعاب القضية بمبلغ <b>{$total} ر.س</b> (شامل الضريبة){$lawyerLine}. تُفعّل القضية بعد السداد.</p>",
            'time_label' => $this->clock(),
        ]);

        UserNotification::create([
            'user_id' => $case->user_id,
            'icon' => 'card',
            'tone' => 't-amber',
            'body' => "صدرت فاتورة أتعاب قضيتك {$case->number} بمبلغ {$total} ر.س. سدّدها لتفعيل القضية.",
            'time_label' => 'الآن',
            'is_read' => false,
        ]);
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // إشراف الإدارة على كل القضايا
    public function index(): Response
    {
        $cases = LegalCase::with('user')->latest('id')->get()->map(fn (LegalCase $c) => [
            'no' => $c->number,
            'client' => Ticket::maskClient($c->user?->name ?? ''),
            'type' => $c->type,
            'lawyer' => $c->assigned_lawyer ?: '—',
            'status' => $c->status,
            'tone' => $c->tone,
            'canClose' => $c->status === 'صدر الحكم',
        ]);

        return Inertia::render('admin/cases', ['cases' => $cases]);
    }

    // الإغلاق والأرشفة بعد الحكم (يطابق cfCloseCase)
    public function closeCase(LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'صدر الحكم', 422);

        $case->update([
            'status' => 'مغلقة',
            'tone' => CaseJourney::toneFor('مغلقة'),
            'update_text' => 'أُغلقت القضية وحُفظ كامل الملف في الأرشيف',
        ]);
        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'إغلاق',
            'body' => '<p>بعد صدور الحكم وتنفيذه، تحوّلت القضية إلى <b>مغلقة</b> وحُفظ كامل الملف في الأرشيف القانوني.</p>',
            'time_label' => $this->clock(),
        ]);
        UserNotification::create([
            'user_id' => $case->user_id, 'icon' => 'check', 'tone' => 't-green',
            'body' => "أُغلقت قضيتك {$case->number} وأُرشفت بعد اكتمال الإجراءات.",
            'time_label' => 'الآن', 'is_read' => false,
        ]);
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}

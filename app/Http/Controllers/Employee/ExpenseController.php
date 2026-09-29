<?php

namespace App\Http\Controllers\Employee;

use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\Finance\Expenses;
use App\Support\Finance\FinanceBoard;
use App\Support\Paginate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **مصروفات الموظّف** — بصلاحيّة «تسجيل المصروفات»: يسجّل مصروفاً فينتظر اعتماد الإدارة، ويرى ما
 * سجّله هو وحده بحالته (معتمدٌ بسند صرف، أو مرفوضٌ بسببه). القواعد كلّها في `Finance\Expenses`.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $mine = Expense::with(['creator:id,name', 'approver:id,name'])
            ->where('created_by', $request->user()->id)
            ->latest('id')->paginate(FinanceBoard::PER_PAGE);

        return Inertia::render('employee/expenses', [
            'rows' => Paginate::shape($mine, fn (Expense $e) => FinanceBoard::expenseRow($e)),
            'categories' => ExpenseCategory::options(),
            'paidFrom' => Expense::paidFromOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$rules, $messages] = Expenses::rules();
        $data = $request->validate($rules, $messages);
        Expenses::record($request->user(), $data, $request->file('document'));

        return back()->with('flash', 'سُجّل المصروف — بانتظار اعتماد الإدارة.');
    }

    /** مرفق المصروف لمن سجّله وحده. */
    public function document(Request $request, Expense $expense): StreamedResponse
    {
        abort_unless($expense->created_by === $request->user()->id, 403);
        abort_if($expense->document_path === null || ! Storage::exists($expense->document_path), 404);

        return Storage::download($expense->document_path);
    }
}

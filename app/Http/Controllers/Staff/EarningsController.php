<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\Finance\StaffEarnings;
use App\Support\Finance\StaffStatement;
use App\Support\PdfRenderer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * **«مستحقاتي» للمحامي والموظّف** — صفحةٌ واحدة لمسارَي الدورين (`/lawyer/earnings` · `/employee/earnings`).
 *
 * تعرض بيانات المستخدم الحاليّ وحده — لا تقبل مُعرِّف موظّف. والحساب من `Finance\StaffEarnings`.
 */
class EarningsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('earnings', [
            'earnings' => StaffEarnings::for($user, $request->query('month')),
            'base' => $user->role->prefix(),
        ]);
    }

    public function statement(Request $request): HttpResponse
    {
        $user = $request->user();
        $earnings = StaffEarnings::for($user, $request->query('month'));

        return PdfRenderer::render(StaffStatement::html($user, $earnings), "statement-{$earnings['month']}.pdf");
    }
}

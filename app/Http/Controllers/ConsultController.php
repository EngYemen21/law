<?php

namespace App\Http\Controllers;

use App\Models\Consult;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * استشارات العميل — «استشاراتي» (يطابق myConsultsView) وغرفة الجلسة المرئية.
 */
class ConsultController extends Controller
{
    // قائمة استشارات العميل الحالي
    public function index(Request $request): Response
    {
        $consults = Consult::with('user')
            ->where('user_id', $request->user()->id)
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toClientCard());

        return Inertia::render('myconsults', [
            'consults' => $consults,
        ]);
    }

    // غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (?ref=CN-…)
    public function room(Request $request): Response
    {
        $consult = Consult::with('user')->where('ref', $request->query('ref'))->firstOrFail();
        abort_unless($consult->user_id === $request->user()->id, 403);

        return Inertia::render('videoroom', [
            'consult' => $consult->toClientCard(),
            'selfName' => $request->user()->name,
        ]);
    }
}

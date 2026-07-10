<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\Ticket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أرشيف الاستشارات — الاستشارات المنتهية الحقيقية (تسجيلاتها وملخصاتها) بدل مصفوفة ARCHIVE.
 */
class ArchiveController extends Controller
{
    public function index(): Response
    {
        $rows = Consult::with('user')->where('status', 'منتهية')->latest('id')->get()
            ->map(fn (Consult $c) => [
                'ref' => $c->ref,
                'ctype' => 'استشارة '.$c->channel,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'date' => $c->when_label,
                'dur' => $c->duration_label ?: '—',
                'recording' => $c->meet_link,        // رابط تسجيل Zoom إن وُجد
                'hasSummary' => ! empty($c->summary),
            ]);

        return Inertia::render('admin/archive', ['rows' => $rows]);
    }
}

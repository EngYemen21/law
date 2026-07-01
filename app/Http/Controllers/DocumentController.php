<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    // مستندات العميل الحالي مقسّمة إلى صادرة (out) ومرفوعة (up)
    public function index(Request $request): Response
    {
        $documents = Document::where('user_id', $request->user()->id)
            ->latest('id')->get();

        return Inertia::render('documents', [
            'docsOut' => $documents->where('direction', 'out')->values()
                ->map(fn (Document $d) => $d->toCard()),
            'docsUp' => $documents->where('direction', 'up')->values()
                ->map(fn (Document $d) => $d->toCard()),
        ]);
    }
}

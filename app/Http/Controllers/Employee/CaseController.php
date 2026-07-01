<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دور الموظف (خدمة العملاء) مع القضايا — متابعة وتنسيق والتواصل مع العميل.
 */
class CaseController extends Controller
{
    public function index(): Response
    {
        $cases = LegalCase::with('user')->latest('id')->get()->map(fn (LegalCase $c) => [
            'no' => $c->number,
            'client' => \App\Models\Ticket::maskClient($c->user?->name ?? ''),
            'type' => $c->type,
            'lawyer' => $c->assigned_lawyer ?: '—',
            'status' => $c->status,
            'tone' => $c->tone,
            'next' => $c->next_hearing,
        ]);

        return Inertia::render('employee/cases', ['cases' => $cases]);
    }

    public function show(LegalCase $case): Response
    {
        $case->load(['user', 'hearings']);

        return Inertia::render('employee/case', [
            'case' => [
                'no' => $case->number, 'client' => \App\Models\Ticket::maskClient($case->user?->name ?? ''),
                'type' => $case->type, 'dept' => $case->department, 'lawyer' => $case->assigned_lawyer ?: '—',
                'status' => $case->status, 'tone' => $case->tone, 'next' => $case->next_hearing,
            ],
            'channel' => 'case.'.$case->id,
            'messages' => $case->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
        ]);
    }

    // ردّ خدمة العملاء للعميل داخل القضية (بثّ لحظي)
    public function reply(Request $request, LegalCase $case): \Illuminate\Http\Response
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $case->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'خدمة العملاء',
            'body' => nl2br(e($data['body'])), 'time_label' => $this->clock(),
        ]);

        return response()->noContent();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}

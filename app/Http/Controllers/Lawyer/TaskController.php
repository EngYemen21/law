<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مهام المحامي — بيانات حقيقية (جدول tasks) بدل مصفوفة useState الوهمية.
 * كل محامٍ يرى ويدير مهامه المسندة إليه فقط.
 */
class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $tasks = Task::where('assigned_to', $request->user()->id)->latest('id')->get()
            ->map(fn (Task $t) => $t->toData());

        return Inertia::render('lawyer/tasks', ['tasks' => $tasks]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'ref' => ['nullable', 'string', 'max:60'],
            'due' => ['nullable', 'string', 'max:60'],
        ]);

        // استحقاق حقيقي إن كان النص تاريخاً (حقل date بالواجهة) — النص الحرّ يبقى عرضاً فقط
        $due = trim($data['due'] ?? '');
        $dueAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;

        Task::create([
            'assigned_to' => $request->user()->id,
            'title' => $data['title'],
            'ref' => $data['ref'] ?? null,
            'due' => $due !== '' ? $due : null,
            'due_at' => $dueAt,
            'status' => 'مفتوحة',
            'tone' => 'b-amber',
        ]);

        return back();
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->assigned_to === $request->user()->id, 403);

        // لا دهس للاستحقاق («مكتملة» كانت تمحو الموعد الأصلي فيضيع أثر الالتزام) — طابع إنجاز حقيقي
        $task->update(['status' => 'منجزة', 'tone' => 'b-green', 'completed_at' => now()]);

        return back();
    }
}

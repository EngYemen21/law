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

        Task::create([
            'assigned_to' => $request->user()->id,
            'title' => $data['title'],
            'ref' => $data['ref'] ?? null,
            'due' => $data['due'] ?? null,
            'status' => 'مفتوحة',
            'tone' => 'b-amber',
        ]);

        return back();
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->assigned_to === $request->user()->id, 403);

        $task->update(['status' => 'منجزة', 'tone' => 'b-green', 'due' => 'مكتملة']);

        return back();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مهام الإدارة — إسناد مهام حقيقية للمحامين (يعيد استخدام موديل Task).
 */
class TaskController extends Controller
{
    public function index(): Response
    {
        $tasks = Task::with('assignee')->latest('id')->get()->map(fn (Task $t) => $t->toData());

        return Inertia::render('admin/tasks', [
            'tasks' => $tasks,
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'ref' => ['nullable', 'string', 'max:60'],
            'due' => ['nullable', 'string', 'max:60'],
        ]);
        // يُسند فقط لحساب محامٍ
        User::where('role', Role::Lawyer)->findOrFail($data['assigned_to']);

        // استحقاق حقيقي إن كان النص تاريخاً (حقل date بالواجهة) — النص الحرّ يبقى عرضاً فقط
        $due = trim($data['due'] ?? '');

        Task::create([
            'assigned_to' => $data['assigned_to'],
            'title' => $data['title'],
            'ref' => $data['ref'] ?? null,
            'due' => $due !== '' ? $due : null,
            'due_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
            'status' => 'مفتوحة',
            'tone' => 'b-amber',
        ]);

        return back()->with('flash', 'تم إسناد المهمة للمحامي.');
    }

    /**
     * إنجاز أي مهمة من شاشة الإدارة — إغلاق المحامي مقيَّد بمهامه هو، فكانت المهمة
     * المسندة لموظف أو إداري (أو ليتيمٍ غادر) لا تُغلق أبداً من أي شاشة.
     */
    public function complete(Task $task): RedirectResponse
    {
        if ($task->status !== Task::DONE) {
            $task->update(['status' => Task::DONE, 'tone' => 'b-green', 'completed_at' => now()]);
        }

        return back()->with('flash', 'أُنجزت المهمة.');
    }

    /** إعادة إسناد مهمة لمحامٍ آخر — لم يكن للمهمة أي إجراء بعد إنشائها. */
    public function reassign(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);
        User::where('role', Role::Lawyer)->findOrFail($data['assigned_to']);

        $task->update(['assigned_to' => $data['assigned_to']]);

        return back()->with('flash', 'أُعيد إسناد المهمة.');
    }
}

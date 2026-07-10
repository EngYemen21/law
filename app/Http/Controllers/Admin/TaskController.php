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

        Task::create([
            'assigned_to' => $data['assigned_to'],
            'title' => $data['title'],
            'ref' => $data['ref'] ?? null,
            'due' => $data['due'] ?? null,
            'status' => 'مفتوحة',
            'tone' => 'b-amber',
        ]);

        return back()->with('flash', 'تم إسناد المهمة للمحامي.');
    }
}

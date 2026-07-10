<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة الفروع (يطابق adBranches + addBranch).
 */
class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/branches', [
            'branches' => Branch::orderBy('id')->get()->map(fn (Branch $b) => $b->toCard()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('branches', 'name')],
            'city' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        Branch::create($data);

        return back()->with('success', 'تم إضافة الفرع');
    }
}

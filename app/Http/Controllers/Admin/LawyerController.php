<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة المحامين (يطابق adLawyers) — من جدول users بدور lawyer + عدد التذاكر المحالة.
 */
class LawyerController extends Controller
{
    public function index(): Response
    {
        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('id')->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'depts' => $u->department ? [$u->department] : [],
                'active' => Ticket::where('assigned_lawyer', $u->name)
                    ->where('status', '!=', 'مكتملة')->count(),
                'mode' => 'تلقائي',
            ]);

        return Inertia::render('admin/lawyers', ['lawyers' => $lawyers]);
    }
}

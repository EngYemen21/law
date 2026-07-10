<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * جدولة/حجز استشارة من الموظف نيابةً عن عميل — يحفظ حجزاً حقيقياً.
 * محامو فرع الموظف فقط (العملاء غير مقيّدين بفرع).
 */
class ScheduleController extends Controller
{
    use BranchScoped;

    public function index(): Response
    {
        return Inertia::render('employee/schedule', [
            'clients' => User::where('role', Role::Client)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'lawyers' => User::where('role', Role::Lawyer)->where('branch', $this->currentBranch())
                ->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'in:office,video,phone'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['required', 'string', 'max:32'],
            'lawyer' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);

        $client = User::where('role', Role::Client)->findOrFail($data['client_id']);
        $consult = ConsultBooking::create($client, $data);

        return back()->with('flash', "تم إنشاء حجز الاستشارة {$consult->ref} للعميل {$client->name}.");
    }
}

<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Concerns\BranchScoped;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\LawyerInBranch;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'in:office,video,phone'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            // المحامي اختياري؛ إن اختير يجب أن يكون نشطاً وضمن فرع الموظف (عزل بالفرع).
            'lawyer_id' => ['nullable', 'integer', new LawyerInBranch($this->currentBranch())],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);

        $client = User::where('role', Role::Client)->findOrFail($data['client_id']);
        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        $consult = ConsultBooking::create($client, [
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'lawyer_id' => $data['lawyer_id'] ?? null,
            'starts_at' => $startsAt->toDateTimeString(),
            'duration' => LawyerAvailability::slotMinutes(),
            'day' => $startsAt->format('Y-m-d'),
            'time' => $data['time'],
        ]);

        return back()->with('flash', "تم إنشاء حجز الاستشارة {$consult->ref} للعميل {$client->name}.");
    }
}

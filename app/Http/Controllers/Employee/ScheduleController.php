<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\ConsultBooking;
use App\Support\LawyerAvailability;
use Illuminate\Http\JsonResponse;
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
    public function index(): Response
    {
        return Inertia::render('employee/schedule', [
            'clients' => User::where('role', Role::Client)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'lawyers' => User::where('role', Role::Lawyer)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    /**
     * API: فترات محامٍ في يوم معيّن — يُعيد كل ساعة مع علامة مشغول/متاح.
     * GET /employee/schedule/slots?lawyer_id=X&date=YYYY-MM-DD
     */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $slots = LawyerAvailability::slotsFor((int) $data['lawyer_id'], $data['date']);

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'in:office,video,phone'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            // المحامي اختياري؛ إن اختير يجب أن يكون نشطاً (يمنع تمرير عميل/موظف/إداري).
            'lawyer_id' => ['nullable', 'integer', new ActiveLawyer],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);

        $client = User::where('role', Role::Client)->findOrFail($data['client_id']);
        $startsAt = Carbon::parse($data['date'].' '.$data['time']);

        if ($startsAt->isPast()) {
            $msg = 'لا يمكن اختيار موعد في الماضي، فضلاً اختر وقتاً لاحقاً.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 422);
            }

            return back()->withErrors(['time' => $msg]);
        }

        // ── فحص التعارض: هل المحامي مشغول في هذه الفترة؟ ──
        if (! empty($data['lawyer_id'])) {
            $busy = LawyerAvailability::isBusy(
                (int) $data['lawyer_id'],
                $startsAt,
                LawyerAvailability::slotMinutes()
            );

            if ($busy) {
                $msg = 'المحامي مشغول في هذا الوقت، يرجى اختيار وقت آخر أو محامٍ مختلف.';

                // طلبات AJAX (من المودال) ← JSON 422
                if ($request->expectsJson()) {
                    return response()->json(['message' => $msg], 422);
                }

                return back()->withErrors(['time' => $msg]);
            }
        }

        $consult = ConsultBooking::create($client, [
            'type' => $data['type'],
            'subject' => $data['subject'] ?? null,
            'lawyer_id' => $data['lawyer_id'] ?? null,
            'starts_at' => $startsAt->toDateTimeString(),
            'duration' => LawyerAvailability::slotMinutes(),
            'day' => $startsAt->format('Y-m-d'),
            'time' => $data['time'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ref' => $consult->ref]);
        }

        return back()->with('flash', "تم إنشاء حجز الاستشارة {$consult->ref} للعميل {$client->name}.");
    }
}

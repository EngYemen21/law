<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة العملاء (يطابق adClients) — من جدول users بدور client، مع إخفاء PII.
 */
class ClientController extends Controller
{
    public function index(): Response
    {
        $clients = User::where('role', Role::Client)
            ->withCount('tickets')
            ->orderBy('id')->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'id' => self::mask($u->national_id, 3),
                'mobile' => self::mask($u->phone, 2),
                'tickets' => $u->tickets_count,
                'status' => $u->isActive() ? 'نشط' : 'موقوف',
            ]);

        return Inertia::render('admin/clients', ['clients' => $clients]);
    }

    // إخفاء وسط القيمة (يطابق نمط التشفير في التصميم: 1•••••••234 / 05•••••12)
    private static function mask(?string $value, int $tail): string
    {
        $value = $value ?? '';
        if ($value === '') {
            return '—';
        }
        $head = mb_substr($value, 0, 2);
        $end = mb_substr($value, -$tail);

        return $head.'•••••'.$end;
    }
}

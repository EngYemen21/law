<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;

/**
 * دليل العملاء المسجّلين وملفاتهم الحقيقية (يطابق CLIENT_DIR في التصميم الأصلي)
 * — يُستخدم في دعوات الاجتماعات وإنشائها.
 */
class ClientDirectory
{
    public static function list(): array
    {
        return User::where('role', Role::Client)->get()->map(function (User $u) {
            $items = collect()
                ->merge(Ticket::where('user_id', $u->id)->pluck('number')->map(fn ($n) => $n.' — تذكرة'))
                ->merge(LegalCase::where('user_id', $u->id)->pluck('number')->map(fn ($n) => $n.' — قضية'))
                ->merge(Consult::where('user_id', $u->id)->pluck('ref')->map(fn ($n) => $n.' — استشارة'))
                ->values()->all();

            return ['id' => $u->id, 'name' => $u->name, 'items' => $items];
        })->values()->all();
    }
}

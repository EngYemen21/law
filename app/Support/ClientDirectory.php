<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * دليل العملاء المسجّلين وملفاتهم الحقيقية (يطابق CLIENT_DIR في التصميم الأصلي)
 * — يُستخدم في دعوات الاجتماعات وإنشائها.
 */
class ClientDirectory
{
    public static function list(): array
    {
        // تحميل مسبق: 4 استعلامات إجمالاً بدل 3N+1 (استعلام لكل عميل × 3)
        return User::where('role', Role::Client)
            ->with(['tickets:id,user_id,number', 'cases:id,user_id,number', 'consults:id,user_id,ref'])
            ->get()->map(function (User $u) {
                $items = collect()
                    ->merge($u->tickets->pluck('number')->map(fn ($n) => $n.' — تذكرة'))
                    ->merge($u->cases->pluck('number')->map(fn ($n) => $n.' — قضية'))
                    ->merge($u->consults->pluck('ref')->map(fn ($n) => $n.' — استشارة'))
                    ->values()->all();

                return ['id' => $u->id, 'name' => $u->name, 'items' => $items];
            })->values()->all();
    }
}

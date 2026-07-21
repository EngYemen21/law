<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;

/**
 * الإسناد الأول للتذكرة — الذكاء الاصطناعي هو المُسنِد الأساسي للمحامي المختص، ومنه يُختم فرع التذكرة.
 * منطق حتمي بالكامل يعمل ولو كان الذكاء الاصطناعي معطّلاً (اختبارات/غياب مزوّد): يفضّل مطابقة التخصّص
 * ثم الأقل حملاً ثم الأقدم. مصدر موحّد يُستدعى عند فتح التذكرة وعند الإحالة إن لزم.
 */
class TicketAssignment
{
    /** يُسنِد المحامي المختص ويختم فرع التذكرة (= فرع المحامي). يُعيد المحامي المسند أو null إن لا محامٍ. */
    public static function assign(Ticket $ticket): ?User
    {
        $lawyer = self::pickLawyer($ticket);
        if (! $lawyer) {
            return null;
        }

        $ticket->update([
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'branch' => $lawyer->branch ?: $ticket->branch,
        ]);

        return $lawyer;
    }

    /** يختار المحامي المختص: مجموعة المرشحين (تخصّص مطابق إن وُجد) مرتّبة حتمياً، ثم اختيار الذكاء الاصطناعي. */
    public static function pickLawyer(Ticket $ticket): ?User
    {
        // المحامون النشطون في وضع التوزيع التلقائي فقط (الـ manual يُسنَد يدوياً من Distribute)
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->where('distribution_mode', 'auto')
            ->get();
        if ($lawyers->isEmpty()) {
            return null;
        }

        // عدد التذاكر المفتوحة لكل محامٍ (لموازنة الحمل)
        $openCounts = Ticket::whereIn('assigned_lawyer_id', $lawyers->pluck('id'))
            ->whereNotIn('status', ['مكتملة', 'مغلقة'])
            ->selectRaw('assigned_lawyer_id, count(*) as c')
            ->groupBy('assigned_lawyer_id')
            ->pluck('c', 'assigned_lawyer_id');

        // اقصر المرشحين على المطابقين للتخصّص إن وُجدوا، وإلا الكل
        $dept = $ticket->department;
        $deptMatched = $dept ? $lawyers->where('department', $dept)->values() : collect();
        $pool = $deptMatched->isNotEmpty() ? $deptMatched : $lawyers;

        // ترتيب حتمي: الأقل حملاً ثم الأقدم معرّفاً
        $ordered = $pool->sortBy(fn ($u) => sprintf('%09d-%09d', (int) ($openCounts[$u->id] ?? 0), $u->id))->values();

        $id = app(LegalAiService::class)->chooseLawyer($ticket, $ordered);

        return $ordered->firstWhere('id', $id) ?? $ordered->first();
    }

    /**
     * انتشار تغيّر محامي التذكرة إلى استشاراتها المفتوحة (غير المنتهية): تحديث المحامي المسند
     * والفرع معاً حتى لا تبقى الاستشارة معزولة عند المحامي القديم بعد التحويل/إعادة الإسناد.
     * القضايا/التنفيذ تحتفظ بمحاميها بحسب التصميم.
     */
    public static function syncRelatedConsults(Ticket $ticket): void
    {
        if (! $ticket->assigned_lawyer_id) {
            return;
        }

        Consult::where('ticket_id', $ticket->id)
            ->where('session', '!=', 'منتهية')
            ->update([
                'assigned_lawyer_id' => $ticket->assigned_lawyer_id,
                'lawyer' => $ticket->assigned_lawyer,
                'branch' => $ticket->branch,
            ]);
    }
}

<?php

namespace App\Enums;

/**
 * **حالة المصروف** (`expenses.status`): يُسجّله الموظّف بصلاحيّة «تسجيل المصروفات» فيبقى «بانتظار
 * الاعتماد» حتى تعتمده الإدارة أو ترفضه؛ وما تسجّله الإدارة معتمدٌ فوراً. والمعتمد وحده يُحسب في
 * التقارير وله سند صرف؛ ويُلغى بسببٍ ولا يُحذف (كقيد صرف الموظّف).
 */
enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الاعتماد',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
            self::Voided => 'ملغى',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'b-amber',
            self::Approved => 'b-green',
            self::Rejected, self::Voided => 'b-red',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}

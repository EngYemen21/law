<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * فرع المكتب (يطابق BRANCHES في التصميم) — يُستخدم في تسجيل الموظفين والحجوزات الحضورية.
 */
class Branch extends Model
{
    /** الفرع الافتراضي (المقرّ الرئيسي) — يطابق أول فرع في BranchSeeder، ومرجعٌ واحد للاحتياط. */
    public const DEFAULT = 'الفرع الرئيسي — جدة';

    protected $fillable = ['name', 'city', 'phone'];

    /** اسم الفرع الافتراضي للختم: أول فرع فعلي إن وُجد، وإلا الثابت. */
    public static function defaultName(): string
    {
        return static::orderBy('id')->value('name') ?? self::DEFAULT;
    }

    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'phone' => $this->phone ?? '',
        ];
    }
}

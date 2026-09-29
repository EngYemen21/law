<?php

namespace App\Enums;

/**
 * **بند الصرف للموظّف** (`staff_payouts.kind`) — يقابل كلّ بندٍ مستحقّاً يحسبه `Finance\StaffEarnings`،
 * فيُطرح المصروف من مستحقّ بنده لا من مجموعٍ مبهم.
 */
enum PayoutKind: string
{
    case Salary = 'salary';
    case CaseShare = 'case_share';
    case ExecShare = 'exec_share';
    case Session = 'session';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'راتب',
            self::CaseShare => 'نصيب قضيّة',
            self::ExecShare => 'نصيب تنفيذ',
            self::Session => 'أجر جلسات',
        };
    }

    /** البند المرتبط بملفٍّ بعينه — يُسجَّل صرفه على قضيّةٍ أو ملفّ تنفيذ مسنَدٍ إلى الموظّف. */
    public function needsFile(): bool
    {
        return $this === self::CaseShare || $this === self::ExecShare;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}

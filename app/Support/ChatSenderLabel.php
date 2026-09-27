<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * **باسم مَن يرى العميلُ كلَّ رسالةٍ في محادثاته** — المصدر الواحد لصفحاته وللبثّ اللحظيّ،
 * في التذاكر والقضايا والتنفيذ. (طلب المالك 2026-09-25)
 *
 * كانت التسمية منقوشةً في موضعين: الخادم يختصر اسم المحامي ويبدّل اسم الموظّف (`LawyerName::inMessages`)،
 * وشاشة العميل (`ChatThread`) تتجاهل ما يصلها وتكتب «الفريق القانوني» و«خدمة العملاء» بيدها. فتغييرُ
 * تسميةٍ كان يلزمه نشرُ كود. الآن تضبطها الإدارة من الإعدادات (`SettingsRegistry`، مجموعة `chat`)،
 * ويقرّرها هذا الصنف وحده، والشاشة تعرض ما يصلها.
 *
 * **الدور من حساب المُرسِل (`sender_id`) لا من `who`.** ردُّ الإدارة على التذكرة يُخزَّن `who='lawyer'`،
 * فالحكم بالنصّ كان يعرضه للعميل باسم المدير مختصراً كأنّه محامٍ. و`who` احتياطٌ للرسائل القديمة
 * التي كُتبت قبل ربط الرسالة بحسابها.
 *
 * **للعميل وحده** (قرار المالك): الطاقم يرى الأسماء الحقيقيّة، ولا يُنادى هذا الصنف في حمولاتهم.
 */
final class ChatSenderLabel
{
    /** إعلانات المكتب الآليّة (سداد · تحويل · إغلاق): ليست شخصاً فلا تأخذ تسمية أحد، وهي ما كان يراه العميل. */
    public const OFFICE = 'الفريق القانوني';

    /** مفاتيح الحقول الأربعة في `SettingsRegistry`. */
    public const EMPLOYEE = 'chat_label_employee';

    public const LAWYER = 'chat_label_lawyer';

    public const ADMIN = 'chat_label_admin';

    public const AI = 'chat_label_ai';

    /** أدوار الحسابات في الطلب الواحد — قائمة المحادثة تكرّر المُرسِلين أنفسهم، فاستعلامٌ لكلّ حساب لا لكلّ رسالة. */
    private const ACCOUNTS = 'support.chat-sender-label.accounts';

    public static function forClient(?string $who, string $storedName, ?int $senderId): string
    {
        // رسالة العميل نفسه تبقى باسمه (وشاشته تكتب «أنت»)، والمخرج الآليّ باسم المساعد وحده بلا وسم.
        // والملاحظة الداخليّة لا تبلغ العميل أصلاً — تُبثّ على قناة الطاقم فتبقى باسم كاتبها
        if (in_array($who, ['client', 'me', 'note'], true)) {
            return $storedName;
        }
        if ($who === 'ai') {
            return SettingsRegistry::str(self::AI);
        }
        if ($who === 'system') {
            return self::OFFICE;
        }

        $account = $senderId !== null ? self::account($senderId) : null;

        if ($account !== null && ! $account->isClient()) {
            return self::forAccount($account);
        }

        return match (true) {
            // رسالةٌ بلا حساب (قديمة، أو كُتبت داخل مهمّة طابور): `who` هو كلّ ما نعرفه
            $who === 'lawyer' => self::unverifiedLawyer($storedName),
            $who === 'admin' => SettingsRegistry::str(self::ADMIN),
            default => SettingsRegistry::str(self::EMPLOYEE),
        };
    }

    /**
     * **باسم مَن يرى العميلُ حساباً من الطاقم** — القاعدة نفسها لرسائل المحادثة ولكلّ موضعٍ
     * يظهر فيه شخصٌ من المكتب أمام العميل بلا رسالة (اسمُ المشارك في غرفة Zoom المضمّنة).
     *
     * كانت الغرفة تمرّر `$user->name` خاماً فيرى العميل الاسم الكامل على مربّع المتحدّث، بينما
     * محادثته نفسها تسمّيه «محمد. ب» أو «خدمة العملاء». فالتسمية تُقرَّر هنا مرّةً ويقرؤها الموضعان.
     * والعميل نفسه يبقى باسمه — لا أحدَ يُخفى عنه اسمُه.
     */
    public static function forAccount(User $account): string
    {
        return match (true) {
            $account->isAdmin() => SettingsRegistry::str(self::ADMIN),
            $account->isLawyer() => self::lawyer((string) $account->name),
            $account->isEmployee() => SettingsRegistry::str(self::EMPLOYEE),
            default => (string) $account->name,
        };
    }

    /** البديل الذي أدخلته الإدارة إن وُجد — وإلّا الاسم المختصر «محمد. ب» (قرار المالك 2026-09-11). */
    private static function lawyer(string $name): string
    {
        // القاعدة الواحدة لاسم المحامي أمام العميل (`LawyerName::display`)؛ والاسم الفارغ اسمُ المكتب
        return trim($name) !== '' ? LawyerName::display($name) : (SettingsRegistry::str(self::LAWYER) ?: self::OFFICE);
    }

    /**
     * **الاسم المخزَّن نصّاً لا يُختصر إلّا إن كان اسمَ محامٍ فعلاً** — القاعدة نفسها في `LawyerName::forClient`.
     *
     * الحقل يحمل أحياناً تسميةً لا اسماً («قسم التنفيذ» من `ExecService::officeMsg`، أو «الإدارة العليا»
     * لملفٍّ مُصعَّد)، واختصارها يُنتج «قسم. ت»؛ ويحمل أحياناً اسمَ مديرٍ كتب بصفة محامٍ فيُختصر كأنّه
     * محامٍ. فالتسمية المعروفة تمرّ، واسمُ حسابٍ محامٍ مطابقٌ حرفاً يُختصر، وما سواهما اسمُ المكتب.
     */
    private static function unverifiedLawyer(string $storedName): string
    {
        $storedName = trim($storedName);

        if (in_array($storedName, LawyerName::PLACEHOLDERS, true)) {
            return $storedName;
        }

        $isLawyer = $storedName !== '' && User::where('name', $storedName)->where('role', Role::Lawyer)->exists();

        return $isLawyer ? self::lawyer($storedName) : self::OFFICE;
    }

    private static function account(int $id): ?User
    {
        $app = app();

        // `scoped` لا خاصّيّة ساكنة: تُنسى بين طلبين وبين اختبارين، فلا يحمل دورٌ قديم إلى غير أهله
        if (! $app->bound(self::ACCOUNTS)) {
            $app->scoped(self::ACCOUNTS, fn () => new \ArrayObject);
        }

        /** @var \ArrayObject<int, ?User> $accounts */
        $accounts = $app->make(self::ACCOUNTS);

        if (! $accounts->offsetExists($id)) {
            $accounts[$id] = User::find($id);
        }

        return $accounts[$id];
    }
}

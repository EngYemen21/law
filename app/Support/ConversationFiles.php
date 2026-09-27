<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\ExecutionDocument;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **مرفقاتُ المحادثات تُنزَّل — لكلّ طرفٍ في الملفّ، ولا لغيره.**
 *
 * كانت شارةُ المرفق في محادثات التذكرة والقضيّة والتنفيذ نصّاً بلا رابط
 * (`<span class="doc-chip">📎 الاسم</span>`) لكلّ الأدوار: يرى الطرفُ أنّ مستنداً أُرفق
 * ولا يفتحه. وكانت مسارات التنزيل مبعثرةً بحسب الدور والنوع (`documents.download-file`
 * للعميل، `lawyer.documents.download` للمحامي، `exec-flow…/download` للتنفيذ)، والموظّف
 * بلا مسار أصلاً.
 *
 * **قرار المالك (2026-09-11):** المحامي والإدارة العليا والموظّف والعميل يُنزّلون المرفقات —
 * وهو ينقض قراراً سابقاً كان يحجبها عن الموظّف. فالقاعدة هنا واحدةٌ لكلّ الأنواع:
 *
 * - **الإدارة العليا:** دائماً (إشرافٌ، كـ`Gate::before`).
 * - **العميل:** صاحبُ الملفّ وحده.
 * - **المحامي:** المسنَدُ إليه الملفّ. وفي التنفيذ غيرُ المسنَد يمرّ بصلاحيّة الملفّات —
 *   كما في `ExecFlowController::downloadDocument` القائم.
 * - **الموظّف:** بالصلاحيّة نفسها التي تفتح له المحادثة (`إدارة التذاكر` للتذكرة،
 *   و`إدارة القضايا والأتعاب` للقضيّة والتنفيذ) — ومن لا يراها لا يُنزّله. **وفوقها**
 *   «تنزيل مرفقات الملفات» (قرار 2026-09-18): رؤية المحادثة لا تعني تنزيل مرفقاتها.
 */
final class ConversationFiles
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'ticket' => TicketDocument::class,
        'case' => CaseDocument::class,
        'exec' => ExecutionDocument::class,
    ];

    public static function find(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type]::findOrFail($id);
    }

    /** الملفّ الذي ينتمي إليه المستند (تذكرة/قضيّة/تنفيذ). */
    public static function parentOf(Model $doc): ?Model
    {
        return match (true) {
            $doc instanceof TicketDocument => $doc->ticket,
            $doc instanceof CaseDocument => $doc->legalCase,
            $doc instanceof ExecutionDocument => $doc->execution,
            default => null,
        };
    }

    public static function canDownload(User $user, Model $doc): bool
    {
        $parent = self::parentOf($doc);
        if ($parent === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $assigned = $parent->assigned_lawyer_id !== null ? (int) $parent->assigned_lawyer_id : null;

        return match ($user->role) {
            Role::Client => (int) $parent->user_id === (int) $user->id,
            Role::Lawyer => $assigned === (int) $user->id
                || ($doc instanceof ExecutionDocument && $assigned === null && $user->can(Permissions::MANAGE_CASES_AND_FEES))
                // محامي القضيّة المحوَّلة من هذه التذكرة: مرفقاتُ الطلب أساسُ لائحته، وإعادةُ إسناد
                // القضيّة لا تمسّ محامي التذكرة — فكان المسنَد الجديد يفقدها
                || ($doc instanceof TicketDocument && (int) ($parent->legalCase?->assigned_lawyer_id ?? 0) === (int) $user->id),
            Role::Employee => self::employeeMayDownload($user)
                && $user->can($doc instanceof TicketDocument ? Permissions::MANAGE_TICKETS : Permissions::MANAGE_CASES_AND_FEES),
            default => false,
        };
    }

    /**
     * **شرط الموظّف الإضافيّ لأيّ تنزيل** — صلاحيّة «تنزيل مرفقات الملفات» (قرار المالك 2026-09-18).
     * كانت صلاحيّة القسم وحدها تفتح مرفقات المكتب كلّه؛ فصار التنزيل قدرةً تمنحها الإدارة وتسحبها.
     * ويناديه كلّ مسار تنزيلٍ للموظّف (هنا و`ExecFlowController::downloadDocument`) فلا يفترقان.
     */
    public static function employeeMayDownload(User $user): bool
    {
        return $user->can(Permissions::DOWNLOAD_FILES);
    }

    /** اسم الملفّ كما رُفع — عمود `label` في التنفيذ و`name` في غيره. */
    public static function nameOf(Model $doc): string
    {
        return (string) ($doc instanceof ExecutionDocument ? $doc->label : $doc->name);
    }

    public static function url(string $type, int $id): string
    {
        return route('files.download', ['type' => $type, 'id' => $id]);
    }

    /**
     * شارةُ المرفق في رسالة المحادثة — رابطٌ واحدٌ لكلّ الأدوار، والمسارُ يحكم الإذن.
     * تُكتب في الرسالة عند الإرفاق، فتصل رابطاً حتى في البثّ اللحظيّ.
     */
    public static function chip(string $type, Model $doc): string
    {
        return self::anchor(self::url($type, (int) $doc->getKey()), e(self::nameOf($doc)));
    }

    /**
     * **الرسائلُ القديمة تُربط عند العرض لا بإعادة الكتابة.** شاراتُها `<span>` بلا معرّف
     * مستند، فتُطابَق بالاسم داخل الملفّ نفسه — **إن كان الاسمُ لمستندٍ واحد**. اسمٌ مكرَّر
     * لا يُخمَّن له رابط (قد يفتح غيرَ المقصود)، ويبقى نصّاً كما كان.
     *
     * لا تمسّ إلا شارات 📎: الشاراتُ الأخرى (المستندات المطلوبة، نوع المستند…) ليست ملفّات.
     *
     * @param  array<int, array<string, mixed>>  $messages  مخرجات `toMessage()`
     * @param  iterable<Model>  $docs  مستندات الملفّ نفسه
     * @return array<int, array<string, mixed>>
     */
    public static function linkLegacyChips(array $messages, string $type, iterable $docs): array
    {
        $byName = [];
        foreach ($docs as $doc) {
            if ($doc->path === null) {
                continue;
            }
            $byName[e(self::nameOf($doc))][] = (int) $doc->getKey();
        }

        if ($byName === []) {
            return $messages;
        }

        foreach ($messages as $i => $message) {
            $messages[$i]['text'] = preg_replace_callback(
                '/<span class="doc-chip">📎 (.*?)<\/span>/u',
                function (array $m) use ($byName, $type) {
                    $ids = $byName[$m[1]] ?? [];

                    return count($ids) === 1 ? self::anchor(self::url($type, $ids[0]), $m[1]) : $m[0];
                },
                (string) ($message['text'] ?? '')
            );
        }

        return $messages;
    }

    /** `$escapedName` مُهرَّبٌ مسبقاً — لا يُهرَّب مرّتين. */
    private static function anchor(string $url, string $escapedName): string
    {
        return '<a class="doc-chip doc-chip-link" href="'.e($url).'" download title="تنزيل المستند">📎 '.$escapedName.'</a>';
    }
}

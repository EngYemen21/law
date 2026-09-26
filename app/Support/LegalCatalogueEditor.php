<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\LegalDepartmentDocument;
use App\Models\LegalService;
use App\Models\StaffDepartment;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * **تعديل كتالوج الأقسام والخدمات والأقسام الإداريّة** — كلّ ما تكتبه شاشة الإدارة يمرّ من هنا.
 *
 * لماذا صنفٌ مستقلّ لا منطقٌ في المتحكّم: إعادة التسمية ليست `update` على صفّ. قرار المالك
 * (2026-09-14) أن يظهر الاسم الجديد **في كلّ مكان** — فتُحدَّث في معاملةٍ واحدة:
 *   - نصوص التذاكر والقضايا والاستشارات والمحامين المرتبطة بالمعرّف (يقرؤها العرض نصّاً)،
 *   - ويُحفظ الاسم القديم اسماً بديلاً كي تبقى مطابقته (مخرجات الذكاء، والمصادر، والبيانات الواردة)،
 *   - ويُقيَّد التغيير في سجلّ التدقيق.
 *
 * **لا حذف**: الإيقاف يُخفي من الاختيار ويُبقي ما يشير إليه. والتحديث الجماعيّ بـ`DB` لا بالنماذج —
 * كي لا يُعيد خطّاف الربط (`LinksLegalDepartment`) حلَّ الاسم لآلاف الصفوف.
 */
class LegalCatalogueEditor
{
    /** تصنيف قيود التدقيق — تعديل الكتالوج قرارٌ إداريّ كبقيّة إجراءات الإدارة العليا. */
    private const AUDIT_CATEGORY = 'الإدارة العليا';

    /** حالات التذكرة المنتهية — لا تُعدّ في أثر إيقاف قسم. */
    private const CLOSED_TICKET_STATUSES = ['مكتملة', 'مغلقة'];

    // ── الأقسام القانونيّة ─────────────────────────────────

    public static function createDepartment(string $name, User $actor): LegalDepartment
    {
        $name = self::cleanName($name);
        self::assertDepartmentNameFree($name, null);

        $department = LegalDepartment::create([
            // رمزٌ داخليّ ثابت لا يُعرض — الأقسام المزروعة تحمل رموزاً مقروءة، والمضافة لاحقاً رمزاً فريداً
            'code' => 'custom_'.Str::lower(Str::random(10)),
            'name' => $name,
            'sort_order' => (int) LegalDepartment::max('sort_order') + 10,
            'status' => LegalDepartment::STATUS_ACTIVE,
        ]);

        self::audit($actor, 'إضافة قسم قانوني', "أضاف {$actor->name} القسم «{$name}».", $department, $department->code);

        return $department;
    }

    public static function renameDepartment(LegalDepartment $department, string $name, User $actor): void
    {
        $name = self::cleanName($name);
        $old = $department->name;
        if ($name === $old) {
            return;
        }

        self::assertDepartmentNameFree($name, $department->id);

        DB::transaction(function () use ($department, $name, $old) {
            $department->update(['name' => $name]);
            self::rememberAlias($old, $department->id, null);

            DB::table('tickets')->where('legal_department_id', $department->id)->update(['department' => $name]);
            DB::table('cases')->where('legal_department_id', $department->id)->update(['department' => $name]);
            DB::table('consults')->where('legal_department_id', $department->id)->update(['specialty' => $name]);

            // نسخة العرض لدى المحامين: من كان نصُّ قسمه الاسمَ القديم وهذا القسم من تخصّصاته
            DB::table('users')->where('role', Role::Lawyer->value)->where('department', $old)
                ->whereExists(fn (Builder $q) => $q->from('lawyer_specialties')
                    ->whereColumn('lawyer_specialties.user_id', 'users.id')
                    ->where('lawyer_specialties.legal_department_id', $department->id))
                ->update(['department' => $name]);
        });

        self::audit($actor, 'إعادة تسمية قسم قانوني', "أعاد {$actor->name} تسمية القسم «{$old}» إلى «{$name}».", $department, $department->code, ['الاسم' => $old], ['الاسم' => $name]);
    }

    public static function setDepartmentStatus(LegalDepartment $department, bool $active, User $actor): void
    {
        $status = $active ? LegalDepartment::STATUS_ACTIVE : LegalDepartment::STATUS_SUSPENDED;
        if ($department->status === $status) {
            return;
        }

        $department->update(['status' => $status]);

        self::audit($actor, $active ? 'تفعيل قسم قانوني' : 'إيقاف قسم قانوني', ($active ? 'فعّل' : 'أوقف')." {$actor->name} القسم «{$department->name}».", $department, $department->code);
    }

    /**
     * أثر إيقاف كلّ قسم — ما تعرضه الشاشة قبل التأكيد ويشترطه الخادم:
     *   - `lawyersOnlyHere`: محامون فعّالون هذا القسم تخصّصهم الوحيد (يخرجون من الإسناد التلقائيّ لجديده).
     *   - `openTickets`: تذاكر مفتوحة في القسم (تبقى، ولا يُختار القسم لجديد).
     *
     * @return array<int, array{lawyersOnlyHere: int, openTickets: int}>
     */
    public static function suspensionImpacts(): array
    {
        $openTickets = DB::table('tickets')->whereNotNull('legal_department_id')
            ->whereNotIn('status', self::CLOSED_TICKET_STATUSES)
            ->selectRaw('legal_department_id, count(*) as total')
            ->groupBy('legal_department_id')
            ->pluck('total', 'legal_department_id');

        // محامٍ فعّالٌ له تخصّصٌ واحد ← يُحسب لذلك القسم
        $singleSpecialty = DB::table('lawyer_specialties')
            ->join('users', 'users.id', '=', 'lawyer_specialties.user_id')
            ->where('users.status', 'active')->where('users.covers_all_departments', false)
            ->selectRaw('lawyer_specialties.user_id, min(lawyer_specialties.legal_department_id) as department_id')
            ->groupBy('lawyer_specialties.user_id')
            ->havingRaw('count(*) = 1')
            ->pluck('department_id')
            ->countBy()
            ->all();

        $impacts = [];
        foreach (LegalCatalogue::departments(activeOnly: false) as $department) {
            $impacts[$department->id] = [
                'lawyersOnlyHere' => (int) ($singleSpecialty[$department->id] ?? 0),
                'openTickets' => (int) ($openTickets[$department->id] ?? 0),
            ];
        }

        return $impacts;
    }

    /** @param  array<int, int|string>  $ids  معرّفات الأقسام بالترتيب الجديد */
    public static function reorderDepartments(array $ids, User $actor): void
    {
        // المقارنة بكلّ الأقسام لا بالمرسَلة: قائمةٌ ناقصة كانت تُقبل فيتداخل ترتيب ما أُغفل
        self::applyOrder(LegalDepartment::query()->pluck('id')->all(), $ids, fn (int $id, int $order) => LegalDepartment::whereKey($id)->update(['sort_order' => $order]));
        LegalCatalogue::flush();

        self::audit($actor, 'ترتيب الأقسام القانونية', "أعاد {$actor->name} ترتيب الأقسام القانونية.");
    }

    // ── الخدمات ────────────────────────────────────────────

    public static function createService(LegalDepartment $department, string $name, User $actor): LegalService
    {
        $name = self::cleanName($name);
        self::assertServiceNameFree($department, $name, null);

        $service = LegalService::create([
            'legal_department_id' => $department->id,
            'name' => $name,
            'sort_order' => (int) $department->services()->max('sort_order') + 10,
            'status' => LegalDepartment::STATUS_ACTIVE,
        ]);

        self::audit($actor, 'إضافة خدمة قانونية', "أضاف {$actor->name} الخدمة «{$name}» إلى القسم «{$department->name}».", $department, $department->code);

        return $service;
    }

    public static function renameService(LegalService $service, string $name, User $actor): void
    {
        $name = self::cleanName($name);
        $old = $service->name;
        if ($name === $old) {
            return;
        }

        $department = $service->department;
        self::assertServiceNameFree($department, $name, $service->id);

        DB::transaction(function () use ($service, $name, $old) {
            $service->update(['name' => $name]);
            self::rememberAlias($old, $service->legal_department_id, $service->id);
            DB::table('tickets')->where('legal_service_id', $service->id)->update(['type' => $name]);
        });

        self::audit($actor, 'إعادة تسمية خدمة قانونية', "أعاد {$actor->name} تسمية الخدمة «{$old}» إلى «{$name}» في القسم «{$department->name}».", $department, $department->code, ['الخدمة' => $old], ['الخدمة' => $name]);
    }

    public static function setServiceStatus(LegalService $service, bool $active, User $actor): void
    {
        $status = $active ? LegalDepartment::STATUS_ACTIVE : LegalDepartment::STATUS_SUSPENDED;
        if ($service->status === $status) {
            return;
        }

        $service->update(['status' => $status]);
        $department = $service->department;

        self::audit($actor, $active ? 'تفعيل خدمة قانونية' : 'إيقاف خدمة قانونية', ($active ? 'فعّل' : 'أوقف')." {$actor->name} الخدمة «{$service->name}» في القسم «{$department->name}».", $department, $department->code);
    }

    /** @param  array<int, int|string>  $ids  معرّفات خدمات القسم بالترتيب الجديد */
    public static function reorderServices(LegalDepartment $department, array $ids, User $actor): void
    {
        self::applyOrder($department->services()->pluck('id')->all(), $ids, fn (int $id, int $order) => LegalService::whereKey($id)->update(['sort_order' => $order]));
        LegalCatalogue::flush();

        self::audit($actor, 'ترتيب خدمات قسم', "أعاد {$actor->name} ترتيب خدمات القسم «{$department->name}».", $department, $department->code);
    }

    // ── قائمة مستندات القسم ─────────────────────────────────
    // قرار المالك 2026-09-26: لكلّ قسمٍ قائمته، ولكلّ بندٍ «إلزاميّ» أو «اختياريّ». الحذف مسموحٌ هنا
    // بخلاف الأقسام والخدمات: لا شيء يشير إلى البند إلّا مطابقاتُ المرفقات، وهي تُبقي نصَّ البند
    // (`requirement`) ويُفرَّغ معرّفها — فلا يُمحى أثرٌ وقع.

    public static function createDocument(LegalDepartment $department, string $name, bool $required, User $actor): LegalDepartmentDocument
    {
        $name = self::cleanName($name);
        self::assertDocumentNameFree($department, $name, null);

        return DB::transaction(function () use ($department, $name, $required, $actor) {
            // **أوّل بندٍ لا يُسقط القائمة العامّة بصمت.** قسمٌ بلا قائمةٍ يطلب `DEFAULT_DOCUMENTS`
            // (الهويّة الوطنيّة…)، وكانت إضافةُ بندٍ واحد تجعل قائمته هي وحدها — فيختفي طلبُ الهويّة
            // من كلّ تذاكر القسم دون أن يقرّر ذلك أحد. فتُنسخ بنود العامّة أوّلاً صفوفاً قابلة
            // للتحرير والحذف، ثمّ يُضاف البند؛ والحذف بعدها قرارٌ صريح يُقيَّد في التدقيق.
            $seeded = [];
            if (! $department->documents()->exists()) {
                $folded = LegalCatalogue::foldName($name);
                foreach (LegalCatalogue::DEFAULT_DOCUMENTS as $default) {
                    if (LegalCatalogue::foldName($default['name']) === $folded) {
                        continue; // البند الجديد نفسه — يُضاف بإلزامه المختار أدناه لا مرّتين
                    }
                    LegalDepartmentDocument::create([
                        'legal_department_id' => $department->id,
                        'name' => $default['name'],
                        'required' => $default['required'],
                        'sort_order' => (count($seeded) + 1) * 10,
                    ]);
                    $seeded[] = $default['name'];
                }
            }

            $document = LegalDepartmentDocument::create([
                'legal_department_id' => $department->id,
                'name' => $name,
                'required' => $required,
                'sort_order' => (int) $department->documents()->max('sort_order') + 10,
            ]);

            self::audit($actor, 'إضافة مستند مطلوب', "أضاف {$actor->name} المستند «{$name}» (".self::requiredLabel($required).") إلى قائمة القسم «{$department->name}»"
                .($seeded === [] ? '.' : '، ونُسخت إليها بنود القائمة العامّة: '.implode('، ', $seeded).'.'), $department, $department->code);

            LegalCatalogue::flush();

            return $document;
        });
    }

    public static function renameDocument(LegalDepartmentDocument $document, string $name, User $actor): void
    {
        $name = self::cleanName($name);
        $old = $document->name;
        if ($name === $old) {
            return;
        }

        $department = $document->department;
        self::assertDocumentNameFree($department, $name, $document->id);

        $document->update(['name' => $name]);

        self::audit($actor, 'إعادة تسمية مستند مطلوب', "أعاد {$actor->name} تسمية المستند «{$old}» إلى «{$name}» في قائمة القسم «{$department->name}».", $department, $department->code, ['المستند' => $old], ['المستند' => $name]);
    }

    public static function setDocumentRequired(LegalDepartmentDocument $document, bool $required, User $actor): void
    {
        if ($document->required === $required) {
            return;
        }

        $document->update(['required' => $required]);
        $department = $document->department;

        self::audit($actor, 'تغيير إلزام مستند', "جعل {$actor->name} المستند «{$document->name}» ".self::requiredLabel($required)."اً في قائمة القسم «{$department->name}».", $department, $department->code, ['الإلزام' => self::requiredLabel(! $required)], ['الإلزام' => self::requiredLabel($required)]);
    }

    public static function deleteDocument(LegalDepartmentDocument $document, User $actor): void
    {
        $department = $document->department;
        $name = $document->name;

        $document->delete();

        self::audit($actor, 'حذف مستند مطلوب', "حذف {$actor->name} المستند «{$name}» من قائمة القسم «{$department->name}».", $department, $department->code);
    }

    /** @param  array<int, int|string>  $ids  معرّفات بنود القسم بالترتيب الجديد */
    public static function reorderDocuments(LegalDepartment $department, array $ids, User $actor): void
    {
        self::applyOrder($department->documents()->pluck('id')->all(), $ids, fn (int $id, int $order) => LegalDepartmentDocument::whereKey($id)->update(['sort_order' => $order]));
        LegalCatalogue::flush();

        self::audit($actor, 'ترتيب مستندات قسم', "أعاد {$actor->name} ترتيب قائمة مستندات القسم «{$department->name}».", $department, $department->code);
    }

    // ── الأقسام الإداريّة للموظّفين ─────────────────────────

    public static function createStaffDepartment(string $name, User $actor): StaffDepartment
    {
        $name = self::cleanName($name);
        self::assertStaffDepartmentNameFree($name, null);

        $department = StaffDepartment::create([
            'name' => $name,
            'sort_order' => (int) StaffDepartment::max('sort_order') + 10,
            'status' => LegalDepartment::STATUS_ACTIVE,
        ]);

        self::audit($actor, 'إضافة قسم إداري', "أضاف {$actor->name} القسم الإداريّ «{$name}».");

        return $department;
    }

    public static function renameStaffDepartment(StaffDepartment $department, string $name, User $actor): void
    {
        $name = self::cleanName($name);
        $old = $department->name;
        if ($name === $old) {
            return;
        }

        self::assertStaffDepartmentNameFree($name, $department->id);

        DB::transaction(function () use ($department, $name, $old) {
            $department->update(['name' => $name]);
            DB::table('users')->where('role', '!=', Role::Lawyer->value)->where('department', $old)->update(['department' => $name]);
        });

        self::audit($actor, 'إعادة تسمية قسم إداري', "أعاد {$actor->name} تسمية القسم الإداريّ «{$old}» إلى «{$name}».", null, null, ['الاسم' => $old], ['الاسم' => $name]);
    }

    public static function setStaffDepartmentStatus(StaffDepartment $department, bool $active, User $actor): void
    {
        $status = $active ? LegalDepartment::STATUS_ACTIVE : LegalDepartment::STATUS_SUSPENDED;
        if ($department->status === $status) {
            return;
        }

        $department->update(['status' => $status]);

        self::audit($actor, $active ? 'تفعيل قسم إداري' : 'إيقاف قسم إداري', ($active ? 'فعّل' : 'أوقف')." {$actor->name} القسم الإداريّ «{$department->name}».");
    }

    // ── مساعدات ────────────────────────────────────────────

    /** مسافةٌ واحدة بين الكلمات وبلا أطراف — فلا يُعدّ «القضايا  العمالية» اسماً آخر. */
    private static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /** اسم القسم لا يطابق قسماً آخر باسمه أو بأحد أسمائه البديلة (بعد توحيد الصياغة). */
    private static function assertDepartmentNameFree(string $name, ?int $exceptId): void
    {
        $existing = LegalCatalogue::departmentNamed($name);

        if ($existing !== null && $existing->id !== $exceptId) {
            throw ValidationException::withMessages([
                'name' => "يوجد قسمٌ بهذا الاسم أو باسمٍ مطابقٍ له: «{$existing->name}».",
            ]);
        }
    }

    /** اسم الخدمة لا يتكرّر داخل قسمها (باسمها أو باسمٍ بديل لها). */
    private static function assertServiceNameFree(LegalDepartment $department, string $name, ?int $exceptId): void
    {
        $existing = LegalCatalogue::resolveService($name, $department->id);

        if ($existing !== null && $existing->id !== $exceptId) {
            throw ValidationException::withMessages([
                'name' => "توجد في القسم خدمةٌ بهذا الاسم أو باسمٍ مطابقٍ له: «{$existing->name}».",
            ]);
        }
    }

    /** اسم البند لا يتكرّر في قائمة قسمه بعد توحيد الصياغة — «الهويّة» و«الهوية» بندٌ واحد. */
    private static function assertDocumentNameFree(LegalDepartment $department, string $name, ?int $exceptId): void
    {
        $folded = LegalCatalogue::foldName($name);
        $clash = $department->documents()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['name'])
            ->first(fn (LegalDepartmentDocument $d) => LegalCatalogue::foldName($d->name) === $folded);

        if ($clash !== null) {
            throw ValidationException::withMessages(['name' => "في قائمة القسم مستندٌ بهذا الاسم: «{$clash->name}»."]);
        }
    }

    private static function requiredLabel(bool $required): string
    {
        return $required ? 'إلزاميّ' : 'اختياريّ';
    }

    private static function assertStaffDepartmentNameFree(string $name, ?int $exceptId): void
    {
        $folded = LegalCatalogue::foldName($name);
        $clash = StaffDepartment::query()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['name'])
            ->first(fn (StaffDepartment $d) => LegalCatalogue::foldName($d->name) === $folded);

        if ($clash !== null) {
            throw ValidationException::withMessages(['name' => "يوجد قسمٌ إداريٌّ بهذا الاسم: «{$clash->name}»."]);
        }
    }

    /**
     * يحفظ الاسم القديم اسماً بديلاً — إلّا إن كانت صياغته محجوزةً لهدفٍ ما (لا يُكتب فوق مطابقةٍ قائمة).
     */
    private static function rememberAlias(string $alias, int $departmentId, ?int $serviceId): void
    {
        $folded = LegalCatalogue::foldName($alias);
        if ($folded === '' || LegalCatalogueAlias::where('alias_folded', $folded)->exists()) {
            return;
        }

        LegalCatalogueAlias::create([
            'alias' => $alias,
            'legal_department_id' => $departmentId,
            'legal_service_id' => $serviceId,
            'source' => LegalCatalogueAlias::SOURCE_RENAME,
        ]);
    }

    /**
     * يطبّق ترتيباً جديداً بشرط أن يطابق المرسَلُ العناصرَ القائمة تماماً — لا عنصر ناقص ولا غريب.
     *
     * @param  array<int, int|string>  $existing
     * @param  array<int, int|string>  $ordered
     * @param  callable(int, int): mixed  $write
     */
    private static function applyOrder(array $existing, array $ordered, callable $write): void
    {
        $ordered = array_map('intval', array_values($ordered));
        $existing = array_map('intval', $existing);

        if (count($ordered) !== count(array_unique($ordered)) || array_diff($existing, $ordered) !== [] || array_diff($ordered, $existing) !== []) {
            throw ValidationException::withMessages(['order' => 'الترتيب المرسل لا يطابق العناصر القائمة — أعد تحميل الصفحة وحاول مجدداً.']);
        }

        DB::transaction(function () use ($ordered, $write) {
            foreach ($ordered as $index => $id) {
                $write($id, ($index + 1) * 10);
            }
        });
    }

    /**
     * @param  array<string, string>|null  $before
     * @param  array<string, string>|null  $after
     */
    private static function audit(User $actor, string $action, string $description, ?LegalDepartment $department = null, ?string $ref = null, ?array $before = null, ?array $after = null): void
    {
        Audit::log(
            action: $action,
            description: $description,
            category: self::AUDIT_CATEGORY,
            auditable: $department,
            auditableRef: $ref,
            beforeState: $before,
            afterState: $after,
            user: $actor,
        );
    }
}

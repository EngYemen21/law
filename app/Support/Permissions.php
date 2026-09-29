<?php

namespace App\Support;

/**
 * كتالوج الصلاحيات — المصدر الوحيد. spatie تخزّن الإسناد؛ هذا الملفّ يعرّف البنية
 * وخريطة المسار→الصلاحية.
 *
 * لا نسخة في الواجهة تُطابَق: الكتالوج يُبَثّ للواجهة وقت التشغيل عبر
 * HandleInertiaRequests كخاصّية permCatalog. (كان التوثيق يحيل إلى
 * PERM_GROUPS/PERM_PRESETS/PERM_VIEW_MAP في admin-data.ts وهي غير موجودة.)
 *
 * تنبيه عند الحذف: الصلاحيّة المُزالة من هنا تبقى صفّاً في القاعدة ومُسنَدةً لمن أُسندت
 * له. ‏`PermissionSeeder::pruneStalePermissions` ينظّفها، لكنّه لا يعمل إلّا حين تُشغَّل
 * البذور — والنشر على الخادم `migrate` وحدها. فاكتب معها مهاجرةً تحذفها من القاعدة
 * (نظير `2026_09_20_000002_drop_correspondence_permission`).
 */
class Permissions
{
    /*
     * **ثابتٌ لكلّ صلاحيّة — الاسم العربيّ يُكتب هنا مرّةً واحدة** (خطّة «إزالة التعارض» — المرحلة ٣).
     * كان الاسم يُكتب حرفيّاً في نحو ١٣٠ موضعاً (المسارات والمتحكّمات والكتالوج نفسه)، فخطأٌ إملائيّ
     * واحد يُسقط الحارس صامتاً: `can()` لاسمٍ غير موجود تُرجع false ولا تشكو. والواجهة تحرسها
     * `PermissionNamesTest` (كلّ اسمٍ مستعمَل موجودٌ في الكتالوج).
     */
    public const MANAGE_TICKETS = 'إدارة التذاكر';

    public const REPLY_TO_CLIENTS = 'الرد على العملاء';

    public const DISTRIBUTE_TICKETS = 'توزيع التذاكر';

    public const TRANSFER_TICKETS = 'تحويل التذاكر';

    public const SCHEDULE_APPOINTMENTS = 'جدولة المواعيد';

    public const RECEIVE_CONSULTS = 'استقبال الاستشارات';

    public const RUN_VIDEO_SESSIONS = 'إجراء الجلسات المرئية';

    public const RUN_LEGAL_ANALYSIS = 'تشغيل تلخيص الفريق القانوني';

    public const APPROVE_CONSULT_SUMMARY = 'اعتماد/تعديل ملخص الاستشارة';

    public const LEGAL_ASSISTANT = 'المساعد القانوني';

    public const APPROVE_SUMMARIES = 'اعتماد الملخصات';

    public const CONSULT_ARCHIVE = 'أرشيف الاستشارات';

    public const MANAGE_MEETINGS = 'إدارة الاجتماعات';

    public const SEND_MEETING_INVITES = 'إرسال دعوات الاجتماعات';

    public const APPROVE_MEETINGS = 'اعتماد الاجتماعات';

    public const MEETING_REPORTS = 'تقارير الاجتماعات';

    public const MANAGE_BOOKINGS = 'إدارة المواعيد والحجوزات';

    public const MANAGE_CASES_AND_FEES = 'إدارة القضايا والأتعاب';

    public const COURT_PROCEEDINGS = 'إجراءات المحكمة والجلسات';

    public const REPORTS_AND_REVENUE = 'التقارير والإيرادات';

    public const MANAGE_STAFF = 'إدارة الموظفين';

    public const SECURITY_AUDIT_LOG = 'سجل التدقيق الأمني';

    /*
     * **صلاحيّاتٌ تضيّق ما كان مفتوحاً للموظّف بصلاحيّةٍ أوسع** (قرار المالك 2026-09-18).
     * كان موظّفٌ بصلاحيّةٍ واحدة يسجّل حكماً على أيّ قضيّة، وينزّل مرفقات المكتب كلّه، ويشغّل
     * كلّ التسجيلات — ولا عمودَ يربط الموظّف بملفٍّ بعينه ليُعزل به. فصارت كلٌّ منها صلاحيّةً
     * مستقلّة تمنحها الإدارة من تبويب الموظّفين، **فوق** الصلاحيّة التي تفتح القسم نفسه.
     * تسري على الموظّف وحده: المحامي بإسناده، والإدارة العليا بإشرافها.
     */

    /** تسجيل الحكم وتصحيحه وحكم الاستئناف — لا تُمنح تلقائياً. */
    public const RECORD_RULINGS = 'تسجيل الأحكام';

    /** تنزيل مرفقات التذاكر والقضايا والتنفيذ — مُنحت لمن كان ينزّلها يوم فصلها (هجرة 2026_09_18_000002). */
    public const DOWNLOAD_FILES = 'تنزيل مرفقات الملفات';

    /** تشغيل تسجيلات الاستشارات والاجتماعات وتنزيلها ونصّها — مُنحت لمن كان يشغّلها يوم فصلها. */
    public const PLAY_RECORDINGS = 'تشغيل تسجيلات الجلسات';

    /** اعتماد لوائح ومستندات محرر الصياغة القانونية — سقف للموظف، ويُمنح للمحامي والإدارة. */
    public const APPROVE_DOCUMENTS = 'اعتماد الصياغة القانونية';

    /** تسجيل مصروفٍ يبقى بانتظار اعتماد الإدارة (`/employee/expenses`) — سقفٌ للموظّف لا منح (2026-09-29). */
    public const RECORD_EXPENSES = 'تسجيل المصروفات';

    /** المجموعات الخمس — العدد من `all()` لا من تعليق. */
    public const GROUPS = [
        'التذاكر والعملاء' => [self::MANAGE_TICKETS, self::REPLY_TO_CLIENTS, self::DISTRIBUTE_TICKETS, self::TRANSFER_TICKETS, self::SCHEDULE_APPOINTMENTS],
        'الاستشارات والفيديو والفريق القانوني' => [self::RECEIVE_CONSULTS, self::RUN_VIDEO_SESSIONS, self::RUN_LEGAL_ANALYSIS, self::APPROVE_CONSULT_SUMMARY, self::LEGAL_ASSISTANT, self::APPROVE_DOCUMENTS, self::APPROVE_SUMMARIES, self::CONSULT_ARCHIVE, self::PLAY_RECORDINGS],
        'الاجتماعات' => [self::MANAGE_MEETINGS, self::SEND_MEETING_INVITES, self::APPROVE_MEETINGS, self::MEETING_REPORTS],
        // حُذفت «إشعارات العملاء» (2026-09-24): شاشتها ومتحكّمها ومساراتها الثلاثة أُزيلت، ولا
        // موضع في النظام يكتب فيه إداريٌّ إشعاراً لعميلٍ بعينه — فبقاؤها مربّعٌ مؤشَّر يفتح باباً
        // معدوماً. تحذفها من القاعدة مهاجرة `2026_09_24_..._drop_client_notifications_permission`.
        'العملاء والإشعارات والمواعيد' => [self::MANAGE_BOOKINGS],
        'القضايا والمالية والإدارة' => [self::MANAGE_CASES_AND_FEES, self::COURT_PROCEEDINGS, self::RECORD_RULINGS, self::DOWNLOAD_FILES, self::REPORTS_AND_REVENUE, self::MANAGE_STAFF, self::SECURITY_AUDIT_LOG, self::RECORD_EXPENSES],
    ];

    /**
     * الصلاحيات المرتبطة بكل دور/لوحة — تُعرض هي فقط عند تسجيل/تعديل الموظف حسب دوره.
     * (موظف لا يرى صلاحيات المحامي/الإدارة والعكس). 'ALL' = كل الصلاحيات (الإدارة العليا).
     */
    public const ROLE_PERMISSIONS = [
        // **«تشغيل تلخيص الفريق القانوني» و«المساعد القانوني» و«اعتماد الصياغة القانونية» أسقفٌ للموظّف لا منحٌ له.**
        //
        // القائمة هنا **حدُّ ما تستطيع الإدارة منحَه** لا ما يُمنح تلقائياً:
        // `StaffController::permsForRole` يقصّ أيّ صلاحيّةٍ خارجها ولو تُلوعب بالطلب.
        // فبإدراجها يظهر المربّع في شاشة صلاحيّات الموظّف **فارغاً**، ويبقى بيد الإدارة
        // مفتاحُ استثناءٍ لموظّفٍ بعينه. وهي غائبةٌ عن قالب «خدمة عملاء» وعن بذرة
        // الموظّف، فلا ينالها أحدٌ تلقائياً.
        'employee' => [
            self::MANAGE_TICKETS, self::REPLY_TO_CLIENTS, self::DISTRIBUTE_TICKETS, self::TRANSFER_TICKETS, self::SCHEDULE_APPOINTMENTS,
            self::RECEIVE_CONSULTS, self::RUN_VIDEO_SESSIONS, self::RUN_LEGAL_ANALYSIS,
            self::LEGAL_ASSISTANT, self::APPROVE_DOCUMENTS,
            self::SEND_MEETING_INVITES,
            self::MANAGE_BOOKINGS,
            self::MANAGE_CASES_AND_FEES,
            // **«إجراءات المحكمة والجلسات» تمنحها الإدارة من تبويب الموظّفين** (قرار المالك 2026-09-11):
            // تسجيل الرفع في ناجز والقيد، وجدولة الجلسات وتحديثها، وتسجيل الحكم. سقفٌ لا منح —
            // غائبةٌ عن قالب «خدمة عملاء» وعن بذرة الموظّف، فلا ينالها إلا من تختاره الإدارة.
            // والمحامي لا يحتاجها: يباشر هذه الإجراءات بإسناده للقضيّة.
            self::COURT_PROCEEDINGS,
            // سقوفٌ لا منح (انظر أعلى الصنف): تظهر مربّعاتٍ في شاشة صلاحيّات الموظّف
            self::RECORD_RULINGS, self::DOWNLOAD_FILES, self::PLAY_RECORDINGS,
            // مصروفٌ يسجّله الموظّف ولا يُحسب حتى تعتمده الإدارة (قرار المالك 2026-09-29)
            self::RECORD_EXPENSES,
        ],
        // **«أرشيف الاستشارات» أُزيلت من دور المحامي.** كانت ممنوحةً له وكلُّ مساراتها
        // الأربعة داخل مجموعة `role:admin`، و`EnsureRole` يحجب غيرَ الإدارة **بلا أيّ
        // استثناء**. فالصلاحيّة تُعرض مؤشَّرةً في شاشة الصلاحيّات ولا تفتح باباً — يظنّ
        // المدير أنّ محاميه يبلغ الأرشيف وهو لا يبلغه.
        //
        // ولم تُفتح له بدلاً من ذلك لأنّ الأرشيف يعرض **جلسات المكتب كلّه**: تسجيلاتٍ
        // وتفريغاتٍ لموكّلي محامين آخرين. وفتحُه يحتاج تصفيةً بالإسناد — قرارُ منتجٍ لا
        // إصلاحُ عطل.
        'lawyer' => [
            self::LEGAL_ASSISTANT, self::APPROVE_DOCUMENTS, self::APPROVE_SUMMARIES, self::APPROVE_CONSULT_SUMMARY, self::RUN_LEGAL_ANALYSIS,
            self::MANAGE_CASES_AND_FEES,
            self::RECEIVE_CONSULTS, self::RUN_VIDEO_SESSIONS,
            self::MANAGE_MEETINGS, self::SEND_MEETING_INVITES, self::APPROVE_MEETINGS, self::MEETING_REPORTS,
        ],
        'admin' => 'ALL',
    ];

    /** القوالب الجاهزة (تُنشأ كأدوار spatie). */
    public const PRESETS = [
        'خدمة عملاء' => [self::MANAGE_TICKETS, self::REPLY_TO_CLIENTS, self::SCHEDULE_APPOINTMENTS, self::TRANSFER_TICKETS, self::RECEIVE_CONSULTS, self::MANAGE_BOOKINGS, self::SEND_MEETING_INVITES],
        // «اعتماد/تعديل ملخص الاستشارة» و«أرشيف الاستشارات» كانتا في `ROLE_PERMISSIONS`
        // للمحامي وغائبتين عن قالبه الجاهز، ومسارا `consults.summary` و`summary/approve`
        // يشترطان الأولى. فمحامٍ يُنشأ بالقالب يرى محرّر التقرير وزرّ الاعتماد ثمّ يُصدّ.
        // (محامو القاعدة الحاليّون يملكونها لأنهم أُنشئوا بمسارٍ آخر — الخطر على من يأتي.)
        // و«إرسال دعوات الاجتماعات» كشفها الحارس نفسه: مجموعةُ طلبات الاجتماعات
        // كاملةً تشترطها، وكانت خارج القالب.
        'محامٍ' => [self::LEGAL_ASSISTANT, self::APPROVE_DOCUMENTS, self::APPROVE_SUMMARIES, self::APPROVE_CONSULT_SUMMARY, self::MANAGE_CASES_AND_FEES, self::RECEIVE_CONSULTS, self::RUN_VIDEO_SESSIONS, self::RUN_LEGAL_ANALYSIS, self::MANAGE_MEETINGS, self::SEND_MEETING_INVITES],
        'إداري' => [self::DISTRIBUTE_TICKETS, self::MANAGE_STAFF, self::REPORTS_AND_REVENUE, self::CONSULT_ARCHIVE, self::RECEIVE_CONSULTS, self::MANAGE_MEETINGS, self::APPROVE_MEETINGS, self::MEETING_REPORTS, self::MANAGE_BOOKINGS],
        'الإدارة العليا' => 'ALL',
        'مدير' => 'ALL',
    ];

    /**
     * **وسيط المسار من الثوابت** — `Route::middleware(Permissions::middleware(self::A, self::B))`
     * يُنتج `permission:A,B` حرفاً بحرف (أيٌّ منها يكفي — `EnsurePermission`)، فخريطة `viewMap`
     * التي تُبنى من نصوص الوسائط وقت التشغيل لا تتغيّر.
     */
    public static function middleware(string ...$names): string
    {
        return 'permission:'.implode(',', $names);
    }

    /** قائمة مسطّحة بكل الصلاحيات (27). */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * الكتالوج الكامل للواجهة (مصدر وحيد) — يُشارَك عبر Inertia فلا تكرار يدوياً في الواجهة.
     * permissions: القائمة المسطّحة · groups: المجموعات · presets: القوالب (الأدوار) بصلاحياتها · viewMap: خريطة المسار→الصلاحية.
     */
    public static function catalog(): array
    {
        $groups = [];
        foreach (self::GROUPS as $g => $items) {
            $groups[] = ['g' => $g, 'items' => $items];
        }

        $presets = [];
        foreach (array_keys(self::PRESETS) as $name) {
            $presets[$name] = self::preset($name);
        }

        $rolePermissions = [];
        foreach (self::ROLE_PERMISSIONS as $role => $perms) {
            $rolePermissions[$role] = $perms === 'ALL' ? self::all() : $perms;
        }

        return [
            'permissions' => self::all(),
            'groups' => $groups,
            'presets' => $presets,
            'viewMap' => self::viewMap(),
            'rolePermissions' => $rolePermissions,
        ];
    }

    /** صلاحيات قالب (تُوسّع 'ALL' لكل الصلاحيات). */
    public static function preset(string $name): array
    {
        $p = self::PRESETS[$name] ?? [];

        return $p === 'ALL' ? self::all() : $p;
    }

    /**
     * خريطة المسار → الصلاحية اللازمة — **مشتقّة من المسارات المسجّلة نفسها**
     * (كل صفحة GET/inertia بلا مُعامِل تحمل middleware «permission:») فتبقى تصفية القائمة
     * متطابقة مع الإنفاذ تلقائياً بلا صيانة يدوية. تُخزّن داخل العملية.
     */
    public static function viewMap(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $map = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (str_contains($uri, '{')) {
                continue; // نتجاهل مسارات المُعامِلات (نبني تبويبات الصفحات فقط، ونتفادى التعارض)
            }
            // **صفحاتٌ فقط (GET).** الخريطة تحكم ظهور بنود التنقّل، وبنود التنقّل صفحات.
            // وبدون هذا الترشيح يطمس مسارُ POST بالعنوان نفسه صفحتَه فتأخذ الصفحةُ صلاحيّةَ
            // الفعل لا صلاحيّةَ العرض — انحرافٌ ظهر حين اختلفت صلاحيّاتهما (2026-09-24).
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                    $map['/'.$uri] = substr($mw, strlen('permission:'));
                    break;
                }
            }
        }

        return $cache = $map;
    }
}

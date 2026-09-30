<?php

namespace App\Http\Middleware;

use App\Domain\Journey\Enums\RescheduleReason;
use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Enums\Role;
use App\Http\Controllers\NotificationController;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewInbox;
use App\Support\AdminApprovalQueue;
use App\Support\AppEnvironment;
use App\Support\Permissions;
use App\Support\RoomPresence;
use App\Support\SettingsRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * **متغيّرات النظام التي تحتاجها الواجهات — القائمة الواحدة المسموح بمشاركتها.**
     *
     * كانت الشاشات تنقش نسخاً من قيمٍ يملكها `SettingsRegistry` («٣ دفعات»، «(15%)»، اسم المكتب
     * وهاتفه)، فيُغيّرها المدير من شاشة الإعدادات ويبقى العميل يقرأ القديم. الآن تُشارَك مرّةً في
     * كلّ صفحة تحت `settings`، والواجهة تقرؤها من `useSettings()` في `resources/js/lib/settings.ts`.
     *
     * **لإضافة مفتاح**: أضِف اسمه هنا كما هو في `SettingsRegistry::all()`، ثمّ حقله في النوع
     * `SharedSettings` في `resources/js/lib/settings.ts` — لا شيء غير ذلك. والقائمةُ قائمةُ سماحٍ
     * عمداً لا «كلّ السجلّ»: ما يُشارَك يصل كلَّ زائرٍ ولو ضيفاً، فلا يخرج متغيّرٌ داخليّ بالخطأ.
     *
     * @var list<string>
     */
    public const SHARED_SETTINGS = [
        'installments_count',
        'exec_max_collection_pct',
        'office_name',
        'office_phone',
        'office_url',
        // ساعات الحجز وطول الشريحة — شبكة الموظّف ومنتقي الوقت يرسمان ما يولّده المحرّك
        'consult_day_start',
        'consult_day_end',
        'consult_work_days',
        'consult_allow_overlap',
        'consult_allow_outside_office',
        'consult_slot_minutes',
        // حدّ «متأخّر» في شاشتي الاستشارات — كانتا تحملان 100 و120 للطلبات نفسها
        'consult_request_late_minutes',
        // مهلة فتح الدخول قبل الموعد — نصوص «يُفعَّل الدخول قبل الموعد بـ…» في الاستشارات والاجتماعات
        'session_join_opens_minutes',
        // نافذة بدء الطاقم — نصوص «البدء قبل الموعد بـ…» في شاشات الاستشارات
        'consult_staff_start_minutes',
        // «يُرجى الحضور قبل الموعد بـ…» في بطاقة الموعد — ونظيرها PDF يقرأ الإعداد نفسه
        'office_arrival_minutes',
    ];

    /**
     * قيم `SHARED_SETTINGS` بأنواعها من السجلّ (استعلامٌ واحد مخزَّن للطلب) + نسبة الضريبة.
     *
     * @return array<string, int|string>
     */
    public static function sharedSettings(): array
    {
        return [
            ...array_intersect_key(SettingsRegistry::values(), array_flip(self::SHARED_SETTINGS)),
            'vat_rate' => Setting::vatRate(),
        ];
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // بيئة التشغيل لشارة «بيئة تجربة» (`EnvironmentBadge`) — لا تُعرض في الإنتاج
            'appEnv' => ['sandbox' => AppEnvironment::isSandbox(), 'name' => (string) app()->environment()],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role->value,
                    'roleLabel' => $user->role->label(),
                    'avatar' => $user->avatar_initials,
                    'home' => $user->role->home(),
                    // الصلاحيات التفصيلية (spatie) — الإدارة تتجاوز الكل (isSuper)
                    'isSuper' => $user->isAdmin(),
                    'permissions' => $user->isAdmin() ? [] : $user->getPermissionNames()->all(),
                    // حسابات نفس الشخص (نفس الهُويّة **والجوال** المُثبت) — لمُبدّل «تبديل الحساب».
                    // أمنيّ: التجميع بالجوال أيضاً يمنع ظهور حساب مزوّر يشارك الهُويّة بجوال مختلف.
                    'accounts' => ($user->national_id && $user->phone)
                        ? User::where('national_id', $user->national_id)->where('phone', $user->phone)->where('status', 'active')
                            ->get(['id', 'role'])
                            ->map(fn ($u) => ['id' => $u->id, 'roleLabel' => $u->role->label(), 'current' => $u->id === $user->id])
                            ->values()
                        : [],
                ] : null,
            ],
            // كتالوج الصلاحيات (المصدر الوحيد من الخادم) — للتصفية وشاشة الموظفين
            'permCatalog' => $user ? Permissions::catalog() : null,
            // متغيّرات النظام التي تعرضها الواجهات (الدفعات، الضريبة، هويّة المكتب) — من مصدرها
            // لا منقوشةً في الشاشات. القائمة وطريقة الإضافة في `SHARED_SETTINGS` أعلاه.
            'settings' => fn () => self::sharedSettings(),
            // **سياسة إعادة الجدولة من مصدرها** (قرار المالك 2026-09-25): أسبابها وسقفها للطاقم.
            // الواجهة لا تكتب الأسباب ولا الرقم بنفسها — تتباعد عن الخادم عند أوّل تعديل.
            'reschedule' => ($user && $user->role !== Role::Client) ? [
                'reasons' => ['consult' => RescheduleReason::options('consult'), 'meeting' => RescheduleReason::options('meeting'), 'hearing' => RescheduleReason::options('hearing')],
                // السقف النافذ من الإعدادات (`consult_reschedule_limit`) — القارئ نفسه الذي يحرس الانتقال
                'limit' => RescheduleConsult::limit(),
            ] : null,
            // **مَن من الطاقم في جلسة Zoom الآن** [معرّف ⇒ رقم الجلسة] — من أحداث Zoom (`RoomPresence`)،
            // قراءةٌ واحدة تغذّي كلّ قوائم المحامين في الصفحة (`lib/staff-presence`). للطاقم وحده، وللعرض فقط.
            'inSession' => fn () => ($user && $user->role !== Role::Client) ? (object) RoomPresence::staffInSession() : null,
            // عدّ الإشعارات غير المقروءة الحقيقي (كسول) — يغذّي نقطة الجرس وشارة «الإشعارات»
            'unreadNotifications' => fn () => $user
                ? UserNotification::where('user_id', $user->id)->where('is_read', false)->count()
                : 0,
            // الإشعارات الحديثة مع الروابط المحسوبة (لتغذية القائمة المنسدلة فورياً دون تأخير)
            // الصفحة الأولى من `NotificationController::feed` — وما بعدها من `/notifications/more` بالمصدر نفسه
            'recentNotifications' => fn () => $user ? NotificationController::feed($user) : [],
            // شارات شريط العميل (كسولة) — كانت مشتقّة من بيانات DATA الوهمية في الواجهة،
            // فيرى كل عميل الأرقام نفسها (تذاكر 3 · مواعيد 2 · فواتير 4) مهما كان سجلّه.
            // وشارات الإدارة من مصادر عدّها الوحيدة: الصندوق (`AiReviewInbox`) ومركز الاعتمادات
            // (`AdminApprovalQueue`) — الرقم نفسه الذي تعرضه الشاشة حين تُفتح.
            'navBadges' => fn () => ($user && $user->role === Role::Admin)
                ? [
                    '/admin/ai-review' => AiReviewInbox::countFor($user),
                    '/admin/approvals' => AdminApprovalQueue::counts()['totalPending'],
                ]
                : (($user && $user->role === Role::Client)
                ? [
                    '/tickets' => Ticket::where('user_id', $user->id)
                        ->open()->count(),
                    // with('consult') إلزامي: liveState() يقرأ الاستشارة، وبدونه استعلام لكل موعد في كل عرض صفحة
                    // المفتاح /calendar لا /appointments: تبويب «المواعيد» طُوي في التبويب
                    // الزمني الموحّد، وبقاء المفتاح القديم كان يُخفي الشارة تماماً.
                    // والموعد «بانتظار الاعتماد» شأنٌ داخليّ لم يُنشر — عدُّه يُعلن للعميل موعداً لا يجده
                    '/calendar' => Appointment::with('consult')->where('user_id', $user->id)
                        ->where('status', '!=', 'بانتظار الاعتماد')
                        ->get()->filter(fn (Appointment $a) => $a->liveState()[0] === 'up')->count(),
                    // ما يُطالَب به فعلاً (`owedByClient`) — لا الملغاة ولا المعدومة ولا المسوّدة
                    '/invoices' => Invoice::where('user_id', $user->id)->owedByClient()->count(),
                ]
                : []),
            // المفتاحان مقبولان: with('success', …) وwith('flash', …) — الأخير مستعمل في 17 متحكّماً
            // وكان يُهمَل صامتاً لأنه غير مشارك، فتضيع كل رسائل التأكيد.
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'success' => fn () => $request->session()->get('success') ?? $request->session()->get('flash'),
                // **هويّة الرسالة لا نصّها** — يُعرض الإشعار مرّةً لكلّ ردٍّ حمله. كان الإشعار يُطلق
                // بتغيّر النصّ، فالرفضُ نفسه مرّتين متتاليتين (زرٌّ ضُغط ثانيةً) يمرّ الثانية صامتاً؛
                // وإعادة التحميل الجزئيّة (`only`) لا تحمل `flash` فتبقى الهويّة ولا يتكرّر الإشعار.
                'id' => fn () => $request->session()->hasAny(['error', 'success', 'flash']) ? Str::random(10) : null,
            ],
        ];
    }
}

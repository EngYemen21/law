// ============================================================
// أنواع منصّة العميل والتنقّل (الأدوار · القوائم · العناوين)
// ============================================================
//
// حُذفت البيانات التجريبيّة الثابتة الموروثة من النموذج (قرار المالك 2026-09-27، ناقضاً قرار
// «لا حذف» في 2026-08-21): لا مستوردَ لها، وقيمها تخالف الخادم. تبقى في تاريخ git.
// الشاشات تقرأ بياناتها من الخادم عبر خصائص Inertia؛ ما هنا أنواعٌ وخرائط تنقّل حيّة.

export interface Appt { id: string; type: string; ico: string; lawyer: string; day: string; time: string; place: string; status: string; tone: string; when: 'up' | 'past'; client?: string; consultRef?: string; pay?: string; joinLink?: string; }
export interface DocItem { id?: number; name: string; meta: string; canDownload?: boolean; downloadUrl?: string; }
export interface Invoice {
  no: string; desc: string; amount: number; status: string; tone: string; due: string; overdue?: boolean; paid: boolean; hasProof?: boolean;
  /** ملغاة — لا دفع ولا إثبات (يطابق `Invoice::isCancelled`). */
  cancelled?: boolean;
  /**
   * أهي ذمّةٌ فعلاً؟ — يحسبها الخادم من `RevenueSnapshot::isReceivable`.
   * **لا تُشتقّ هنا بـ`!paid`**: الملغاة والمعدومة غير مدفوعتين وليستا ديناً.
   */
  receivable?: boolean;
}

// العدادات المشتقة من DATA الوهمية — **ميتة**: Sidebar يقرأ navBadges من الخادم
// (أعداد حقيقية لكل عميل) ويتجاهل حقل badge الثابت تماماً. تُعلَّق لا تُحذف كي لا
// يُعاد ربطها سهواً فيرى كل عميل الأرقام نفسها مهما كان سجلّه.
// export const openTickets = DATA.tickets.filter((t) => t.status !== 'مكتملة').length;
// export const upAppts = DATA.appts.filter((a) => a.when === 'up').length;
// export const upMeet = DATA.meetings.filter((m) => m.up).length;
// export const dueInv = DATA.invoices.filter((i) => !i.paid).length;
// شارة الإشعارات صارت عدّاً حقيقياً من الخادم (unreadNotifications) تُحقن في Sidebar — لا ثابت هنا

// ── التنقل (NAV) ومسارات Inertia المقابلة ──
// كل عنصر: [icon, label, view] ؛ view نربطه بمسار /view
export interface NavItem { icon: string; label: string; view: string; badge?: number; alert?: boolean; }
export interface NavGroup { g: string; items: NavItem[]; }

export const NAV: NavGroup[] = [
  { g: 'لوحة المعلومات', items: [{ icon: 'home', label: 'الرئيسية', view: 'home' }] },
  { g: 'طلباتي وقضاياي', items: [
    { icon: 'ticket', label: 'فتح تذكرة', view: 'newticket' },
    { icon: 'folder', label: 'متابعة التذاكر', view: 'tickets' },
    { icon: 'scale', label: 'القضايا النشطة', view: 'cases' },
    { icon: 'exec', label: 'التنفيذ', view: 'execs' },
  ] },
  { g: 'الاستشارات والمواعيد', items: [
    { icon: 'calplus', label: 'حجز استشارة', view: 'book' },
    { icon: 'compass', label: 'استشاراتي', view: 'myconsults' },
    { icon: 'video', label: 'الاجتماعات', view: 'meetings' },
    { icon: 'calgrid', label: 'التقويم والمواعيد', view: 'calendar' },
  ] },
  { g: 'الملفات والمالية', items: [
    { icon: 'doc', label: 'المستندات', view: 'docs' },
    { icon: 'card', label: 'الفواتير', view: 'invoices', alert: true },
  ] },
  { g: 'الحساب', items: [
    { icon: 'user', label: 'الملف الشخصي', view: 'profile' },
  ] },
];

// خدمات الصفحة الرئيسية (TILES): [icon, title, sub, view, featured?]
export interface Tile { icon: string; title: string; sub: string; view: string; feat?: boolean; }
export const TILES: Tile[] = [
  { icon: 'ticket', title: 'فتح تذكرة', sub: 'قدّم طلباً قانونياً جديداً', view: 'newticket', feat: true },
  { icon: 'folder', title: 'متابعة التذاكر', sub: 'حالة طلباتك وآخر الردود', view: 'tickets' },
  { icon: 'scale', title: 'القضايا النشطة', sub: 'قضاياك الجارية وتحديثاتها', view: 'cases' },
  { icon: 'exec', title: 'طلبات التنفيذ', sub: 'متابعة إجراءات التنفيذ', view: 'execs' },
  { icon: 'calplus', title: 'حجز استشارة', sub: 'حضورية أو مرئية أو هاتفية', view: 'book' },
  { icon: 'video', title: 'الاجتماعات', sub: 'الروابط والمحاضر المعتمدة', view: 'meetings' },
  { icon: 'doc', title: 'المستندات', sub: 'المرفوعة والصادرة إليك', view: 'docs' },
  { icon: 'card', title: 'الفواتير', sub: 'المستحقة والمدفوعة', view: 'invoices' },
  { icon: 'calgrid', title: 'التقويم والمواعيد', sub: 'مواعيدك وارتباطاتك في مكان واحد', view: 'calendar' },
  { icon: 'user', title: 'الملف الشخصي', sub: 'بياناتك وأمان حسابك', view: 'profile' },
];

// العناوين والمسارات (TITLES): view -> [title, crumb]
export const TITLES: Record<string, [string, string]> = {
  home: ['الرئيسية', 'منصة العميل'],
  newticket: ['فتح تذكرة جديدة', 'طلباتي'],
  tickets: ['متابعة التذاكر', 'طلباتي'],
  cases: ['القضايا النشطة', 'طلباتي'],
  execs: ['التنفيذ', 'طلباتي'],
  book: ['حجز استشارة', 'الاستشارات'],
  // appts: طُوي في calendar (التبويب الزمني الموحّد) — يُعلَّق لا يُحذف كي يعرف
  // من يصادف مرجعاً قديماً لـview: 'appts' أين ذهب.
  // appts: ['المواعيد', 'الاستشارات'],
  meetings: ['الاجتماعات', 'الاستشارات'],
  calendar: ['التقويم والمواعيد', 'الاستشارات'],
  docs: ['المستندات', 'الملفات والمالية'],
  invoices: ['الفواتير والمدفوعات', 'الملفات والمالية'],
  profile: ['الملف الشخصي', 'الحساب'],
  myconsults: ['استشاراتي', 'الاستشارات'],
  // meetreqs: طُوي — الدعوة تُولَد مؤكَّدة فتظهر في «الاجتماعات» مباشرةً
  // meetreqs: ['دعوات الاجتماعات', 'الاستشارات'],
};

// خريطة view -> مسار URL (Inertia)
export const VIEW_ROUTE: Record<string, string> = {
  home: '/dashboard',
  newticket: '/tickets/new',
  tickets: '/tickets',
  cases: '/cases',
  execs: '/execs',
  book: '/book',
  myconsults: '/myconsults',
  // appts: '/appointments',  ← طُوي في calendar؛ المسار نفسه ما زال حيّاً ويُحوّل إليه
  meetings: '/meetings',
  // meetreqs: '/meetreqs',  ← طُوي في meetings؛ المسار نفسه ما زال حيّاً ويُحوّل إليه
  calendar: '/calendar',
  docs: '/documents',
  invoices: '/invoices',
  profile: '/profile',
};

// ============================================================
// نظام الأدوار — يطابق ROLE_DEFS في index (82).html
// ============================================================

export interface RoleMeta { key: string; label: string; icon: string; name: string; av: string; home: string; }

export const ROLES: RoleMeta[] = [
  { key: 'client', label: 'العميل', icon: 'user', name: 'عبدالله محمد العتيبي', av: 'ع م', home: '/dashboard' },
  { key: 'employee', label: 'الموظف', icon: 'reply', name: 'منيرة الحربي', av: 'م ح', home: '/employee/dashboard' },
  { key: 'lawyer', label: 'المحامي', icon: 'scale', name: 'أ. سارة القحطاني', av: 'س ق', home: '/lawyer/dashboard' },
  { key: 'admin', label: 'الإدارة', icon: 'office', name: 'الإدارة العليا', av: 'إ ع', home: '/admin/dashboard' },
];

// تحديد الدور الحالي من المسار
export function roleOfPath(path: string): string {
  if (path.startsWith('/employee')) {
return 'employee';
}

  if (path.startsWith('/lawyer')) {
return 'lawyer';
}

  if (path.startsWith('/admin')) {
return 'admin';
}

  return 'client';
}

// الصفحات المشتركة (بلا بادئة دور) — تُعرض داخل لوحة دور المستخدم الفعليّ لا لوحة العميل الافتراضية.
export const SHARED_ACCOUNT_ROUTES = ['/profile'];

// لوحة العرض الصحيحة: للصفحات المشتركة نعتمد دور المستخدم الفعليّ (auth.user.role)،
// ولغيرها نشتقّ الدور من المسار. (مبدّل لوحات الإدارة أُلغي 2026-08-28 — الدالة باقية
// لأنها تحدد شريط التنقل حسب مسار الصفحة المعروضة.)
/**
 * بادئة لوحة الدور من المسار الحاليّ — لبناء وجهات الإرسال.
 *
 * الشاشات المشتركة بين الأدوار (صندوق المراجعة، المراجعة العمياء، المصادر) لها
 * مسارٌ لكل دور. وتثبيت `/admin` في الإرسال يجعل المحامي يفتح الشاشة ثم يُمنع
 * عند الحفظ بـ403 — أي شاشةٌ تُعرض ولا تعمل، وهو أسوأ من غيابها.
 */
export function panelBase(path: string): string {
  const role = roleOfPath(path);

  return role === 'client' ? '' : `/${role}`;
}

export function panelRole(path: string, userRole?: string): string {
  if (userRole && SHARED_ACCOUNT_ROUTES.includes(path)) {
    return userRole;
  }

  return roleOfPath(path);
}

// عناصر الشريط الجانبي (مبنية على المسارات مباشرة)
export interface SideItem { icon: string; label: string; route: string; badge?: number; alert?: boolean; }
export interface SideGroup { g: string; items: SideItem[]; }

// تحويل NAV (دور العميل) إلى عناصر مبنية على المسارات
const CLIENT_NAV: SideGroup[] = NAV.map((grp) => ({
  g: grp.g,
  items: grp.items.map((it) => ({
    icon: it.icon,
    label: it.label,
    route: VIEW_ROUTE[it.view] ?? '#',
    badge: it.badge,
    alert: it.alert,
  })),
}));

// شريط دور الموظف — حوكمة العمليات الإدارية والتشغيلية
const EMPLOYEE_NAV: SideGroup[] = [
  { g: 'العمليات والمسارات', items: [
    { icon: 'home', label: 'الرئيسية', route: '/employee/dashboard' },
    { icon: 'folder', label: 'التذاكر', route: '/employee/tickets' },
    { icon: 'reply', label: 'التحويلات', route: '/employee/transfer' },
    { icon: 'scale', label: 'القضايا', route: '/employee/cases' },
    { icon: 'exec', label: 'التنفيذ', route: '/employee/execs' },
  ] },
  { g: 'الاستشارات والمواعيد', items: [
    { icon: 'scale', label: 'إدارة الاستشارات', route: '/employee/consults' },
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/employee/consultrecv' },
    { icon: 'calgrid', label: 'التقويم والمواعيد', route: '/employee/calendar' },
  ] },
  { g: 'الاجتماعات', items: [
    { icon: 'video', label: 'الاجتماعات', route: '/employee/meetings' },
    { icon: 'send', label: 'طلبات الاجتماعات', route: '/employee/meetreqs' },
  ] },
  { g: 'الصياغة والوثائق', items: [
    { icon: 'office', label: 'محرر الصياغة', route: '/employee/editor' },
  ] },
  { g: 'الذكاء الاصطناعي', items: [
    { icon: 'sparkles', label: 'مراجعة مخرجات الذكاء', route: '/employee/ai-review' },
  ] },
  { g: 'حسابي', items: [
    { icon: 'card', label: 'مستحقاتي', route: '/employee/earnings' },
  ] },
];

// شريط دور المحامي — مسارات العمل المهني والقانوني التخصصي
const LAWYER_NAV: SideGroup[] = [
  { g: 'العمل القانوني والملفات', items: [
    { icon: 'home', label: 'الرئيسية', route: '/lawyer/dashboard' },
    { icon: 'folder', label: 'التذاكر', route: '/lawyer/tickets' },
    { icon: 'scale', label: 'قضاياي', route: '/lawyer/cases' },
    { icon: 'exec', label: 'التنفيذ', route: '/lawyer/execs' },
    { icon: 'check', label: 'مهام العمل', route: '/lawyer/tasks' },
  ] },
  { g: 'الاستشارات والمواعيد', items: [
    { icon: 'scale', label: 'جلسات الاستشارات', route: '/lawyer/consults' },
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/lawyer/consultrecv' },
    { icon: 'out', label: 'الملخصات القانونية', route: '/lawyer/summaries' },
    { icon: 'calgrid', label: 'التقويم والمواعيد', route: '/lawyer/calendar' },
  ] },
  { g: 'الاجتماعات', items: [
    { icon: 'video', label: 'الاجتماعات', route: '/lawyer/meetings' },
    { icon: 'send', label: 'طلبات الاجتماعات', route: '/lawyer/meetreqs' },
  ] },
  { g: 'الصياغة والأدوات القانونية', items: [
    { icon: 'office', label: 'محرر الصياغة', route: '/lawyer/editor' },
    { icon: 'doc', label: 'المساعد القانوني', route: '/lawyer/assistant' },
  ] },
  { g: 'الذكاء الاصطناعي والمصادر', items: [
    { icon: 'sparkles', label: 'مراجعة مخرجات الذكاء', route: '/lawyer/ai-review' },
    { icon: 'eye', label: 'المراجعة العمياء', route: '/lawyer/ai-blind-review' },
    { icon: 'scale', label: 'المصادر القانونيّة', route: '/lawyer/legal-sources' },
  ] },
  { g: 'حسابي', items: [
    { icon: 'card', label: 'مستحقاتي', route: '/lawyer/earnings' },
  ] },
];

// شريط دور الإدارة — يطابق ROLE_DEFS.admin.nav
const ADMIN_NAV: SideGroup[] = [
  { g: 'الإشراف', items: [
    { icon: 'home', label: 'الرئيسية', route: '/admin/dashboard' },
    { icon: 'clock', label: 'سجل التدقيق الأمني', route: '/admin/audit-logs' },
    { icon: 'reply', label: 'سجل انتقالات الرحلة', route: '/admin/journey-transitions' },
    { icon: 'user', label: 'العملاء', route: '/admin/clients' },
    { icon: 'folder', label: 'التذاكر', route: '/admin/tickets' },
    { icon: 'scale', label: 'كل القضايا', route: '/admin/cases' },
    { icon: 'calgrid', label: 'الجلسات وتواريخ المحاكم', route: '/admin/hearings' },
    { icon: 'exec', label: 'التنفيذ', route: '/admin/execs' },
    { icon: 'scale', label: 'المحامون', route: '/admin/lawyers' },
  ] },
  { g: 'الاستشارات', items: [
    { icon: 'scale', label: 'إدارة الاستشارات', route: '/admin/consults' },
    { icon: 'card', label: 'طلبات الاستشارات', route: '/admin/consult-requests' },
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/admin/consultrecv' },
    { icon: 'video', label: 'أرشيف الاستشارات', route: '/admin/archive' },
  ] },
  { g: 'الاجتماعات', items: [
    { icon: 'calgrid', label: 'إدارة الاجتماعات', route: '/admin/meetmgmt' },
    { icon: 'video', label: 'طلبات الاجتماعات', route: '/admin/meetreqs' },
    { icon: 'video', label: 'اعتماد الاجتماعات', route: '/admin/meetings' },
    { icon: 'folder', label: 'أرشيف الاجتماعات', route: '/admin/meetlog' },
    { icon: 'cal', label: 'تقارير الاجتماعات', route: '/admin/meetreports' },
  ] },
  { g: 'الإدارة العليا والعمليات', items: [
    { icon: 'check', label: 'مركز الاعتمادات والقرارات', route: '/admin/approvals' },
    { icon: 'user', label: 'تسجيل الموظفين', route: '/admin/staff' },
    { icon: 'reply', label: 'توزيع وإسناد الأعمال', route: '/admin/distribute' },
    { icon: 'card', label: 'أتعاب القضايا', route: '/admin/casefees' },
    { icon: 'exec', label: 'مهام العمل', route: '/admin/tasks' },
    { icon: 'calgrid', label: 'التقويم والمواعيد', route: '/admin/calendar' },
    { icon: 'doc', label: 'المساعد القانوني', route: '/admin/assistant' },
    { icon: 'office', label: 'محرر الصياغة', route: '/admin/editor' },
    // متغيّرات النظام: مهل التنفيذ والتنبيهات وبيانات المكتب — كانت ثوابتَ في الشيفرة
    { icon: 'compass', label: 'إعدادات النظام', route: '/admin/settings' },
    // كتالوج الأقسام القانونيّة وخدماتها والأقسام الإداريّة — كان قوائم ثابتة في الواجهة
    { icon: 'folder', label: 'الأقسام والخدمات', route: '/admin/catalogue' },
  ] },
  // شاشات الذكاء الاصطناعي: مراجعة المخرجات، واعتماد المصادر، والحوكمة والمعايرة.
  // كانت تُبنى بلا رابط يصل إليها — تُفتح بكتابة مسارها يدوياً وحدها، أي إنها عملياً
  // غير موجودة لمن لا يعرف المسار. الشاشة بلا مدخل ليست شاشةً.
  { g: 'الذكاء الاصطناعي', items: [
    { icon: 'sparkles', label: 'مراجعة مخرجات الذكاء', route: '/admin/ai-review' },
    { icon: 'scale', label: 'المصادر القانونيّة', route: '/admin/legal-sources' },
    { icon: 'doc', label: 'المراجعة العمياء', route: '/admin/ai-blind-review' },
    { icon: 'compass', label: 'تشغيل الذكاء وحوكمته', route: '/admin/ai-ops' },
  ] },
  { g: 'المالية والتقارير', items: [
    { icon: 'card', label: 'الإيرادات', route: '/admin/revenue' },
    { icon: 'card', label: 'المالية والمحاسبة', route: '/admin/finance' },
    { icon: 'calgrid', label: 'التقارير', route: '/admin/reports' },
  ] },
];

export const ROLE_NAV: Record<string, SideGroup[]> = {
  client: CLIENT_NAV,
  employee: EMPLOYEE_NAV,
  lawyer: LAWYER_NAV,
  admin: ADMIN_NAV,
};

// العناوين حسب الدور: المسار -> [العنوان، المسار الفرعي]
const CLIENT_TITLES: Record<string, [string, string]> = Object.entries(TITLES).reduce(
  (acc, [view, t]) => {
    const r = VIEW_ROUTE[view];

    if (r) {
acc[r] = t;
}

    return acc;
  },
  {} as Record<string, [string, string]>
);
// مسارات إضافية لدور العميل (الغرف المرئية) — ليست ضمن VIEW_ROUTE فتظهر بلا عنوان
CLIENT_TITLES['/meetingroom'] = ['غرفة الاجتماع', 'منصة العميل'];
CLIENT_TITLES['/consults/room'] = ['غرفة الاستشارة المرئية', 'منصة العميل'];

const EMPLOYEE_TITLES: Record<string, [string, string]> = {
  '/employee/dashboard': ['الرئيسية', 'لوحة الموظف'],
  '/employee/tickets': ['التذاكر', 'لوحة الموظف'],
  '/employee/cases': ['القضايا', 'لوحة الموظف'],
  '/employee/execs': ['التنفيذ', 'لوحة الموظف'],
  '/employee/consults': ['إدارة الاستشارات', 'لوحة الموظف'],
  '/employee/consult': ['رحلة الاستشارة', 'لوحة الموظف'],
  '/employee/schedule': ['التقويم والمواعيد', 'لوحة الموظف'],
  '/employee/calendar': ['التقويم والمواعيد', 'لوحة الموظف'],
  '/employee/transfer': ['التحويلات', 'لوحة الموظف'],
  '/employee/meetreqs': ['طلبات الاجتماعات', 'لوحة الموظف'],
  '/employee/meetings': ['الاجتماعات', 'لوحة الموظف'],
  '/employee/meeting': ['تفاصيل الاجتماع', 'لوحة الموظف'],
  '/employee/meetingroom': ['غرفة الاجتماع', 'لوحة الموظف'],
  '/employee/consultrecv': ['استقبال الاستشارات', 'لوحة الموظف'],
  '/employee/videoroom': ['غرفة الجلسة المرئية', 'لوحة الموظف'],
  '/employee/ai-review': ['مراجعة مخرجات الذكاء', 'لوحة الموظف'],
  '/employee/editor': ['محرر الصياغة القانونية', 'لوحة الموظف'],
  '/employee/earnings': ['مستحقاتي', 'لوحة الموظف'],
};

const LAWYER_TITLES: Record<string, [string, string]> = {
  '/lawyer/dashboard': ['الرئيسية', 'لوحة المحامي'],
  '/lawyer/tickets': ['التذاكر', 'لوحة المحامي'],
  '/lawyer/cases': ['قضاياي', 'لوحة المحامي'],
  '/lawyer/execs': ['التنفيذ', 'لوحة المحامي'],
  '/lawyer/meetings': ['الاجتماعات', 'لوحة المحامي'],
  '/lawyer/meetreqs': ['طلبات الاجتماعات', 'لوحة المحامي'],
  '/lawyer/calendar': ['التقويم والمواعيد', 'لوحة المحامي'],
  '/lawyer/assistant': ['المساعد القانوني الذكي', 'لوحة المحامي'],
  '/lawyer/meeting': ['تفاصيل الاجتماع', 'لوحة المحامي'],
  '/lawyer/meetingroom': ['غرفة الاجتماع', 'لوحة المحامي'],
  '/lawyer/consultrecv': ['استقبال الاستشارات', 'لوحة المحامي'],
  '/lawyer/consults': ['جلسات الاستشارات', 'لوحة المحامي'],
  '/lawyer/consult': ['رحلة الاستشارة', 'لوحة المحامي'],
  '/lawyer/summaries': ['الملخصات', 'لوحة المحامي'],
  '/lawyer/tasks': ['المهام', 'لوحة المحامي'],
  '/lawyer/ai-review': ['مراجعة مخرجات الذكاء', 'لوحة المحامي'],
  '/lawyer/ai-blind-review': ['المراجعة العمياء', 'لوحة المحامي'],
  '/lawyer/legal-sources': ['المصادر القانونيّة المعتمدة', 'لوحة المحامي'],
  '/lawyer/editor': ['محرر الصياغة القانونية', 'لوحة المحامي'],
  '/lawyer/videoroom': ['غرفة الجلسة المرئية', 'لوحة المحامي'],
  '/lawyer/earnings': ['مستحقاتي', 'لوحة المحامي'],
};

const ADMIN_TITLES: Record<string, [string, string]> = {
  '/admin/dashboard': ['الرئيسية', 'لوحة الإدارة'],
  '/admin/audit-logs': ['سجل الرقابة والتدقيق الأمني', 'الإدارة العليا'],
  '/admin/journey-transitions': ['سجل انتقالات الرحلة الموحد', 'الإدارة العليا'],
  '/admin/ai-review': ['مراجعة مخرجات الذكاء', 'الإدارة العليا'],
  '/admin/ai-blind-review': ['المراجعة العمياء', 'الإدارة العليا'],
  '/admin/legal-sources': ['المصادر القانونيّة المعتمدة', 'الإدارة العليا'],
  '/admin/ai-ops': ['تشغيل الذكاء وحوكمته', 'الإدارة العليا'],
  '/admin/clients': ['العملاء', 'لوحة الإدارة'],
  '/admin/tickets': ['التذاكر', 'لوحة الإدارة'],
  '/admin/lawyers': ['المحامون', 'لوحة الإدارة'],
  '/admin/consults': ['إدارة الاستشارات', 'الإدارة العليا'],
  '/admin/consult-requests': ['طلبات الاستشارات', 'الإدارة العليا'],
  '/admin/consult': ['رحلة الاستشارة', 'الإدارة العليا'],
  '/admin/staff': ['تسجيل الموظفين', 'الإدارة العليا'],
  '/admin/archive': ['أرشيف الاستشارات', 'الإدارة العليا'],
  '/admin/distribute': ['توزيع وإسناد الأعمال', 'الإدارة العليا'],
  '/admin/casefees': ['أتعاب القضايا', 'الإدارة العليا'],
  '/admin/cases': ['كل القضايا', 'لوحة الإدارة'],
  '/admin/hearings': ['الجلسات القضائية وتواريخ المحاكم', 'الإشراف والمتابعة'],
  '/admin/execs': ['التنفيذ', 'لوحة الإدارة'],
  '/admin/tasks': ['مهام العمل', 'الإدارة العليا'],
  '/admin/meetmgmt': ['إدارة الاجتماعات', 'الإدارة العليا'],
  '/admin/meetreqs': ['طلبات الاجتماعات', 'لوحة الإدارة'],
  '/admin/meetlog': ['أرشيف الاجتماعات', 'الإدارة العليا'],
  '/admin/meetings': ['اعتماد الاجتماعات', 'لوحة الإدارة'],
  '/admin/meeting': ['تفاصيل الاجتماع', 'لوحة الإدارة'],
  '/admin/meetingroom': ['غرفة الاجتماع', 'لوحة الإدارة'],
  '/admin/assistant': ['المساعد القانوني الذكي', 'الإدارة العليا'],
  '/admin/approvals': ['مركز الاعتمادات والقرارات', 'لوحة الإدارة'],
  '/admin/revenue': ['الإيرادات', 'لوحة الإدارة'],
  '/admin/settings': ['إعدادات النظام', 'الإدارة العليا'],
  '/admin/catalogue': ['الأقسام والخدمات', 'الإدارة العليا'],
  '/admin/finance': ['المالية والمحاسبة', 'الإدارة العليا'],
  '/admin/meetreports': ['تقارير الاجتماعات', 'الإدارة العليا'],
  '/admin/reports': ['التقارير', 'لوحة الإدارة'],
  '/admin/editor': ['محرر الصياغة القانونية', 'لوحة الإدارة'],
  '/admin/calendar': ['التقويم والمواعيد', 'لوحة الإدارة'],
  '/admin/consultrecv': ['استقبال الاستشارات', 'لوحة الإدارة'],
  '/admin/videoroom': ['غرفة الجلسة المرئية', 'لوحة الإدارة'],
};

export const ROLE_TITLES: Record<string, Record<string, [string, string]>> = {
  client: CLIENT_TITLES,
  employee: EMPLOYEE_TITLES,
  lawyer: LAWYER_TITLES,
  admin: ADMIN_TITLES,
};

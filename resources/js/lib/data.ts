// ============================================================
// بيانات منصة العميل — مستخرجة حرفياً من index (82).html (DATA)
// ============================================================

export interface Ticket { no: string; type: string; dept: string; status: string; tone: string; last: string; date: string; }
export interface Case { no: string; type: string; status: string; tone: string; update: string; }
export interface Exec { no: string; subject: string; status: string; tone: string; last: string; }
export interface Appt { id: string; type: string; ico: string; lawyer: string; day: string; time: string; branch: string; status: string; tone: string; when: 'up' | 'past'; client?: string; consultRef?: string; pay?: string; }
export interface Meeting { id?: number; ref: string; title: string; when: string; up: boolean; status?: string; tone?: string; canJoin?: boolean; approved?: boolean; link: string; minutes: string | null; summary: string | null; }
export interface DocItem { id?: number; name: string; meta: string; canDownload?: boolean; downloadUrl?: string; }
export interface Invoice { no: string; desc: string; amount: number; status: string; tone: string; due: string; overdue?: boolean; paid: boolean; hasProof?: boolean; }
export interface Notif { ic: string; tone: string; text: string; time: string; unread: boolean; }

export const DATA = {
  tickets: [
    { no: 'SB-2026-1042', type: 'نزاع تجاري', dept: 'القسم التجاري', status: 'قيد التحليل', tone: 'b-blue', last: 'تمت إحالة طلبكم إلى القسم المختص لدراسة الموضوع.', date: 'قبل ساعتين' },
    { no: 'SB-2026-1009', type: 'قضية عمالية', dept: 'قسم القضايا العمالية', status: 'بانتظار مستندات', tone: 'b-amber', last: 'يرجى إرفاق عقد العمل ومسير الرواتب لاستكمال الدراسة.', date: 'أمس' },
    { no: 'SB-2026-0950', type: 'استشارة قانونية عامة', dept: 'قسم الاستشارات العامة', status: 'بانتظار حجز الاستشارة', tone: 'b-amber', last: 'تمت دراسة طلبكم مبدئياً، الرجاء حجز استشارة لاستكمال الرأي.', date: 'قبل 4 أيام' },
    { no: 'SB-2026-0987', type: 'نزاع عقاري', dept: 'القسم العقاري', status: 'مكتملة', tone: 'b-green', last: 'تم الانتهاء من الموضوع وإرسال ملخص الاستشارة.', date: 'قبل أسبوع' },
  ] as Ticket[],
  cases: [
    { no: 'ق-2026-0211', type: 'تجاري', status: 'منظورة', tone: 'b-blue', update: 'جلسة قادمة الخميس 02 يوليو' },
    { no: 'ق-2026-0118', type: 'عمالي', status: 'قيد التحضير', tone: 'b-amber', update: 'إعداد مذكرة الرد على الدعوى' },
    { no: 'ق-2025-0904', type: 'عقاري', status: 'مغلقة', tone: 'b-green', update: 'صدور حكم نهائي لصالح العميل' },
  ] as Case[],
  execs: [
    { no: 'تنفيذ-5521', subject: 'تنفيذ حكم مالي', status: 'جارٍ', tone: 'b-blue', last: 'تقديم طلب حجز تحفظي على الحسابات' },
    { no: 'تنفيذ-5440', subject: 'تنفيذ سند لأمر', status: 'مكتمل', tone: 'b-green', last: 'تم تحصيل كامل المبلغ' },
  ] as Exec[],
  appts: [
    { id: 'AP1', type: 'مرئية', ico: 'video', lawyer: 'أ. سارة القحطاني', day: 'الاثنين 29 يونيو 2026', time: '11:30 ص', branch: 'اجتماع إلكتروني', status: 'مؤكد', tone: 'b-green', when: 'up' },
    { id: 'AP2', type: 'حضورية', ico: 'office', lawyer: 'أ. خالد المالكي', day: 'الأربعاء 01 يوليو 2026', time: '01:00 م', branch: 'الرياض — حي العليا', status: 'مؤكد', tone: 'b-green', when: 'up' },
    { id: 'AP3', type: 'حضورية', ico: 'office', lawyer: 'أ. ريم الزهراني', day: 'الجمعة 12 يونيو 2026', time: '10:00 ص', branch: 'جدة — حي الروضة', status: 'منتهٍ', tone: 'b-grey', when: 'past' },
  ] as Appt[],
  meetings: [
    { title: 'استشارة مرئية — نزاع تجاري', when: 'الاثنين 29 يونيو · 11:30 ص', up: true, link: 'https://salaselbabel.net/M-1', minutes: null, summary: null },
    { title: 'استشارة مرئية — نزاع عقاري', when: 'الجمعة 12 يونيو · 10:00 ص', up: false, link: '', minutes: 'محضر معتمد', summary: 'ملخص معتمد' },
  ] as Meeting[],
  docsUp: [
    { name: 'عقد_التوريد.pdf', meta: 'PDF · 1.2MB · تذكرة SB-2026-1042' },
    { name: 'الهوية_الوطنية.jpg', meta: 'صورة · 480KB' },
    { name: 'مراسلات_البريد.pdf', meta: 'PDF · 760KB' },
  ] as DocItem[],
  docsOut: [
    { name: 'ملخص_الاستشارة.pdf', meta: 'صادر · معتمد · 14 يونيو' },
    { name: 'مذكرة_قانونية.pdf', meta: 'صادر · معتمد · 14 يونيو' },
    { name: 'بطاقة_الموعد.pdf', meta: 'صادر · 12 يونيو' },
  ] as DocItem[],
  invoices: [
    { no: 'INV-2026-312', desc: 'أتعاب قضية · CASE-2026-0001', amount: 23000, status: 'مستحقة', tone: 'b-amber', due: 'تستحق قبل 02 يوليو', paid: false },
    { no: 'INV-2026-309', desc: 'استشارة هاتفية · الأحوال الشخصية', amount: 345, status: 'مستحقة', tone: 'b-amber', due: 'تستحق قبل 01 يوليو', paid: false },
    { no: 'INV-2026-305', desc: 'مراجعة عقد · العقود والاتفاقيات', amount: 460, status: 'مستحقة', tone: 'b-amber', due: 'تستحق قبل 29 يونيو', paid: false },
    { no: 'INV-2026-301', desc: 'استشارة مرئية · القسم التجاري', amount: 518, status: 'مستحقة', tone: 'b-amber', due: 'تستحق قبل 30 يونيو', paid: false },
    { no: 'INV-2026-296', desc: 'اجتماع فريق قضية · عمالي', amount: 805, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 22 يونيو', paid: true },
    { no: 'INV-2026-288', desc: 'استشارة حضورية · القسم العقاري', amount: 690, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 20 يونيو', paid: true },
    { no: 'INV-2026-275', desc: 'مراجعة مستند · الشركات', amount: 402, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 15 يونيو', paid: true },
    { no: 'INV-2026-260', desc: 'استشارة مرئية · البنوك والتمويل', amount: 575, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 10 يونيو', paid: true },
    { no: 'INV-2026-244', desc: 'أتعاب تنفيذ · التنفيذ', amount: 1150, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 05 يونيو', paid: true },
    { no: 'INV-2026-231', desc: 'استشارة هاتفية · الملكية الفكرية', amount: 299, status: 'مدفوعة', tone: 'b-green', due: 'سُددت في 01 يونيو', paid: true },
  ] as Invoice[],
  notifs: [
    { ic: 'ticket', tone: 't-blue', text: 'تم تحديث حالة التذكرة <b>SB-2026-1042</b> إلى «قيد التحليل».', time: 'قبل ساعتين', unread: true },
    { ic: 'cal', tone: 't-green', text: 'تم تأكيد موعدك يوم <b>الاثنين 29 يونيو</b> الساعة 11:30 ص.', time: 'أمس', unread: true },
    { ic: 'video', tone: 't-cyan', text: 'تم اعتماد ملخص اجتماعك ويمكنك الاطلاع عليه في قسم الاجتماعات.', time: 'قبل يومين', unread: false },
    { ic: 'card', tone: 't-amber', text: 'فاتورة <b>INV-2026-301</b> مستحقة السداد قبل 30 يونيو.', time: 'قبل 3 أيام', unread: true },
  ] as Notif[],
};

// تفاصيل القضايا — يطابق CASE_DETAILS في الأصل
export interface CaseDetail { next: string; update: string; invoice: string; paid: string; }
export const CASE_DETAILS: Record<string, CaseDetail> = {
  'ق-2026-0211': { next: 'الخميس 02 يوليو · 10:00 ص', update: 'تم تقديم مذكرة وتحديد جلسة', invoice: 'أتعاب القضية 23,000 ر.س — مدفوعة', paid: 'دفعة أولى 5,000 · ثانية 5,000 · ثالثة 13,000' },
  'ق-2026-0118': { next: 'لم تُحدد بعد', update: 'إعداد مذكرة الرد على الدعوى', invoice: 'أتعاب القضية 17,250 ر.س — دفعة مستحقة', paid: 'دفعة أولى 5,000 (مدفوعة)' },
  'ق-2025-0904': { next: '—', update: 'صدور حكم نهائي وإغلاق القضية', invoice: 'أتعاب القضية 28,750 ر.س — مدفوعة بالكامل', paid: 'سُددت كامل الدفعات' },
};

// العدادات المشتقة — مطابقة للأصل
export const openTickets = DATA.tickets.filter((t) => t.status !== 'مكتملة').length;
export const upAppts = DATA.appts.filter((a) => a.when === 'up').length;
export const upMeet = DATA.meetings.filter((m) => m.up).length;
export const dueInv = DATA.invoices.filter((i) => !i.paid).length;
// شارة الإشعارات صارت عدّاً حقيقياً من الخادم (unreadNotifications) تُحقن في Sidebar — لا ثابت هنا

// ── التنقل (NAV) ومسارات Inertia المقابلة ──
// كل عنصر: [icon, label, view] ؛ view نربطه بمسار /view
export interface NavItem { icon: string; label: string; view: string; badge?: number; alert?: boolean; }
export interface NavGroup { g: string; items: NavItem[]; }

export const NAV: NavGroup[] = [
  { g: 'لوحة المعلومات', items: [{ icon: 'home', label: 'الرئيسية', view: 'home' }] },
  { g: 'طلباتي', items: [
    { icon: 'ticket', label: 'فتح تذكرة', view: 'newticket' },
    { icon: 'folder', label: 'متابعة التذاكر', view: 'tickets', badge: openTickets },
    { icon: 'scale', label: 'القضايا النشطة', view: 'cases' },
    { icon: 'exec', label: 'التنفيذ', view: 'execs' },
    { icon: 'office', label: 'مخاطباتي', view: 'mycorr' },
  ] },
  { g: 'الاستشارات', items: [
    { icon: 'calplus', label: 'حجز استشارة', view: 'book' },
    { icon: 'folder', label: 'استشاراتي', view: 'myconsults' },
    { icon: 'cal', label: 'المواعيد', view: 'appts', badge: upAppts },
    { icon: 'video', label: 'الاجتماعات', view: 'meetings' },
    { icon: 'video', label: 'دعوات الاجتماعات', view: 'meetreqs' },
    { icon: 'calgrid', label: 'التقويم', view: 'calendar' },
  ] },
  { g: 'الملفات والمالية', items: [
    { icon: 'doc', label: 'المستندات', view: 'docs' },
    { icon: 'card', label: 'الفواتير', view: 'invoices', badge: dueInv, alert: true },
  ] },
  { g: 'الحساب', items: [
    { icon: 'bell', label: 'الإشعارات', view: 'notifications', alert: true },
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
  { icon: 'cal', title: 'المواعيد', sub: 'مواعيدك القادمة والسابقة', view: 'appts' },
  { icon: 'video', title: 'الاجتماعات', sub: 'الروابط والمحاضر المعتمدة', view: 'meetings' },
  { icon: 'doc', title: 'المستندات', sub: 'المرفوعة والصادرة إليك', view: 'docs' },
  { icon: 'card', title: 'الفواتير', sub: 'المستحقة والمدفوعة', view: 'invoices' },
  { icon: 'calgrid', title: 'التقويم', sub: 'نظرة شهرية على ارتباطاتك', view: 'calendar' },
  { icon: 'bell', title: 'الإشعارات', sub: 'آخر التحديثات', view: 'notifications' },
  { icon: 'user', title: 'الملف الشخصي', sub: 'بياناتك وأمان حسابك', view: 'profile' },
];

// العناوين والمسارات (TITLES): view -> [title, crumb]
export const TITLES: Record<string, [string, string]> = {
  home: ['الرئيسية', 'منصة العميل'],
  newticket: ['فتح تذكرة جديدة', 'طلباتي'],
  tickets: ['متابعة التذاكر', 'طلباتي'],
  cases: ['القضايا النشطة', 'طلباتي'],
  execs: ['التنفيذ', 'طلباتي'],
  mycorr: ['مخاطباتي', 'طلباتي'],
  book: ['حجز استشارة', 'الاستشارات'],
  appts: ['المواعيد', 'الاستشارات'],
  meetings: ['الاجتماعات', 'الاستشارات'],
  calendar: ['التقويم', 'الاستشارات'],
  docs: ['المستندات', 'الملفات والمالية'],
  invoices: ['الفواتير والمدفوعات', 'الملفات والمالية'],
  notifications: ['الإشعارات', 'الحساب'],
  profile: ['الملف الشخصي', 'الحساب'],
  myconsults: ['استشاراتي', 'الاستشارات'],
  meetreqs: ['دعوات الاجتماعات', 'الاستشارات'],
};

// خريطة view -> مسار URL (Inertia)
export const VIEW_ROUTE: Record<string, string> = {
  home: '/dashboard',
  newticket: '/tickets/new',
  tickets: '/tickets',
  cases: '/cases',
  execs: '/execs',
  mycorr: '/mycorr',
  book: '/book',
  myconsults: '/myconsults',
  appts: '/appointments',
  meetings: '/meetings',
  meetreqs: '/meetreqs',
  calendar: '/calendar',
  docs: '/documents',
  invoices: '/invoices',
  notifications: '/notifications',
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
export const SHARED_ACCOUNT_ROUTES = ['/notifications', '/profile'];

// لوحة العرض الصحيحة: للصفحات المشتركة نعتمد دور المستخدم الفعليّ (auth.user.role)،
// ولغيرها نشتقّ الدور من المسار (يُبقي مبدّل لوحات الإدارة سليماً).
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

// شريط دور الموظف — يطابق ROLE_DEFS.employee.nav
const EMPLOYEE_NAV: SideGroup[] = [
  { g: 'التشغيل', items: [
    { icon: 'home', label: 'الرئيسية', route: '/employee/dashboard' },
    { icon: 'folder', label: 'التذاكر', route: '/employee/tickets' },
    { icon: 'scale', label: 'القضايا', route: '/employee/cases' },
    { icon: 'exec', label: 'التنفيذ', route: '/employee/execs' },
    { icon: 'scale', label: 'إدارة الاستشارات', route: '/employee/consults' },
    { icon: 'cal', label: 'جدولة المواعيد', route: '/employee/schedule' },
    { icon: 'reply', label: 'التحويلات', route: '/employee/transfer' },
    { icon: 'video', label: 'الاجتماعات', route: '/employee/meetings' },
    { icon: 'video', label: 'طلبات الاجتماعات', route: '/employee/meetreqs' },
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/employee/consultrecv' },
  ] },
];

// شريط دور المحامي — يطابق ROLE_DEFS.lawyer.nav
const LAWYER_NAV: SideGroup[] = [
  { g: 'العمل القانوني', items: [
    { icon: 'home', label: 'الرئيسية', route: '/lawyer/dashboard' },
    { icon: 'folder', label: 'التذاكر', route: '/lawyer/tickets' },
    { icon: 'scale', label: 'قضاياي', route: '/lawyer/cases' },
    { icon: 'exec', label: 'التنفيذ', route: '/lawyer/execs' },
    { icon: 'office', label: 'المخاطبات', route: '/lawyer/correspondences' },
    { icon: 'video', label: 'الاجتماعات', route: '/lawyer/meetings' },
    { icon: 'video', label: 'طلبات الاجتماعات', route: '/lawyer/meetreqs' },
    { icon: 'calgrid', label: 'التقويم', route: '/lawyer/calendar' },
  ] },
  { g: 'الأدوات', items: [
    { icon: 'doc', label: 'المساعد القانوني', route: '/lawyer/assistant' },
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/lawyer/consultrecv' },
    { icon: 'out', label: 'الملخصات', route: '/lawyer/summaries' },
    { icon: 'exec', label: 'المهام', route: '/lawyer/tasks' },
  ] },
];

// شريط دور الإدارة — يطابق ROLE_DEFS.admin.nav
const ADMIN_NAV: SideGroup[] = [
  { g: 'الإشراف', items: [
    { icon: 'home', label: 'الرئيسية', route: '/admin/dashboard' },
    { icon: 'user', label: 'العملاء', route: '/admin/clients' },
    { icon: 'folder', label: 'التذاكر', route: '/admin/tickets' },
    { icon: 'scale', label: 'كل القضايا', route: '/admin/cases' },
    { icon: 'exec', label: 'التنفيذ', route: '/admin/execs' },
    { icon: 'office', label: 'المخاطبات', route: '/admin/correspondences' },
    { icon: 'scale', label: 'المحامون', route: '/admin/lawyers' },
    { icon: 'scale', label: 'إدارة الاستشارات', route: '/admin/consults' },
    { icon: 'card', label: 'طلبات الاستشارات', route: '/admin/consult-requests' },
  ] },
  { g: 'الإدارة العليا', items: [
    { icon: 'user', label: 'تسجيل الموظفين', route: '/admin/staff' },
    { icon: 'office', label: 'الفروع', route: '/admin/branches' },
    { icon: 'video', label: 'أرشيف الاستشارات', route: '/admin/archive' },
    { icon: 'reply', label: 'توزيع التذاكر', route: '/admin/distribute' },
    { icon: 'card', label: 'أتعاب القضايا', route: '/admin/casefees' },
    { icon: 'exec', label: 'مهام العمل', route: '/admin/tasks' },
  ] },
  { g: 'العمليات', items: [
    { icon: 'compass', label: 'استقبال الاستشارات', route: '/admin/consultrecv' },
    { icon: 'calgrid', label: 'إدارة الاجتماعات', route: '/admin/meetmgmt' },
    { icon: 'video', label: 'طلبات الاجتماعات', route: '/admin/meetreqs' },
    { icon: 'folder', label: 'سجل الاجتماعات', route: '/admin/meetlog' },
    { icon: 'bell', label: 'إشعارات العملاء', route: '/admin/clientnotifs' },
    { icon: 'video', label: 'الاجتماعات', route: '/admin/meetings' },
    { icon: 'out', label: 'الملخصات', route: '/admin/summaries' },
  ] },
  { g: 'المالية والتقارير', items: [
    { icon: 'card', label: 'الإيرادات', route: '/admin/revenue' },
    { icon: 'card', label: 'أسعار الاستشارات', route: '/admin/prices' },
    { icon: 'card', label: 'الفواتير والمحاسبة', route: '/admin/accounting' },
    { icon: 'cal', label: 'تقارير الاجتماعات', route: '/admin/meetreports' },
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
// مسارات إضافية لدور العميل (المحادثات)

const EMPLOYEE_TITLES: Record<string, [string, string]> = {
  '/employee/dashboard': ['الرئيسية', 'لوحة الموظف'],
  '/employee/tickets': ['التذاكر', 'لوحة الموظف'],
  '/employee/cases': ['القضايا', 'لوحة الموظف'],
  '/employee/execs': ['التنفيذ', 'لوحة الموظف'],
  '/employee/consults': ['إدارة الاستشارات', 'لوحة الموظف'],
  '/employee/consult': ['رحلة الاستشارة', 'لوحة الموظف'],
  '/employee/schedule': ['جدولة المواعيد', 'لوحة الموظف'],
  '/employee/transfer': ['التحويلات', 'لوحة الموظف'],
  '/employee/meetreqs': ['طلبات الاجتماعات', 'لوحة الموظف'],
  '/employee/meetings': ['الاجتماعات', 'لوحة الموظف'],
  '/employee/meeting': ['تفاصيل الاجتماع', 'لوحة الموظف'],
  '/employee/consultrecv': ['استقبال الاستشارات', 'لوحة الموظف'],
  '/employee/videoroom': ['غرفة الجلسة المرئية', 'لوحة الموظف'],
};

const LAWYER_TITLES: Record<string, [string, string]> = {
  '/lawyer/dashboard': ['الرئيسية', 'لوحة المحامي'],
  '/lawyer/tickets': ['التذاكر', 'لوحة المحامي'],
  '/lawyer/cases': ['قضاياي', 'لوحة المحامي'],
  '/lawyer/execs': ['التنفيذ', 'لوحة المحامي'],
  '/lawyer/correspondences': ['المخاطبات', 'لوحة المحامي'],
  '/lawyer/meetings': ['الاجتماعات', 'لوحة المحامي'],
  '/lawyer/meetreqs': ['طلبات الاجتماعات', 'لوحة المحامي'],
  '/lawyer/calendar': ['التقويم', 'لوحة المحامي'],
  '/lawyer/assistant': ['المساعد القانوني الذكي', 'لوحة المحامي'],
  '/lawyer/consultrecv': ['استقبال الاستشارات', 'لوحة المحامي'],
  '/lawyer/consult': ['رحلة الاستشارة', 'لوحة المحامي'],
  '/lawyer/summaries': ['الملخصات', 'لوحة المحامي'],
  '/lawyer/tasks': ['المهام', 'لوحة المحامي'],
  '/lawyer/videoroom': ['غرفة الجلسة المرئية', 'لوحة المحامي'],
};

const ADMIN_TITLES: Record<string, [string, string]> = {
  '/admin/dashboard': ['الرئيسية', 'لوحة الإدارة'],
  '/admin/clients': ['العملاء', 'لوحة الإدارة'],
  '/admin/tickets': ['التذاكر', 'لوحة الإدارة'],
  '/admin/lawyers': ['المحامون', 'لوحة الإدارة'],
  '/admin/consults': ['إدارة الاستشارات', 'الإدارة العليا'],
  '/admin/consult-requests': ['طلبات الاستشارات', 'الإدارة العليا'],
  '/admin/consult': ['رحلة الاستشارة', 'الإدارة العليا'],
  '/admin/staff': ['تسجيل الموظفين', 'الإدارة العليا'],
  '/admin/branches': ['الفروع', 'الإدارة العليا'],
  '/admin/archive': ['أرشيف الاستشارات', 'الإدارة العليا'],
  '/admin/distribute': ['توزيع التذاكر', 'الإدارة العليا'],
  '/admin/casefees': ['أتعاب القضايا', 'الإدارة العليا'],
  '/admin/cases': ['كل القضايا', 'لوحة الإدارة'],
  '/admin/execs': ['التنفيذ', 'لوحة الإدارة'],
  '/admin/correspondences': ['المخاطبات', 'لوحة الإدارة'],
  '/admin/tasks': ['مهام العمل', 'الإدارة العليا'],
  '/admin/meetmgmt': ['إدارة الاجتماعات', 'الإدارة العليا'],
  '/admin/meetreqs': ['طلبات الاجتماعات', 'لوحة الإدارة'],
  '/admin/meetlog': ['سجل الاجتماعات', 'الإدارة العليا'],
  '/admin/clientnotifs': ['إشعارات العملاء', 'الإدارة العليا'],
  '/admin/meetings': ['اعتماد الاجتماعات', 'لوحة الإدارة'],
  '/admin/summaries': ['اعتماد الملخصات', 'لوحة الإدارة'],
  '/admin/revenue': ['الإيرادات', 'لوحة الإدارة'],
  '/admin/prices': ['أسعار الاستشارات', 'الإدارة العليا'],
  '/admin/accounting': ['الفواتير والمحاسبة', 'الإدارة العليا'],
  '/admin/meetreports': ['تقارير الاجتماعات', 'الإدارة العليا'],
  '/admin/reports': ['التقارير', 'لوحة الإدارة'],
  '/admin/consultrecv': ['استقبال الاستشارات', 'لوحة الإدارة'],
  '/admin/videoroom': ['غرفة الجلسة المرئية', 'لوحة الإدارة'],
};

export const ROLE_TITLES: Record<string, Record<string, [string, string]>> = {
  client: CLIENT_TITLES,
  employee: EMPLOYEE_TITLES,
  lawyer: LAWYER_TITLES,
  admin: ADMIN_TITLES,
};

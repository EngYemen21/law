// ============================================================
// بيانات دور الموظف — مستخرجة حرفياً من index (82).html
// SYS_TICKETS / STAFF / MEET_REQUESTS / CONSULTS ... إلخ
// ============================================================
//
// ⚠️ ثوابت غير مستعملة (تدقيق 2026-08-21) — بيانات عرض بقيت من مرحلة النموذج الثابت،
//    والشاشات صارت تقرأ من الخادم عبر خصائص Inertia. مُحتفَظ بها بقرار «لا حذف»:
//   EM_QUICK · SCHEDULE_TIMES · TRANSFERS · TICKET_THREADS
//    لا تبنِ عليها شيئاً: قيمها ثابتة ولا تعكس القاعدة.

import type { Message } from '@/lib/chat';

// ── تذاكر النظام (دور الموظف) — يطابق SYS_TICKETS ──
export interface SysTicket {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
}

export const SYS_TICKETS: SysTicket[] = [
  { no: 'SB-2026-1042', client: 'عبدالله العتيبي', type: 'استشارة قانونية', dept: 'القضايا التجارية', lawyer: 'أ. سارة القحطاني', status: 'قيد التحليل', tone: 'b-blue' },
  { no: 'SB-2026-1009', client: 'نورة الدوسري', type: 'متابعة قضية', dept: 'القضايا العمالية', lawyer: 'أ. سارة القحطاني', status: 'بانتظار مستندات', tone: 'b-amber' },
  { no: 'SB-2026-1003', client: 'شركة الأفق', type: 'مراجعة مستند', dept: 'العقود والاتفاقيات', lawyer: '—', status: 'جديدة', tone: 'b-grey' },
  { no: 'SB-2026-0987', client: 'فهد الشهري', type: 'استشارة قانونية', dept: 'العقارات', lawyer: 'أ. خالد المالكي', status: 'مغلقة', tone: 'b-grey' },
  { no: 'SB-2026-0950', client: 'عبدالله العتيبي', type: 'استفسار قانوني', dept: 'الاستشارات القانونية', lawyer: 'أ. ريم الزهراني', status: 'بانتظار حجز الاستشارة', tone: 'b-amber' },
];

// ── الأدلة (LAWYERS / CLIENTS / TICKET_STATES) ──
// DEPTS أُزيلت (2026-09-14): أقسام المحامين والتحويل من كتالوج الأقسام في قاعدة البيانات،
// وأقسام الموظّفين الإداريّة من جدول staff_departments — يمرّرها الخادم.

// مفردات حالة التذكرة ونغماتها تأتي من App\Support\TicketJourney::options() كخاصية من الخادم.
// كانت مكتوبة هنا يدوياً فأسقطت 9 من 13 حالة حقيقية — منها «قيد التحليل» حالة كل تذكرة جديدة.

export interface Lawyer { name: string; depts: string[]; active: number; mode: string; }
export const LAWYERS: Lawyer[] = [
  { name: 'أ. سارة القحطاني', depts: ['القضايا التجارية', 'القضايا العمالية'], active: 7, mode: 'تلقائي' },
  { name: 'أ. خالد المالكي', depts: ['العقارات', 'التنفيذ'], active: 5, mode: 'يدوي' },
  { name: 'أ. ريم الزهراني', depts: ['الأحوال الشخصية', 'التركات والأوقاف'], active: 4, mode: 'تلقائي' },
  { name: 'أ. ماجد العتيبي', depts: ['القضايا الجنائية', 'الجرائم المعلوماتية'], active: 3, mode: 'تلقائي' },
];

export interface Client { name: string; id: string; mobile: string; tickets: number; status: string; }
export const CLIENTS: Client[] = [
  { name: 'عبدالله محمد العتيبي', id: '1•••••••234', mobile: '05•••••12', tickets: 4, status: 'نشط' },
  { name: 'نورة سعد الدوسري', id: '1•••••••871', mobile: '05•••••44', tickets: 2, status: 'نشط' },
  { name: 'شركة الأفق التجارية', id: '7•••••••03', mobile: '05•••••90', tickets: 6, status: 'نشط' },
  { name: 'فهد علي الشهري', id: '1•••••••556', mobile: '05•••••17', tickets: 1, status: 'موقوف' },
];

// ── الموظفون (STAFF) — يطابق STAFF في الأصل ──
export interface Staff {
  name: string; role: string; dept: string; pay: string;
  salary: number; status: string; perms: string[]; email: string;
  mobile: string; nid: string; join: string; start: string; end: string;
}
export const STAFF: Staff[] = [
  { name: 'منيرة الحربي', role: 'موظف خدمة عملاء', dept: 'خدمة العملاء', pay: 'راتب ثابت: 7,000 ر.س/شهري', salary: 7000, status: 'نشط', perms: ['إدارة التذاكر', 'الرد على العملاء', 'جدولة المواعيد', 'تحويل التذاكر'], email: 'm.harbi@salasel.sa', mobile: '0551234501', nid: '1023456789', join: '2025-09-01', start: '08:00', end: '16:00' },
  { name: 'أ. سارة القحطاني', role: 'محامٍ', dept: 'القضايا التجارية', pay: 'راتب 12,000 ر.س + نسبة 10%', salary: 12000, status: 'نشط', perms: ['المساعد القانوني', 'اعتماد الملخصات', 'إدارة القضايا والأتعاب'], email: 's.qahtani@salasel.sa', mobile: '0551234502', nid: '1098765432', join: '2024-03-15', start: '09:00', end: '17:00' },
  { name: 'أ. خالد المالكي', role: 'محامٍ', dept: 'العقارات', pay: 'بالجلسة: 800 ر.س/جلسة', salary: 0, status: 'نشط', perms: ['المساعد القانوني', 'إدارة القضايا والأتعاب'], email: 'k.malki@salasel.sa', mobile: '0551234503', nid: '1055667788', join: '2025-01-10', start: '10:00', end: '18:00' },
];

// ── الردود السريعة (EM_QUICK) ──
export const EM_QUICK = [
  'تم استلام طلبكم وجارٍ تحويله للقسم المختص.',
  'نأمل تزويدنا بالمستندات المطلوبة لاستكمال الدراسة.',
  'تمت جدولة موعد استشارتكم وسيصلكم إشعار التأكيد.',
  'نشكر تواصلكم، تم تحديث حالة طلبكم وسنوافيكم بالمستجدات.',
];

// أوقات الجدولة — يطابق times في emScheduleView
export const SCHEDULE_TIMES = ['10:00 ص', '11:30 ص', '01:00 م', '02:30 م', '04:00 م'];

// سجل التحويلات — يطابق المصفوفة في emTransferView
export const TRANSFERS: [string, string, string, string][] = [
  ['SB-2026-1042', '→ القضايا التجارية', 'أ. سارة القحطاني', 'تلقائي'],
  ['SB-2026-1009', '→ القضايا العمالية', 'أ. سارة القحطاني', 'يدوي'],
  ['SB-2026-0950', '→ الاستشارات القانونية', 'أ. ريم الزهراني', 'تلقائي'],
];

// ── محادثات التذاكر (TICKET_THREADS) — تتضمن staff + note ──
export const TICKET_THREADS: Record<string, Message[]> = {
  'SB-2026-1042': [
    { who: 'client', name: 'عبدالله العتيبي', role: 'العميل', time: '10:02 ص', text: 'لدينا نزاع تجاري مع أحد المورّدين بسبب إخلاله بشروط التوريد المتفق عليها، ونرغب بدراسة موقفنا القانوني والإجراءات الممكنة.' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', time: '10:03 ص', text: 'تم استلام طلبكم بنجاح. يرجى إرفاق المستندات المتعلقة بالموضوع إن وجدت، وسيُحال الطلب إلى القسم المختص للمراجعة.' },
    { who: 'client', name: 'عبدالله العتيبي', role: 'العميل', time: '10:15 ص', text: '<p>تم إرفاق عقد التوريد والمراسلات.</p><div class="doc-list"><span class="doc-chip">📎 عقد_التوريد.pdf</span><span class="doc-chip">📎 مراسلات.pdf</span></div>' },
    { who: 'staff', name: 'منيرة الحربي', role: 'خدمة العملاء', time: '10:21 ص', text: 'شكراً لتعاونكم. تم استلام المستندات وإحالة طلبكم إلى القسم التجاري لدراسته، وسنوافيكم بالرد داخل التذكرة.' },
    { who: 'note', name: 'منيرة الحربي', role: 'ملاحظة داخلية', time: '10:22 ص', text: 'أُحيلت للقسم التجاري — أ. سارة القحطاني. المرفقات مكتملة. بانتظار الملخص القانوني.' },
  ],
  'SB-2026-1009': [
    { who: 'client', name: 'نورة الدوسري', role: 'العميل', time: 'أمس · 02:41 م', text: 'لدي نزاع عمالي مع جهة العمل بخصوص مستحقات نهاية الخدمة، وأرغب بمعرفة موقفي القانوني.' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', time: 'أمس · 02:42 م', text: 'تم استلام طلبكم. لاستكمال الدراسة نحتاج بعض المستندات الأساسية.' },
    { who: 'staff', name: 'منيرة الحربي', role: 'خدمة العملاء', time: 'أمس · 03:10 م', text: 'للتمكن من دراسة طلبكم، نأمل تزويدنا بعقد العمل ومسير الرواتب لآخر ثلاثة أشهر.' },
    { who: 'client', name: 'نورة الدوسري', role: 'العميل', time: 'أمس · 05:25 م', text: '<p>تم إرفاق عقد العمل، وسأرفق مسير الرواتب قريباً.</p><div class="doc-list"><span class="doc-chip">📎 عقد_العمل.pdf</span></div>' },
    { who: 'note', name: 'منيرة الحربي', role: 'ملاحظة داخلية', time: 'أمس · 05:30 م', text: 'العميلة أرفقت عقد العمل فقط؛ بانتظار مسير الرواتب لإكمال الإحالة للقسم العمالي.' },
  ],
  'SB-2026-1003': [
    { who: 'client', name: 'شركة الأفق التجارية', role: 'العميل', time: '09:12 ص', text: 'نرغب بمراجعة عقد توريد قبل توقيعه وإبداء الملاحظات القانونية على بنوده، خاصة بنود الغرامات والإنهاء.' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', time: '09:13 ص', text: 'تم استلام طلبكم. سيتولى أحد موظفي خدمة العملاء متابعة طلبكم وإحالته للقسم المختص.' },
  ],
  'SB-2026-0950': [
    { who: 'client', name: 'عبدالله العتيبي', role: 'العميل', time: 'قبل 4 أيام', text: 'لدي استفسار قانوني عام حول صياغة اتفاقية شراكة، وأرغب بمعرفة الخطوات.' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', time: 'قبل 4 أيام', text: 'تم استلام استفساركم وتمت دراسته مبدئياً.' },
    { who: 'staff', name: 'منيرة الحربي', role: 'خدمة العملاء', time: 'قبل 4 أيام', text: 'لاستكمال الرأي القانوني نأمل حجز استشارة قانونية مع المختص، ويمكنكم ذلك من قسم «حجز استشارة».' },
  ],
};

export function defaultThread(t: SysTicket): Message[] {
  return [
    { who: 'client', name: t.client, role: 'العميل', time: 'اليوم', text: 'مرحباً، أرغب بمتابعة طلبي ومعرفة المستجدات.' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', time: 'اليوم', text: 'تم استلام طلبكم وهو قيد المعالجة.' },
  ];
}

// بصمة IP ثابتة للتذكرة — يطابق ticketStamp(no)
export function ticketStamp(no: string): string {
  let h = 0;
  for (let i = 0; i < no.length; i++) h = (h * 31 + no.charCodeAt(i)) >>> 0;
  return `178.${20 + (h % 200)}.${(h >> 3) % 255}.${(h >> 7) % 255}`;
}

// ── دعوات الاجتماعات (MEET_REQUESTS) ──
export const MR_FLOW = ['بانتظار موافقة الإدارة', 'معتمدة ومنشورة للعميل', 'تنفيذ الجلسة', 'اعتماد المحضر والملخص'];

export interface MeetRequest {
  id: string; client: string; service: string; type: string;
  day: string; time: string; by: string; stage: number;
  meetId?: string; meetLink?: string;
}
export const MEET_REQUESTS: MeetRequest[] = [
  { id: 'MR-1042', client: 'عبدالله العتيبي', service: 'نزاع تجاري', type: 'استشارة مرئية', day: 'الاثنين 29 يونيو', time: '11:30 ص', by: 'منيرة الحربي (خدمة العملاء)', stage: 0 },
  { id: 'MR-1039', client: 'نورة الدوسري', service: 'قضية عمالية', type: 'استشارة هاتفية', day: 'الثلاثاء 30 يونيو', time: '10:00 ص', by: 'الإدارة العليا', stage: 0 },
  { id: 'MR-1035', client: 'شركة الأفق', service: 'مراجعة عقد', type: 'استشارة حضورية', day: 'الأربعاء 01 يوليو', time: '01:00 م', by: 'منيرة الحربي (خدمة العملاء)', stage: 1, meetId: 'SLS-204517', meetLink: 'https://salaselbabel.net/SLS-204517' },
  { id: 'MR-1028', client: 'فهد الشهري', service: 'تنفيذ حكم', type: 'استشارة مرئية', day: 'الأحد 28 يونيو', time: '09:00 ص', by: 'الإدارة العليا', stage: 2, meetId: 'SLS-338290', meetLink: 'https://salaselbabel.net/SLS-338290' },
];

// ── الاستشارات (CONSULTS) ──
export const CONSULT_FLOW = ['استقبال الاستشارة', 'مراجعة الموظف', 'معالجة الفريق القانوني', 'اعتماد الموظف', 'جاهزة/محالة للمحامي'];

/**
 * **رحلةُ الحجز والجلسة — الرحلة الثانية التي لم تكن.**
 *
 * `CONSULT_FLOW` أعلاه رحلةُ **الاستقبال**: استشارةٌ تُفتح عند الموظّف فيراجعها ويحلّلها
 * ويعتمدها ويُحيلها. أمّا الاستشارة القادمة من **حجزٍ على تذكرة** (`ConsultBooking::request`)
 * فلا تدخل هذا المسار قطّ — رحلتُها تسعيرٌ وسدادٌ وموعدٌ وجلسةٌ وملخّص.
 *
 * وكانت صفحة الرحلة تعرض لها الخطّ الأوّل، فتُبرَز «جاهزة/محالة للمحامي» لجلسةٍ
 * **انتهت واعتُمد ملخّصها وسُدّد ثمنها**. قِيس على `CN-2026-7173`: سجلّ تدقيقها يقول
 * تسعير ← سداد ← موعد ← جلسة، والشاشة تقول «معالجة الفريق القانوني».
 */
export const CONSULT_BOOKING_FLOW = ['طلب الاستشارة', 'التسعير والسداد', 'تحديد الموعد', 'انعقاد الجلسة', 'الملخّص والاعتماد'];

/**
 * مرحلةُ الاستشارة في رحلة الحجز — تُقاس بالحالة **وحالة الجلسة معاً**.
 *
 * الحالة وحدها لا تكفي: «منتهية» حالةُ استشارةٍ و«منتهية» حالةُ جلسة، والفرق بينهما
 * أنّ الأولى نهايةُ الملفّ والثانية نهايةُ الانعقاد.
 */
export function cBookingStage(status: string, session?: string): number {
  if (status === 'بانتظار التسعير') {
    return 0;
  }

  if (status === 'بانتظار السداد') {
    return 1;
  }

  if (status === 'بانتظار تحديد الموعد' || status === 'بانتظار اعتماد الموعد') {
    return 2;
  }

  if (session === 'جلسة جارية' || status === 'قيد الاستشارة') {
    return 3;
  }

  if (session === 'منتهية' || status === 'منتهية' || session === 'لم تُعقد' || status === 'لم يحضر') {
    return 4;
  }

  // موعدٌ مؤكَّدٌ لم تبدأ جلستُه بعد
  return 3;
}

/** `time` نصٌّ للعرض (١٢ ساعة + ص/م)، و`at` طابعٌ ISO للفرز — والقيود القديمة بلا `at`. */
export interface AuditEntry { user: string; field: string; before: string; after: string; time: string; at?: string | null; }
export interface Consult {
  ref: string; client: string; subject: string; type: string;
  priority: string; status: string; received: string; employee: string;
  lawyer: string; mins: number; aiDone: boolean; aiClass: string;
  aiSummary: string; aiLawyer: string; missing: string[]; audit: AuditEntry[];
  channel: string; session: string; when: string; place: string;
  phone: string; slink: string;
}

const RAW_CONSULTS: Omit<Consult, 'channel' | 'session' | 'when' | 'place' | 'phone' | 'slink'>[] = [
  { ref: 'CN-2026-1042', client: 'عبدالله محمد العتيبي', subject: 'نزاع تجاري مع مورّد', type: 'تجاري', priority: 'عالية', status: 'جديدة', received: 'اليوم 09:14 ص', employee: '—', lawyer: '—', mins: 6, aiDone: false, aiClass: '', aiSummary: '', aiLawyer: '', missing: [], audit: [] },
  { ref: 'CN-2026-1035', client: 'شركة الأفق التجارية', subject: 'مراجعة عقد توريد', type: 'تجاري', priority: 'عادية', status: 'بانتظار اعتماد الموظف', received: 'أمس 02:10 م', employee: 'منيرة الحربي', lawyer: '—', mins: 120, aiDone: true, aiClass: 'استشارة عقود تجارية', aiSummary: 'مراجعة بنود التوريد وتقييم مخاطر الإخلال واقتراح تعديلات تحمي الطرف.', aiLawyer: 'أ. سارة القحطاني', missing: ['نسخة العقد الموقّعة'], audit: [{ user: 'النظام', field: 'تحليل الفريق القانوني', before: '—', after: 'اكتمل', time: 'أمس 02:30 م' }] },
  { ref: 'CN-2026-1028', client: 'فهد علي الشهري', subject: 'طلب تنفيذ حكم', type: 'تنفيذ', priority: 'عالية', status: 'جاهزة للمحامي', received: 'أمس 11:00 ص', employee: 'منيرة الحربي', lawyer: '—', mins: 90, aiDone: true, aiClass: 'طلب تنفيذ حكم', aiSummary: 'تجهيز ملف التنفيذ ومتابعة الإجراءات لدى محكمة التنفيذ.', aiLawyer: 'أ. خالد المالكي', missing: [], audit: [{ user: 'منيرة الحربي', field: 'اعتماد التحليل', before: 'بانتظار اعتماد الموظف', after: 'جاهزة للمحامي', time: 'أمس 11:50 ص' }] },
  { ref: 'CN-2026-1020', client: 'نورة سعد الدوسري', subject: 'مطالبة مالية', type: 'تجاري', priority: 'عادية', status: 'محولة إلى قضية', received: 'قبل يومين', employee: 'منيرة الحربي', lawyer: 'أ. سارة القحطاني', mins: 140, aiDone: true, aiClass: 'مطالبة مالية', aiSummary: 'تحويلها إلى قضية مطالبة بعد تعذّر الحل الودي.', aiLawyer: 'أ. سارة القحطاني', missing: [], audit: [] },
];

// نفس منطق CONSULTS.forEach في الأصل (قناة/جلسة/محامٍ/موعد/مكان/هاتف/رابط)
const _CH = ['مرئية', 'هاتفية', 'حضورية'];
const _LW = ['أ. سارة القحطاني', 'أ. خالد المالكي', 'أ. ماجد العتيبي'];
export const CONSULTS: Consult[] = RAW_CONSULTS.map((c, i) => ({
  ...c,
  audit: [...c.audit],
  missing: [...c.missing],
  channel: _CH[i % 3],
  session: 'بانتظار الجلسة',
  lawyer: !c.lawyer || c.lawyer === '—' ? _LW[i % 3] : c.lawyer,
  when: c.received,
  place: `مقر المكتب · قاعة ${(i % 3) + 1}`,
  phone: `05•••••${10 + i}`,
  slink: `https://salaselbabel.net/CN-${c.ref.split('-').pop()}`,
}));

export const CONSULT_CHANNELS: [string, string][] = [
  ['all', 'الكل'], ['مرئية', 'مرئية (فيديو)'], ['حضورية', 'حضورية'], ['هاتفية', 'هاتفية'],
];

/**
 * **قنوات الاستشارة الثلاث — مصدرٌ واحد للمنتقيات** (نظير `Consult::CHANNELS` في الخادم،
 * ويحرس تطابقَهما `ConsultChannelCatalogueTest`). غيرُ `CONSULT_CHANNELS` أعلاه: تلك تبويباتُ
 * ترشيحٍ تبدأ بـ«الكل». وأيقونةُ كلّ قناة من `crChannelIcon` — لا تُكتب بجانبها مرّةً أخرى.
 */
export const CONSULT_CHANNEL_OPTIONS = ['حضورية', 'مرئية', 'هاتفية'] as const;

export type ConsultChannel = (typeof CONSULT_CHANNEL_OPTIONS)[number];

/** ما تُفتح عليه منتقيات القناة حين لا تحمل الاستشارة قناةً بعد. */
export const DEFAULT_CONSULT_CHANNEL: ConsultChannel = CONSULT_CHANNEL_OPTIONS[0];

// ── دوال مساعدة للاستشارات ──
export function cStage(s: string): number {
  const m: Record<string, number> = {
    'جديدة': 0, 'بانتظار استكمال البيانات': 1,
    // المرحلة 2 («معالجة الفريق القانوني») **لا تكون الحاليّة أبداً**: `analyze`
    // يسجّل «قيد معالجة الفريق القانوني» في سجلّ التدقيق ولا يكتبها في العمود، ثمّ
    // يكتب «بانتظار اعتماد الموظف» مباشرةً. فالخريطة كانت تحمل مفتاحاً لا يُطابَق،
    // والرحلة تقفز من 1 إلى 3. أُزيل المفتاح الميت وبقيت المرحلة في الخطّ لأنّ
    // العمل يقع فيها فعلاً — لكنّه لحظيّ لا حالةٌ تُحفَظ.
    'بانتظار اعتماد الموظف': 3,
    'جاهزة للمحامي': 4, 'محالة للمحامي': 4,
    // حالات دورة الجلسة: الاستشارة تجاوزت رحلة الاستقبال كاملةً، فتُعرض عند نهايتها
    // (كانت غائبة فترتدّ إلى 1، فتظهر استشارة منتهية وكأنها ما زالت في مرحلة المراجعة)
    'قيد الاستشارة': 4, 'منتهية': 4,
    // **«لم يحضر» كانت ترتدّ إلى صفر** — فتُعرض جلسةٌ لم تُعقد عند «استقبال
    // الاستشارة»، أي عند بداية رحلةٍ قطعتها كاملة. وهي نهايةُ مسارٍ لا بدايته.
    'لم يحضر': 4,
  };
  return s in m ? m[s] : 0;
}

/*
 * **مجموعات حالة الاستشارة أعلامٌ على البطاقة لا قوائم هنا** (خطّة «إزالة التعارض» — المرحلة ٢).
 * كانت ستُّ قوائم منسوخة من `Consult` تُفرز بها الشاشات؛ اليوم `Consult::toCard` يرسل:
 * `bookingStage` (دورة الحجز) · `isTerminal` (نهايةٌ تُعرض: منتهية/لم يحضر/ملغاة) · `isClosed`
 * (نهايةٌ تُقفل — «لم يحضر» ليست منها: إعادة الجدولة بابُ إنقاذها) · `sessionEnded`؛ وبطاقة العميل
 * `inBooking`. فتعديلُ مجموعةٍ في الخادم يصل كلّ شاشةٍ بلا نسخةٍ تنجرف.
 */

/**
 * **قاموس الأولويّة** — يطابق `Consult::PRIORITIES`.
 *
 * «عادية» أولويّةُ **تذكرة** لا استشارة: كان المرشِّح يعرضها ويُغفل «منخفضة» التي
 * تكتبها أزرار الدرج في الشاشة نفسها.
 */
export const CONSULT_PRIORITIES = ['عالية', 'متوسطة', 'منخفضة'];

/**
 * **تطبيع نصّ البحث — توأم `App\Support\SearchText::fold`** ويحرس تطابقهما `ArabicSearchTest`.
 *
 * كلّ ترشيحٍ في المتصفّح كان يقوم على `toLowerCase().includes(q)`، و`toLowerCase` لا أثر
 * لها على العربيّة: «احمد» لا يجد «أحمد»، و«محكمه» لا تجد «محكمة»، ومن يكتب `٢٠٢٦` —
 * وهو ما تكتبه لوحة المفاتيح العربيّة — لا يجد `SB-2026-1042`.
 */
const SEARCH_FOLD: Record<string, string> = {
  'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا',
  'ة': 'ه',
  'ى': 'ي', 'ئ': 'ي',
  'ؤ': 'و',
  'ـ': '',
  '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
  '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
  '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
};

/** يطبّع نصّاً للبحث: أرقاماً عربيّةً وهمزاتٍ وتاءً مربوطة وتطويلاً، مع خفض حالة الأحرف. */
export function foldSearch(raw: string): string {
  return (raw ?? '').replace(/[أإآٱةىئؤـ٠-٩۰-۹]/g, (c) => SEARCH_FOLD[c] ?? c).toLowerCase().trim();
}

/** أيطابق أيٌّ من الحقول نصَّ البحث؟ — نقطة الدخول الوحيدة للترشيح في المتصفّح. */
export function matchesSearch(query: string, ...fields: (string | null | undefined)[]): boolean {
  const q = foldSearch(query);
  if (q === '') {
    return true;
  }

  return fields.some((f) => foldSearch(String(f ?? '')).includes(q));
}

/**
 * **أولويّة التذكرة — نسخةٌ تطابق `App\Support\TicketJourney::PRIORITIES` حرفاً**،
 * ويحرس تطابقَهما `TicketPriorityCatalogueTest`.
 *
 * كانت كلّ شاشةٍ تكتب مفرداتها بيدها فتفرّقت: مرشّح المحامي «عاجلة/عادية/منخفضة» ولا
 * واحدةَ منها في القاعدة، وعدّادات الموظّف تفحص «حرجة/urgent/high»، والإدارة «عاجلة جداً».
 * خمس مفرداتٍ ميّتة، والكاتب الوحيد `'عالية'`.
 */
export const TICKET_PRIORITIES = ['عالية', 'متوسطة', 'منخفضة'];

/** الأولويّة الافتراضيّة — تطابق `TicketJourney::PRIORITY_DEFAULT` و`default` في الهجرة. */
export const TICKET_PRIORITY_DEFAULT = 'متوسطة';

/** الأولويّة العليا — تُقرأ في العدّادات والشارات بدل قوائم مفرداتٍ متفرّقة. */
export const TICKET_PRIORITY_URGENT = TICKET_PRIORITIES[0];

/** أهي عاجلة؟ نظير `TicketJourney::isUrgent` — مصدرٌ واحد للشارة والعدّاد والتبويب. */
export function isUrgentTicket(priority?: string | null): boolean {
  return (priority ?? '') === TICKET_PRIORITY_URGENT;
}

/** رتبة الفرز «الأعلى أولاً» — تطابق `TicketJourney::PRIORITY_RANK`. */
export function ticketPriorityRank(priority?: string | null): number {
  const i = TICKET_PRIORITIES.indexOf(priority ?? '');

  return i === -1 ? TICKET_PRIORITIES.length : i;
}
// يُنادى خارج دورة الحجز وحده (`isBooking` يفرز قبله) — فالملغاة وحدها بلا مرحلة
export function cHasStage(s: string): boolean {
  return s !== 'ملغاة';
}

export function crChannelIcon(ch: string): string {
  return ch === 'مرئية' ? 'video' : ch === 'هاتفية' ? 'phone' : 'office';
}
export function crChannelTone(ch: string): string {
  return ch === 'مرئية' ? 'b-blue' : ch === 'هاتفية' ? 'b-amber' : 'b-green';
}
// اسم العميل في لوحات الطاقم — صريح (يطابق Ticket::maskClient على الخادم). النسخة الوحيدة في الواجهة:
// كانت نسخةٌ مطابقة في `admin-data` تستوردها شاشات الإدارة
export function maskClient(name: string): string {
  // **لا تقنيع على الإدارة والمحامي والموظّف** (قرار المالك 2026-09-11). الاسمُ باقٍ لأنّ
  // الشاشات تناديه في مواضع كثيرة؛ تغييرُ السلوك هنا يغطّيها كلَّها دون أن يُنسى أحدُها.
  return name || '—';
}

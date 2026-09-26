// ============================================================
// بيانات دور الإدارة العليا (admin) — مستخرجة حرفياً من index (82).html
// تعيد استخدام البيانات المشتركة من employee-data حيث أمكن
// ============================================================
//
// ⚠️ ثوابت غير مستعملة (تدقيق 2026-08-21) — بيانات عرض بقيت من مرحلة النموذج الثابت،
//    والشاشات صارت تقرأ من الخادم عبر خصائص Inertia. مُحتفَظ بها بقرار «لا حذف»:
//   REVENUE · REV_BY_SVC · ARCHIVE · CASE_FEES · CLIENT_DIR · CONSULT_PRICES · DEFAULT_VAT_RATE ·
//   DEMO_CLIENT_NOTIFS · AD_ACTIVITY · INVOICES · INV_CLIENTS · VAT_NO · INV_IBAN
//    لا تبنِ عليها شيئاً: قيمها ثابتة ولا تعكس القاعدة.

import {
  CLIENTS,
  SYS_TICKETS,
  LAWYERS,
  STAFF,
  CONSULTS,
  type Consult,
} from '@/lib/employee-data';

export { CLIENTS, SYS_TICKETS, LAWYERS, STAFF, CONSULTS };
export type { Consult };

// ── إخفاء الأسماء في دور الإدارة: لا يُخفى شيء (تمرير مباشر) ──
// يطابق maskClient (ROLE!=='employee'&&ROLE!=='lawyer') و maskLawyer (ROLE!=='client')
export function maskClient(name: string): string {
  return name || '—';
}
export function maskLawyer(name: string): string {
  return name || '—';
}

// ── بيانات الرسوم البيانية (REVENUE / REV_BY_SVC) ──
export interface BarDatum { m: string; v: number; }
export const REVENUE: BarDatum[] = [
  { m: 'فبراير', v: 182 }, { m: 'مارس', v: 214 }, { m: 'أبريل', v: 268 },
  { m: 'مايو', v: 241 }, { m: 'يونيو', v: 312 },
];
export const REV_BY_SVC: BarDatum[] = [
  { m: 'استشارات', v: 420 }, { m: 'اجتماعات', v: 160 },
  { m: 'قضايا/أتعاب', v: 380 }, { m: 'مراجعة مستندات', v: 120 },
];

// ── أرشيف التسجيلات (ARCHIVE) ──
export interface ArchiveItem { ref: string; ctype: string; client: string; date: string; dur: string; }
export const ARCHIVE: ArchiveItem[] = [
  { ref: 'SB-2026-1042', ctype: 'استشارة مرئية', client: 'عبدالله العتيبي', date: '24 يونيو 2026', dur: '42:10' },
  { ref: 'SB-2026-0987', ctype: 'استشارة حضورية', client: 'فهد الشهري', date: '20 يونيو 2026', dur: '35:48' },
  { ref: 'SB-2026-0950', ctype: 'استشارة هاتفية', client: 'عبدالله العتيبي', date: '18 يونيو 2026', dur: '21:05' },
];

// ── أتعاب القضايا (CASE_FEES) ──
export interface CaseFee {
  caseNo: string; client: string; type: string;
  value: number; lawyerFee: number; pct: number; status: string;
}
export const CASE_FEES: CaseFee[] = [
  { caseNo: 'CASE-2026-0001', client: 'عبدالله العتيبي', type: 'دعوى مطالبة مالية', value: 0, lawyerFee: 0, pct: 15, status: 'بانتظار التحديد' },
  { caseNo: 'CASE-2026-0118', client: 'نورة الدوسري', type: 'قضية عمالية', value: 0, lawyerFee: 0, pct: 15, status: 'بانتظار التحديد' },
];

// ── المهام (TASKS) ──
export interface Task { title: string; ref: string; owner: string; due: string; status: string; tone: string; }
export const TASKS: Task[] = [
  { title: 'صياغة خطاب مطالبة', ref: 'SB-2026-1042', owner: 'أ. سارة القحطاني', due: '30 يونيو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'إعداد مذكرة الرد على الدعوى', ref: 'ق-2026-0118', owner: 'أ. سارة القحطاني', due: '02 يوليو', status: 'قيد العمل', tone: 'b-blue' },
  { title: 'تجهيز ملاحظات عقد شركة الأفق', ref: 'SB-2026-1003', owner: 'أ. سارة القحطاني', due: '29 يونيو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'تجهيز السند التنفيذي', ref: 'تنفيذ-5521', owner: 'أ. خالد المالكي', due: '01 يوليو', status: 'قيد العمل', tone: 'b-blue' },
  { title: 'متابعة جلسة القضية التجارية', ref: 'CASE-2026-0001', owner: 'أ. سارة القحطاني', due: '09 يوليو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'تسليم ملخص الاستشارة', ref: 'SB-2026-0987', owner: 'أ. خالد المالكي', due: 'مكتملة', status: 'منجزة', tone: 'b-green' },
];

// ── الملخصات (SUMMARIES / SUM_FLOW) ──
export interface Summary { ref: string; kind: string; stage: number; client: string; text?: string; }
export const SUMMARIES: Summary[] = [
  { ref: 'SB-2026-1042', kind: 'استشارة', stage: 1, client: 'عبدالله العتيبي' },
  { ref: 'M3', kind: 'اجتماع', stage: 1, client: 'شركة الأفق' },
  { ref: 'M2', kind: 'اجتماع', stage: 2, client: 'فريق القضية' },
  { ref: 'M1', kind: 'استشارة', stage: 2, client: 'عبدالله العتيبي' },
  { ref: 'SB-2026-0987', kind: 'استشارة', stage: 3, client: 'فهد الشهري' },
];
export const SUM_FLOW = ['إنشاء (الفريق القانوني)', 'اعتماد المحامي', 'اعتماد الإدارة', 'إرسال للعميل'];

// ── الاجتماعات الكاملة (FULL_MEETINGS) ──
export interface FullMeeting {
  id: string; title: string; type: string; client: string; when: string;
  approve: string; before: string[]; during: string[]; after: string[];
  status: string; priority: string; conf: string; attend: number;
  link: string; meetId: string; meetLink: string; dur: string;
}
const RAW_MEETINGS: Omit<FullMeeting, 'status' | 'priority' | 'conf' | 'attend' | 'link' | 'meetId' | 'meetLink' | 'dur'>[] = [
  { id: 'M1', title: 'استشارة مرئية — نزاع تجاري', type: 'اجتماع استشارة', client: 'عبدالله العتيبي', when: 'الاثنين 29 يونيو · 11:30 ص', approve: 'بانتظار اعتماد الإدارة',
    before: ['مراجعة التذكرة SB-2026-1042', 'قراءة عقد التوريد والمراسلات', 'تجهيز ملخص أولي للوقائع'],
    during: ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'تحديد المتحدثين (المستشار/العميل)'],
    after: ['ملخص الجلسة جاهز', 'محضر الاجتماع منشأ', 'مهام مستخرجة: 3', 'قرارات مستخرجة: 2'] },
  { id: 'M2', title: 'اجتماع فريق قضية — عمالي', type: 'اجتماع فريق قضية', client: 'داخلي', when: 'الأحد 28 يونيو · 09:00 ص', approve: 'معتمد',
    before: ['مراجعة ملف القضية ق-2026-0118', 'قراءة مذكرة الرد'],
    during: ['تسجيل النقاش', 'تفريغ نصي', 'توزيع المهام'],
    after: ['ملخص الاجتماع جاهز', 'المحضر معتمد', 'مهام مستخرجة: 4', 'قرارات مستخرجة: 1'] },
  { id: 'M3', title: 'اجتماع عميل — شركة الأفق', type: 'اجتماع عميل', client: 'شركة الأفق', when: 'الثلاثاء 30 يونيو · 01:00 م', approve: 'بانتظار اعتماد الإدارة',
    before: ['مراجعة عقد التوريد محل المراجعة', 'إعداد قائمة الملاحظات المبدئية'],
    during: ['عرض الملاحظات', 'مناقشة بنود الغرامات والإنهاء', 'تسجيل ملاحظات العميل'],
    after: ['ملخص الاجتماع جاهز', 'محضر منشأ', 'مهام مستخرجة: 2', 'قرارات مستخرجة: 1'] },
  { id: 'M4', title: 'اجتماع داخلي — توزيع الأعباء', type: 'اجتماع داخلي', client: 'داخلي', when: 'الأحد 28 يونيو · 04:00 م', approve: 'معتمد',
    before: ['حصر التذاكر المفتوحة', 'مراجعة طاقة كل مستشار'],
    during: ['مناقشة التوزيع', 'تسجيل القرارات'],
    after: ['محضر معتمد', 'قرارات مستخرجة: 3'] },
  { id: 'M5', title: 'اجتماع قسم — القضايا التجارية', type: 'اجتماع قسم', client: 'داخلي', when: 'الخميس 25 يونيو · 10:00 ص', approve: 'معتمد',
    before: ['حصر قضايا القسم', 'مراجعة الجلسات القادمة'],
    during: ['متابعة كل قضية', 'توزيع المهام'],
    after: ['ملخص القسم جاهز', 'مهام مستخرجة: 5'] },
  { id: 'M6', title: 'اجتماع إدارة — مؤشرات الأداء', type: 'اجتماع إدارة', client: 'الإدارة', when: 'الجمعة 26 يونيو · 12:00 م', approve: 'بانتظار اعتماد الإدارة',
    before: ['تجهيز تقارير الإيرادات', 'حصر الاعتمادات المعلقة'],
    during: ['استعراض المؤشرات', 'مناقشة الخطة'],
    after: ['ملخص الإدارة جاهز', 'قرارات مستخرجة: 4'] },
];
// يطابق FULL_MEETINGS.forEach(...) في الأصل
const _S = ['جارٍ', 'منتهٍ', 'قادم', 'مؤجل', 'منتهٍ', 'قادم'];
const _P = ['عالية', 'متوسطة', 'عالية', 'عادية', 'متوسطة', 'عالية'];
const _A = [0, 92, 0, 0, 86, 0];
const _D = ['45 دقيقة', '60 دقيقة', '30 دقيقة', '90 دقيقة', '45 دقيقة', '60 دقيقة'];
export const FULL_MEETINGS: FullMeeting[] = RAW_MEETINGS.map((m, i) => ({
  ...m,
  before: [...m.before], during: [...m.during], after: [...m.after],
  status: _S[i] || 'قادم',
  priority: _P[i] || 'عادية',
  conf: i % 2 === 0 ? 'سري' : 'عادي',
  attend: _A[i] || 0,
  link: m.client,
  meetId: 'SLS-' + (200000 + i * 1357),
  meetLink: 'https://salaselbabel.net/SLS-' + (200000 + i * 1357),
  dur: _D[i] || '60 دقيقة',
}));

// مفاتيح `MeetingStatus::key()` (تُقارَن بـ`statusKey` في البطاقة) — لا النصّ العربيّ المعروض
export const MEET_STATUSES: [string, string][] = [
  // «بانتظار التأكيد» عُلّقت: حالة يتيمة منذ إلغاء تأكيد العميل — خيار مرشّح بلا نتائج أبداً
  ['all', 'الكل'], ['upcoming', 'القادمة'], /* ['awaiting', 'بانتظار التأكيد'], */ ['live', 'الجارية'],
  ['ended', 'المنتهية'], ['missed', 'لم تنعقد'], ['postponed', 'المؤجلة'], ['cancelled', 'الملغاة'],
];
export const MEET_TYPES_FULL = ['اجتماع مع عميل', 'اجتماع مع محامٍ', 'اجتماع مع موظف', 'اجتماع متعدد الموظفين', 'اجتماع داخلي', 'اجتماع الإدارة العليا', 'اجتماع مرتبط بقضية', 'اجتماع مرتبط باستشارة'];
export const MEET_TEMPLATES: [string, string][] = [
  ['استشارة أولية', 'اجتماع مع عميل'], ['متابعة قضية', 'اجتماع مرتبط بقضية'],
  ['مراجعة عقد', 'اجتماع مع عميل'], ['اجتماع تفاوض', 'اجتماع مع عميل'],
  ['تجهيز جلسة', 'اجتماع مرتبط بقضية'], ['مراجعة مستندات', 'اجتماع مع محامٍ'],
  ['إغلاق قضية', 'اجتماع مرتبط بقضية'], ['اجتماع داخلي', 'اجتماع متعدد الموظفين'],
  ['الإدارة العليا', 'اجتماع الإدارة العليا'],
];

// نغمة حالة الاجتماع انتقلت إلى lib/meeting-ui (المكتبة المشتركة للاجتماعات)،
// إذ كانت هنا نسخة وثانية سطرية داخل صفحة التفاصيل تخالفها فيظهر الاجتماع نفسه بلونين.

// ── دعوات الاجتماعات (MEET_REQUESTS / MR_FLOW) ──
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

// دليل العملاء للدعوات (CLIENT_DIR) — `STAFF_DIR` التجريبيّ أُزيل: كان بديلاً صامتاً لكادرٍ حقيقيّ فارغ
export const CLIENT_DIR: { name: string; items: string[] }[] = [
  { name: 'عبدالله محمد العتيبي', items: ['SB-2026-1042 — استشارة تجارية', 'CASE-2026-014 — قضية تجارية', 'EXE-2026-2210 — طلب تنفيذ حكم'] },
  { name: 'نورة سعد الدوسري', items: ['SB-2026-1009 — استشارة عمالية', 'CASE-2026-031 — قضية عمالية'] },
  { name: 'شركة الأفق التجارية', items: ['SB-2026-0987 — مراجعة عقد', 'CASE-2026-022 — نزاع تجاري', 'EXE-2026-2185 — تنفيذ مطالبة'] },
  { name: 'فهد علي الشهري', items: ['SB-2026-0950 — استشارة تنفيذ'] },
];

// ملاحظة: كتالوج الصلاحيات (المجموعات/القوالب/الخريطة) صار مصدره الوحيد الخادم
// (App\Support\Permissions::catalog) ويصل عبر Inertia؛ انظر resources/js/lib/permissions.ts.

// ── أسعار الاستشارات (CONSULT_PRICES / VAT_RATE / PRICE_LOG) ──
export const CONSULT_PRICES: Record<string, number> = { 'حضورية': 600, 'مرئية': 450, 'هاتفية': 350 };
export const DEFAULT_VAT_RATE = 0.15;
export interface PriceLogEntry { who: string; ts: number; changes: string[]; }

// قنوات الاستشارات ونغماتها مصدرها employee-data (تستهلكها consult-ui المشتركة).
// حُذفت من هنا نسخة ثانية متطابقة لم تكن مستوردة من أي صفحة — نفس ازدواج نغمة الاجتماعات.

// ── أحدث النشاط في لوحة الإدارة (adHome) ──
export const AD_ACTIVITY: [string, string, string][] = [
  ['ticket', 'تذكرة جديدة من شركة الأفق', 'قبل ساعة'],
  ['video', 'اجتماع بانتظار الاعتماد', 'قبل ساعتين'],
  ['card', 'سداد فاتورة INV-2026-301', 'أمس'],
];

// ── المحاسبة (INVOICES) — نظام محاسبي مبسّط ──
export interface InvItem { d: string; q: number; p: number; }
export interface Invoice {
  no: string; client: string; code: string; date: string; due: string;
  items: InvItem[]; disc: number; vat: number; status: string;
  method: string; paid: string; part?: number;
}
export const INVOICES: Invoice[] = [
  { no: 'INV-2026-0001', client: 'شركة الأفق التجارية', code: 'CL-000142', date: '2026-06-01', due: '2026-06-15', items: [{ d: 'استشارة قانونية تجارية — حضورية', q: 1, p: 600 }, { d: 'إعداد مذكرة دعوى تجارية', q: 1, p: 1500 }], disc: 0, vat: 0.15, status: 'مدفوعة', method: 'تحويل بنكي', paid: '2026-06-09' },
  { no: 'INV-2026-0002', client: 'نورة سعد الدوسري', code: 'CL-000118', date: '2026-06-08', due: '2026-06-22', items: [{ d: 'استشارة قانونية عمالية — مرئية', q: 1, p: 450 }, { d: 'مراجعة عقد عمل', q: 2, p: 300 }], disc: 50, vat: 0.15, status: 'غير مدفوعة', method: '', paid: '' },
  { no: 'INV-2026-0003', client: 'عبدالله محمد العتيبي', code: 'CL-000127', date: '2026-06-12', due: '2026-06-26', items: [{ d: 'استشارة قانونية عامة — هاتفية', q: 1, p: 350 }], disc: 0, vat: 0.15, status: 'مدفوعة', method: 'مدى', paid: '2026-06-12' },
  { no: 'INV-2026-0004', client: 'شركة النهج المتقدم للتجارة', code: 'CL-000156', date: '2026-05-20', due: '2026-06-03', items: [{ d: 'الاتفاقية الشهرية — استشارات قانونية', q: 1, p: 4000 }, { d: 'تمثيل في جلسة تنفيذ', q: 3, p: 800 }], disc: 200, vat: 0.15, status: 'متأخرة', method: '', paid: '' },
  { no: 'INV-2026-0005', client: 'فهد علي الشهري', code: 'CL-000133', date: '2026-06-18', due: '2026-07-02', items: [{ d: 'استشارة عقارية — حضورية', q: 1, p: 600 }, { d: 'صياغة عقد إيجار', q: 1, p: 900 }], disc: 0, vat: 0.15, status: 'جزئية', method: 'تحويل بنكي', paid: '', part: 1000 },
];
export const INV_CLIENTS: [string, string][] = [
  ['شركة الأفق التجارية', 'CL-000142'], ['نورة سعد الدوسري', 'CL-000118'],
  ['عبدالله محمد العتيبي', 'CL-000127'], ['شركة النهج المتقدم للتجارة', 'CL-000156'],
  ['فهد علي الشهري', 'CL-000133'],
];
export const VAT_NO = '300055512300003';
export const INV_IBAN = 'SA44 8000 0000 6080 1234 5678';

// ── دوال حساب الفواتير (مطابقة للأصل) ──
export function invSub(v: Invoice): number { return v.items.reduce((a, i) => a + i.q * i.p, 0); }
export function invDiscV(v: Invoice): number { return v.disc || 0; }
export function invNet(v: Invoice): number { return invSub(v) - invDiscV(v); }
export function invVatV(v: Invoice): number {
  const r = typeof v.vat === 'number' ? v.vat : 0.15;
  return Math.round(invNet(v) * r);
}
export function invTotal(v: Invoice): number { return invNet(v) + invVatV(v); }
export function invPaidAmt(v: Invoice): number {
  return v.status === 'مدفوعة' ? invTotal(v) : v.status === 'جزئية' ? v.part || 0 : 0;
}
export function invDueAmt(v: Invoice): number { return invTotal(v) - invPaidAmt(v); }
export function invTone(s: string): string {
  return s === 'مدفوعة' ? 'b-green' : s === 'متأخرة' ? 'b-red' : s === 'جزئية' ? 'b-amber' : 'b-grey';
}
export function fmtSAR(n: number): string { return Math.round(n).toLocaleString('en-US') + ' ر.س'; }

// ── دوال مساعدة عامة ──
export function relTime(ts: number): string {
  if (!ts) return 'الآن';
  const s = Math.floor((Date.now() - ts) / 1000);
  if (s < 10) return 'الآن';
  if (s < 60) return 'قبل ' + s + ' ثانية';
  const m = Math.floor(s / 60);
  if (m < 60) return 'قبل ' + m + (m === 1 ? ' دقيقة' : m === 2 ? ' دقيقتين' : ' دقائق');
  const hh = Math.floor(m / 60);
  if (hh < 24) return 'قبل ' + hh + (hh === 1 ? ' ساعة' : hh === 2 ? ' ساعتين' : ' ساعات');
  const d = Math.floor(hh / 24);
  if (d === 1) return 'أمس';
  return 'قبل ' + d + ' أيام';
}

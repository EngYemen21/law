// ============================================================
// بيانات دور المحامي — مستخرجة حرفياً من index (82).html
// FULL_MEETINGS / SUMMARIES / SUM_FLOW / TASKS / ASSIST ... إلخ
// ============================================================

import { SYS_TICKETS } from '@/lib/employee-data';

// اسم المحامي الحالي — يطابق ROLE_DEFS.lawyer.name
export const LAWYER_NAME = 'أ. سارة القحطاني';
export const LAWYER_AV = 'س ق';

// ── الاجتماعات الكاملة (FULL_MEETINGS) — يطابق الأصل + forEach للإثراء ──
export interface FullMeeting {
  id: string;
  title: string;
  type: string;
  client: string;
  when: string;
  approve: string;
  before: string[];
  during: string[];
  after: string[];
  status: string;
  priority: string;
  conf: string;
  attend: number;
  link: string;
  meetId: string;
  meetLink: string;
  dur: string;
  summary?: string;
  sumApproved?: boolean;
  minutes?: string;
  participants?: string;
  caseRef?: string;
}

const RAW_MEETINGS: Omit<FullMeeting, 'status' | 'priority' | 'conf' | 'attend' | 'link' | 'meetId' | 'meetLink' | 'dur'>[] = [
  {
    id: 'M1', title: 'استشارة مرئية — نزاع تجاري', type: 'اجتماع استشارة', client: 'عبدالله العتيبي', when: 'الاثنين 29 يونيو · 11:30 ص', approve: 'بانتظار اعتماد الإدارة',
    before: ['مراجعة التذكرة SB-2026-1042', 'قراءة عقد التوريد والمراسلات', 'تجهيز ملخص أولي للوقائع'],
    during: ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'تحديد المتحدثين (المستشار/العميل)'],
    after: ['ملخص الجلسة جاهز', 'محضر الاجتماع منشأ', 'مهام مستخرجة: 3', 'قرارات مستخرجة: 2'],
  },
  {
    id: 'M2', title: 'اجتماع فريق قضية — عمالي', type: 'اجتماع فريق قضية', client: 'داخلي', when: 'الأحد 28 يونيو · 09:00 ص', approve: 'معتمد',
    before: ['مراجعة ملف القضية ق-2026-0118', 'قراءة مذكرة الرد'],
    during: ['تسجيل النقاش', 'تفريغ نصي', 'توزيع المهام'],
    after: ['ملخص الاجتماع جاهز', 'المحضر معتمد', 'مهام مستخرجة: 4', 'قرارات مستخرجة: 1'],
  },
  {
    id: 'M3', title: 'اجتماع عميل — شركة الأفق', type: 'اجتماع عميل', client: 'شركة الأفق', when: 'الثلاثاء 30 يونيو · 01:00 م', approve: 'بانتظار اعتماد الإدارة',
    before: ['مراجعة عقد التوريد محل المراجعة', 'إعداد قائمة الملاحظات المبدئية'],
    during: ['عرض الملاحظات', 'مناقشة بنود الغرامات والإنهاء', 'تسجيل ملاحظات العميل'],
    after: ['ملخص الاجتماع جاهز', 'محضر منشأ', 'مهام مستخرجة: 2', 'قرارات مستخرجة: 1'],
  },
  {
    id: 'M4', title: 'اجتماع داخلي — توزيع الأعباء', type: 'اجتماع داخلي', client: 'داخلي', when: 'الأحد 28 يونيو · 04:00 م', approve: 'معتمد',
    before: ['حصر التذاكر المفتوحة', 'مراجعة طاقة كل مستشار'],
    during: ['مناقشة التوزيع', 'تسجيل القرارات'],
    after: ['محضر معتمد', 'قرارات مستخرجة: 3'],
  },
  {
    id: 'M5', title: 'اجتماع قسم — القضايا التجارية', type: 'اجتماع قسم', client: 'داخلي', when: 'الخميس 25 يونيو · 10:00 ص', approve: 'معتمد',
    before: ['حصر قضايا القسم', 'مراجعة الجلسات القادمة'],
    during: ['متابعة كل قضية', 'توزيع المهام'],
    after: ['ملخص القسم جاهز', 'مهام مستخرجة: 5'],
  },
  {
    id: 'M6', title: 'اجتماع إدارة — مؤشرات الأداء', type: 'اجتماع إدارة', client: 'الإدارة', when: 'الجمعة 26 يونيو · 12:00 م', approve: 'بانتظار اعتماد الإدارة',
    before: ['تجهيز تقارير الإيرادات', 'حصر الاعتمادات المعلقة'],
    during: ['استعراض المؤشرات', 'مناقشة الخطة'],
    after: ['ملخص الإدارة جاهز', 'قرارات مستخرجة: 4'],
  },
];

// يطابق FULL_MEETINGS.forEach(...) في الأصل
const _S = ['جارٍ', 'منتهٍ', 'قادم', 'مؤجل', 'منتهٍ', 'قادم'];
const _P = ['عالية', 'متوسطة', 'عالية', 'عادية', 'متوسطة', 'عالية'];
const _A = [0, 92, 0, 0, 86, 0];
const _DUR = ['45 دقيقة', '60 دقيقة', '30 دقيقة', '90 دقيقة', '45 دقيقة', '60 دقيقة'];

export const FULL_MEETINGS: FullMeeting[] = RAW_MEETINGS.map((m, i) => {
  const meetId = `SLS-${200000 + i * 1357}`;
  return {
    ...m,
    before: [...m.before], during: [...m.during], after: [...m.after],
    status: _S[i] || 'قادم',
    priority: _P[i] || 'عادية',
    conf: i % 2 === 0 ? 'سري' : 'عادي',
    attend: _A[i] || 0,
    link: m.client,
    meetId,
    meetLink: `https://meet.salasel.sa/${meetId}`,
    dur: _DUR[i] || '60 دقيقة',
  };
});

// ── الملخصات (SUMMARIES) ──
export interface Summary { ref: string; kind: string; stage: number; client: string; text?: string; }
export const SUMMARIES: Summary[] = [
  { ref: 'SB-2026-1042', kind: 'استشارة', stage: 1, client: 'عبدالله العتيبي' },
  { ref: 'M3', kind: 'اجتماع', stage: 1, client: 'شركة الأفق' },
  { ref: 'M2', kind: 'اجتماع', stage: 2, client: 'فريق القضية' },
  { ref: 'M1', kind: 'استشارة', stage: 2, client: 'عبدالله العتيبي' },
  { ref: 'SB-2026-0987', kind: 'استشارة', stage: 3, client: 'فهد الشهري' },
];

export const SUM_FLOW = ['إنشاء (الفريق القانوني)', 'اعتماد المحامي', 'اعتماد الإدارة', 'إرسال للعميل'];

// ملخص الملف الحقيقي القادم من الخادم (ticket_summaries)
export interface SummaryData {
  id?: number;
  ref?: string;
  caseSummary?: string;
  attachmentsSummary?: string;
  facts?: string;
  keyPoints?: string;
  status: string; // awaiting_lawyer | approved
  approved: boolean;
  result?: string;
  resultStatus?: string; // none | pending_lawyer | pending_admin | approved
}

// موضع الملخص على مسار SUM_FLOW حسب حالته
export function sumStage(status: string): number {
  return status === 'approved' ? 3 : 1;
}

// نص ملخص افتراضي — يطابق defaultSummaryText (مُستخرج من summaryView)
export function defaultSummaryText(s: Summary): string {
  return `ملخص ${s.kind || ''} — ${s.ref}\n\n` +
    `عزيزنا العميل،\n\n` +
    `الوقائع: تمّ خلال الاستشارة تحديد محل النزاع والنقاط الجوهرية بناءً على ما قدّمتموه من مستندات.\n\n` +
    `الرأي القانوني: نرى توجيه إنذار رسمي للطرف الآخر، ثم تجهيز مذكرة دعوى احتياطية حال عدم الاستجابة خلال المهلة النظامية.\n\n` +
    `الإجراءات المقترحة: صياغة خطاب المطالبة ومتابعة المهلة النظامية، مع تزويدنا بأي مستندات إضافية.`;
}

// ── المهام (TASKS) ──
export interface LawyerTask { title: string; ref: string; owner: string; due: string; status: string; tone: string; }
export const TASKS: LawyerTask[] = [
  { title: 'صياغة خطاب مطالبة', ref: 'SB-2026-1042', owner: 'أ. سارة القحطاني', due: '30 يونيو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'إعداد مذكرة الرد على الدعوى', ref: 'ق-2026-0118', owner: 'أ. سارة القحطاني', due: '02 يوليو', status: 'قيد العمل', tone: 'b-blue' },
  { title: 'تجهيز ملاحظات عقد شركة الأفق', ref: 'SB-2026-1003', owner: 'أ. سارة القحطاني', due: '29 يونيو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'تجهيز السند التنفيذي', ref: 'تنفيذ-5521', owner: 'أ. خالد المالكي', due: '01 يوليو', status: 'قيد العمل', tone: 'b-blue' },
  { title: 'متابعة جلسة القضية التجارية', ref: 'CASE-2026-0001', owner: 'أ. سارة القحطاني', due: '09 يوليو', status: 'مفتوحة', tone: 'b-amber' },
  { title: 'تسليم ملخص الاستشارة', ref: 'SB-2026-0987', owner: 'أ. خالد المالكي', due: 'مكتملة', status: 'منجزة', tone: 'b-green' },
];

// ── المساعد القانوني الذكي (ASSIST) ──
export interface AssistTab { key: string; label: string; items: string[]; }
export const ASSIST: AssistTab[] = [
  { key: 'lawahe', label: 'كتابة اللوائح', items: ['لائحة دعوى', 'لائحة جوابية', 'لائحة اعتراض', 'لائحة استئناف', 'التماس إعادة نظر'] },
  { key: 'mems', label: 'كتابة المذكرات', items: ['مذكرة دفاع', 'مذكرة رد', 'مذكرة تعقيب', 'مذكرة قانونية'] },
  { key: 'analyze', label: 'التحليل القانوني', items: ['تحليل العقود', 'تحليل الأحكام', 'تحليل الأدلة', 'تحليل المستندات'] },
  { key: 'defense', label: 'اقتراح الدفوع', items: ['استخراج الوقائع', 'استخراج الطلبات', 'اقتراح الدفوع القانونية'] },
];

// مراجع التذاكر للمساعد القانوني (select asRef)
export const ASSIST_REFS = SYS_TICKETS.map((t) => t.no);

// يطابق lwGenerate — توليد نص المسودة حسب التبويب
export function lwGenerate(tab: string, type: string, ref: string): string {
  let body = '';
  if (tab === 'lawahe') {
    body = `${type}\n\n` +
      `إلى فضيلة ناظر الدائرة المختصة،\n\n` +
      `مقدّمه (المدّعي): العميل، بموجب التذكرة ${ref}.\n\n` +
      `أولاً — الوقائع:\nبتاريخه نشأ نزاع يتعلق بإخلال المدّعى عليه بالتزاماته التعاقدية على النحو الثابت بالمستندات.\n\n` +
      `ثانياً — الأسانيد النظامية:\nيستند الطلب إلى القواعد العامة في الالتزامات والأنظمة ذات العلاقة.\n\n` +
      `ثالثاً — الطلبات:\n1) إلزام المدّعى عليه بتنفيذ التزامه.\n2) التعويض عن الأضرار.\n3) إلزامه بالمصاريف.`;
  } else if (tab === 'mems') {
    body = `${type} — بشأن ${ref}\n\n` +
      `نلتمس من الدائرة الموقرة اعتماد الدفوع التالية:\n` +
      `• الدفع بصحة موقف الموكّل استناداً للمستندات المرفقة.\n` +
      `• الرد على ما ورد في لائحة الخصم نقطةً نقطة.\n` +
      `• تمسّك الموكّل بكامل طلباته.\n\n` +
      `وبناءً عليه نلتمس الحكم بما يحفظ حق الموكّل.`;
  } else if (tab === 'analyze') {
    body = `${type} — ${ref}\n\n` +
      `النقاط الجوهرية:\n• الأطراف والالتزامات المتبادلة محددة بوضوح.\n• بنود قد تثير خلافاً: مدة التنفيذ والشرط الجزائي.\n\n` +
      `المخاطر:\n• ضعف توثيق التسليم قد يؤثر على الإثبات.\n\n` +
      `التوصيات:\n• تدعيم الملف بالمراسلات وإثبات الاستلام قبل المرافعة.`;
  } else {
    body = `${type} — ${ref}\n\n` +
      `الوقائع المستخرجة:\n• إخلال بالالتزام خلال المدة المتفق عليها.\n\n` +
      `الطلبات المستخرجة:\n• التنفيذ العيني + التعويض.\n\n` +
      `الدفوع المقترحة:\n• الدفع بثبوت الإخلال بالمستندات.\n• الدفع باستحقاق الشرط الجزائي.`;
  }
  return body;
}

// ── محرك تحويل الاستشارة إلى قضية (CASE CONVERSION) ──
export const CF_RAIL: [string, string][] = [
  ['انتهاء الاستشارة', 'حفظ الملفات'],
  ['التحليل الذكي', 'نوع القضية والقسم'],
  ['مراجعة المحامي', 'قرار التحويل'],
  ['تحويل إلى قضية', 'رقم وملف القضية'],
  ['الأتعاب والفاتورة', 'تحديد وإصدار'],
  ['سداد الأتعاب', 'تفعيل القضية'],
  ['خطة العمل واللائحة', 'إعداد واعتماد'],
  ['المتابعة والإغلاق', 'الجلسات والأرشفة'],
];

export const CF_STAGE: Record<string, number> = {
  ended: 0, analysis: 1, review: 2, reqdocs: 2, convert: 3,
  fees: 4, invoice: 4, notify: 5, pay: 5, activate: 6, plan: 6,
  statement: 6, track: 7, gov: 7, closed: 7,
};

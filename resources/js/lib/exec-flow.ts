import type { ConversationHistory } from '@/components/babylon/ConversationHandlerCard';
// ─────────────────────────────────────────────────────────────────────────────
// ثوابت وأنواع تدفّق طلب التنفيذ (نظير EXEC_FLOW في index (21).html).
// المرحلة 2: لا بيانات وهميّة ولا منطق محلّي — الحالة كلّها من الخادم عبر Inertia،
// والانتقالات تُنفَّذ بـ POST إلى نقاط النهاية (App\Support\ExecService).
// ─────────────────────────────────────────────────────────────────────────────

export const EXEC_FLOW = [
  'طلب جديد', 'تحليل ذكي', 'قيد الدراسة', 'تحديد الأتعاب', 'اعتماد الإدارة',
  'عرض الخدمة', 'السداد', 'بانتظار الرفع في ناجز', 'قيد التنفيذ', 'مغلق',
];

/**
 * أسباب إنهاء الملفّ — **احتياطيٌّ وحده**. القائمة الحيّة تصل في `ExecNajiz.closeReasons`
 * من `ExecFlow::CLOSE_REASONS`؛ ونسخةٌ يدويّة هنا كانت تتباعد عن الخادم بصمت فتعرض خياراً يردّه.
 */
export const EXEC_CLOSE_REASONS = ['سداد كامل', 'تسوية', 'إعسار', 'تنازل طالب التنفيذ', 'أخرى'];

export const EXEC_SANADS = ['حكم قضائي', 'سند لأمر', 'شيك', 'عقد تنفيذي', 'محضر صلح', 'قرار تحكيم'];
/**
 * **نماذج الأتعاب** — يقرّرها المكتب عند التسعير، ولكلٍّ منها محرّك خلفه (`App\Support\ExecFee`).
 * وأُسقط «حسب مراحل التنفيذ»: خيارٌ كان يُعرض بلا سلوكٍ البتّة.
 */
export const EXEC_FEE_MODES = [
  { value: 'fixed', label: 'مبلغ ثابت' },
  { value: 'percent', label: 'نسبة من المحصّل' },
] as const;

export type ExecFeeMode = (typeof EXEC_FEE_MODES)[number]['value'];

// أُزيلت `EXEC_PAY_PLANS`: قائمةٌ لا يستوردها أحد، ونصّها «تقسيط على 3 دفعات» نسخةٌ منقوشة من
// إعدادٍ تضبطه الإدارة. عدد الدفعات من `useSettings().installments_count` وصياغته `installmentsText`.

/**
 * نصّ سطر الضريبة — مصدرٌ واحد كي لا تتباعد صياغته بين البطاقات. **والنسبة إلزاميّة**: كان
 * افتراضها «15» هنا فيعرض نسبةً منقوشة متى غابت، والخادم يرسلها دائماً (`Execution::toFlowCard`).
 */
export function execVatLabel(rate: number): string {
  return `ضريبة القيمة المضافة (${rate}%)`;
}

// صيغ الإرفاق في محادثة التنفيذ — تطابق ExecFlowController::attach (يضيف XLSX عن نظيرتها في التذاكر/القضايا)
export const EXEC_DOC_ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xlsx';
export const EXEC_DOC_HINT = 'الصيغ المسموحة: PDF، JPG، PNG، DOC، DOCX، XLSX — حتى 10MB لكل ملف';

// رفع المستند **المطلوب** أضيق من إرفاق المحادثة (ExecFlowController::uploadDocument: 2MB وبلا DOC/XLSX)،
// وكانت الشاشة تصمت عن الحدّ فيُردّ رفع العميل بـ422 بعد انتظار الرفع كلّه.
export const EXEC_REQ_DOC_ACCEPT = '.pdf,.jpg,.jpeg,.png,.docx';
export const EXEC_REQ_DOC_HINT = 'الصيغ المسموحة: PDF، JPG، PNG، DOCX — حتى 2MB لكل مستند';

import { type Message } from '@/lib/chat';

export type Role = 'client' | 'lawyer' | 'admin' | 'employee';

export interface Proc { a: string; t: string; type?: string; status?: string }

/** فاتورة على ملفّ التنفيذ (يطابق `Invoice::toCard()`). */
export interface ExecInvoice {
  no: string;
  desc: string;
  amount: number;
  status: string;
  tone: string;
  due: string;
  overdue: boolean;
  paid: boolean;
  /** موضعها من خطّة التقسيط — `null` لفاتورة أتعابٍ عن تحصيل. */
  installmentNo?: number | null;
}

// مستند مطلوب من العميل (يطابق exDocPanel)
export interface ExecDoc {
  id: number; label: string; status: string; tone: string; fileName: string | null; canUpload: boolean; docType?: string; summary?: string;
  /** يُراجَع (اعتماد/إعادة) — حارس `reviewDocument` نفسه، لا مقارنة بـ«مرفوع» هنا */
  canReview: boolean;
  /** وصل المكتب (رُفع أو قُبل) */
  provided: boolean;
  /** `false` لموظّفٍ بلا «تنزيل مرفقات الملفات» — يُعرض الاسم بلا رابط. */
  canDownload?: boolean;
}

/**
 * مسار التنفيذ في ناجز (المرحلتان 7 و8) — يطابق `Execution::najizCard()`.
 * يُعرَّف هنا لا في بطاقة العرض: `exec-najiz.tsx` يستورد من هذا الملفّ، فلا استيراد دائريّ.
 */
export interface ExecNajiz {
  requestNo: string;
  filedAt: string;
  court: string;
  circuit: string;
  registeredAt: string;
  notifiedAt: string;
  /** نهاية مهلة الوفاء كما يحسبها الخادم (تقويميّة الآن، وأيام عمل بعد نفاذ النظام الجديد). */
  payDueAt: string;
  /** هل انقضت المهلة؟ — من الخادم لا من ساعة المتصفّح. */
  payDueOver: boolean;
  measures: string[];
  collected: number;
  amount: number;
  closedReason: string;
  /** خيارات الخادم (`ExecFlow::MEASURES`/`CLOSE_REASONS`) — تُستهلَك متى وصلت، والثوابت أعلاه احتياطٌ لا أكثر. */
  measureOptions?: string[];
  closeReasons?: string[];
}

/**
 * دراسة التنفيذ — مخرَج التحليل الموسَّع الذي يُبنى عليه التسعير (`Execution::toFlowCard`).
 * تجري في الخلفيّة ولا تحجز الملفّ: `null` تعني «قيد الإعداد» لا «تعذّرت».
 * `pending`: قُرئت في المكتب ولم يعتمدها مستشار بعد — والعميل لا يراها قبل الاعتماد (الخادم يفرضه).
 */
export interface ExecStudy {
  summary: string;
  missing: string[];
  procedures: string[];
  /** جاهزيّة السند التنفيذيّ للقيد في ناجز. */
  readiness: string;
  difficulty: string;
  expectedProceduresCount: number;
  durationEstimate: string;
  /** مؤشّرات التحصيل (ملاءة المنفَّذ ضدّه، أصول معروفة…). */
  recovery: string[];
  risks: string[];
  pending: boolean;
  approved: boolean;
}

export interface ExecReq {
  /** من يتولّى محادثة الملفّ ومن تولّاها قبله — لبطاقة الطاقم وحدها (`ExecFlowController::staffCards`). */
  conversation?: ConversationHistory | null;
  id: string;
  rawId?: number;
  client: string;
  code: string;
  sanad: string;
  subject: string;
  defendant: string;
  amount: number;
  notes: string;
  docs: string[];
  stage: number;
  channel: string;
  messages: Message[];
  docItems: ExecDoc[];
  lawyer: string;
  aiDone: boolean;
  /** App\Enums\AiSource — '' لصفوف ما قبل هجرة المصدر (مصدر غير معروف). */
  aiSource: '' | 'ai_success' | 'fallback' | 'manual_required' | 'human_approved';
  aiSummary: string;
  aiMissing: string[];
  aiProcedures: string[];
  /** اعتمده محامٍ؟ — وقبله تصل الحقول الثلاثة فارغة (`Execution::toFlowCard`). */
  aiApproved?: boolean;
  /** أُنتج وينتظر اعتماداً — يُعلَم العميل أن ملفّه تحت الدراسة لا مهمَل. */
  aiPending?: boolean;
  decision: string;
  fee: number;
  vat: number;
  /** نسبة الضريبة من إعدادات النظام — يرسلها الخادم دائماً، فلا افتراضَ منقوشاً في الشاشة. */
  vatRate: number;
  duration: string;
  /** عنوان طريقة السداد — **مشتقٌّ في الخادم** من النموذج والخطّة، فلا يخالف ما يقع. */
  payMethod: string;
  /** نموذج الأتعاب: مبلغٌ ثابت أو نسبةٌ من كلّ محصَّل. */
  feeMode?: ExecFeeMode;
  /** نسبة الأتعاب من المحصَّل (النموذج النسبيّ وحده). */
  collectionFeePct?: number;
  /** خطّة السداد التي اختارها العميل — '' قبل اختياره. */
  payPlan?: '' | 'full' | 'install';
  installmentsTotal?: number;
  installmentsPaid?: number;
  /** فواتير الملفّ كلّها — دفعات الخطّة وفواتير الأتعاب عن التحصيل. */
  invoices?: ExecInvoice[];
  feeApproved: boolean;
  offerStatus: string;
  /** فاتورة **فتح الملفّ**: الوحيدة في النموذج الثابت، والدفعة الأولى في التقسيط، و'' في النسبيّ. */
  invoiceNo: string;
  paid: boolean;
  execNo: string;
  procedures: Proc[];
  /** خطوات ناجز — تصل بعد فتح الملفّ (المرحلة 7)، وقبلها `null`. */
  najiz?: ExecNajiz | null;
  closed: boolean;
  /** رفضه المحامي بعد الدراسة (`Execution::isRejectedAfterStudy`) — لا مقارنة بـ«مرفوض» هنا */
  isRejected: boolean;
  /** رفض العميل عرض الأتعاب (`Execution::isOfferRejected`) */
  offerRejected: boolean;
  /** مرفوضٌ مفتوح مخرجُه إنهاء الإدارة (`Execution::isRejectedOpen` — حارس `CloseExecution` نفسه) */
  rejectedOpen: boolean;
  /** يجوز تسعيره الآن (`ExecService::canPrice` — حارس `setFee` نفسه) */
  canReprice: boolean;
  /** دراسة التنفيذ — تصل للمكتب وحده؛ `null` قبل جاهزيّتها. */
  study?: ExecStudy | null;
  /** هل يملك الناظر إسناد محامٍ؟ (إدارةٌ دائماً، وموظّفٌ بصلاحيّة «إجراءات المحكمة والجلسات») */
  canAssign?: boolean;
  /** معرّف المحامي المسنَد — `null` يعني ملفّاً بلا مالك، وقبل هجرة العقد يصل `undefined`. */
  lawyerId?: number | null;
}

/** خيار محامٍ في قائمة الإسناد (يطابق `lawyers` في props الصفحة). */
export interface ExecLawyerOpt { id: number; name: string }

/**
 * ملفٌّ بلا محامٍ؟ — العقد يرسل `lawyerId`، وقبل وصوله يبقى الاسم وحده دليلاً،
 * فلا تنقلب الشاشة إلى «بانتظار الإسناد» على كلّ ملفّ لمجرّد غياب الحقل الجديد.
 */
export function execUnassigned(r: Pick<ExecReq, 'lawyer' | 'lawyerId'>): boolean {
  return r.lawyerId === undefined ? !r.lawyer : r.lawyerId === null;
}

/**
 * سطرٌ مضغوط يصف أساس التسعير من الدراسة — الأتعاب تُحدَّد مقابل شيء لا في الفراغ.
 * يعيد '' حين لا دراسة، فتقول الشاشة ذلك صراحةً بدل عرض أصفارٍ توهم بتقديرٍ وقع.
 */
export function execStudyBasis(study?: ExecStudy | null): string {
  if (!study) {
    return '';
  }

  const count = Number(study.expectedProceduresCount ?? 0);

  return [
    study.difficulty ? `الصعوبة: ${study.difficulty}` : '',
    count > 0 ? `الإجراءات المتوقّعة: ${count}` : '',
    study.durationEstimate ? `المدّة المتوقّعة: ${study.durationEstimate}` : '',
  ].filter(Boolean).join(' · ');
}

// نغمة الشارة حسب المرحلة (تطابق execTone في التصميم)
export function execTone(stage: number): string {
  return stage >= 9 ? 'b-grey' : stage >= 7 ? 'b-green' : stage >= 5 ? 'b-amber' : stage >= 2 ? 'b-blue' : 'b-grey';
}

// تنسيق المبلغ (تطابق execMoney)
export function execMoney(n: number): string {
  return Number(n || 0).toLocaleString('en-US');
}

// نغمة شارة حالة إجراء التنفيذ (منفّذ/مجدول/مؤجل)
export function procTone(status?: string): string {
  return status === 'منفّذ' ? 'b-green' : status === 'مؤجل' ? 'b-amber' : status === 'مجدول' ? 'b-blue' : 'b-grey';
}

/**
 * عرض بطاقة مخرج التنفيذ بحسب مصدره — لا يُعرض القالب الاحتياطيّ تحت عنوان
 * «الملخّص الذكيّ» أبداً. `null` يعني: لا بطاقة تُعرض أصلاً.
 *
 * `forClient`: سياسة المكتب أن العميل لا يُطلَع على تعذّر التحليل — فالبطاقة
 * الاحتياطيّة تختفي عنه تماماً، ويراها المكتب وحده بعنوانها الصادق.
 */
export function execAiPresentation(
  r: Pick<ExecReq, 'aiSource' | 'aiSummary'>,
  forClient = false,
): { title: string; accent: string; notice: string } | null {
  if (!r.aiSummary) return null;

  if (r.aiSource === 'ai_success' || r.aiSource === 'human_approved') {
    return { title: 'الملخّص الذكيّ', accent: 'var(--cyan)', notice: '' };
  }

  if (forClient) return null;

  return {
    title: 'تقييم أوّليّ — تعذّر التحليل الذكيّ',
    accent: 'var(--amber)',
    notice: 'لم يُفحص أي مستند. ما يلي مشتقّ من بيانات الطلب وحدها، ويلزم فحص المستندات يدوياً قبل الإحالة.',
  };
}

// ─────────────────────────────────────────────────────────────────────────────
// ثوابت وأنواع تدفّق طلب التنفيذ (نظير EXEC_FLOW في index (21).html).
// المرحلة 2: لا بيانات وهميّة ولا منطق محلّي — الحالة كلّها من الخادم عبر Inertia،
// والانتقالات تُنفَّذ بـ POST إلى نقاط النهاية (App\Support\ExecService).
// ─────────────────────────────────────────────────────────────────────────────

export const EXEC_FLOW = [
  'طلب جديد', 'تحليل ذكي', 'قيد الدراسة', 'تحديد الأتعاب', 'اعتماد الإدارة',
  'عرض الخدمة', 'السداد', 'ملف تنفيذ', 'قيد التنفيذ', 'مغلق',
];

export const EXEC_SANADS = ['حكم قضائي', 'سند لأمر', 'شيك', 'عقد تنفيذي', 'محضر صلح', 'قرار تحكيم'];
export const EXEC_PAYM = ['دفعة واحدة', 'دفعات', 'حسب مراحل التنفيذ', 'نسبة من المحصّل'];

// صيغ الإرفاق في محادثة التنفيذ — تطابق ExecFlowController::attach (يضيف XLSX عن نظيرتها في التذاكر/القضايا)
export const EXEC_DOC_ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xlsx';
export const EXEC_DOC_HINT = 'الصيغ المسموحة: PDF، JPG، PNG، DOC، DOCX، XLSX — حتى 10MB لكل ملف';

import { type Message } from '@/lib/chat';

export type Role = 'client' | 'lawyer' | 'admin' | 'employee';

export interface Proc { a: string; t: string; type?: string; status?: string }
export interface LinkedCorr { id: string; entity: string; stageLabel: string }

// مستند مطلوب من العميل (يطابق exDocPanel)
export interface ExecDoc { id: number; label: string; status: string; tone: string; fileName: string | null; canUpload: boolean; docType?: string; summary?: string }

export interface ExecReq {
  id: string;
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
  duration: string;
  payMethod: string;
  feeApproved: boolean;
  offerStatus: string;
  invoiceNo: string;
  paid: boolean;
  execNo: string;
  procedures: Proc[];
  closed: boolean;
  linkedCorr: LinkedCorr[];
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

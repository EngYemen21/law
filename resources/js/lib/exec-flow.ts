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
  aiSummary: string;
  aiMissing: string[];
  aiProcedures: string[];
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

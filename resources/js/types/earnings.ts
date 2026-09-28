/**
 * **مستحقّات الموظّف** — شكل ما يُرسله `App\Support\Finance\StaffEarnings::for()` بعينه.
 * تقرؤه صفحة «مستحقاتي» (`pages/staff/earnings.tsx`) ودرج الإدارة في تبويب الموظّفين.
 */
export type PayoutKindId = 'salary' | 'case_share' | 'exec_share' | 'session';

export interface SalaryMonth {
  period: string;
  label: string;
  /** راتب الشهر بعد إسقاط أيّام الإيقاف */
  amount: number;
  suspendedDays: number;
  paid: number;
  remaining: number;
}

export interface ShareRow {
  kind: 'case' | 'exec';
  id: number;
  ref: string;
  client: string;
  fee: number;
  pct: number;
  /** النصيب الكلّيّ — null في التنفيذ بنموذج النسبة من المحصَّل (بلا مبلغٍ مقدَّم) */
  share: number | null;
  /** المسند الآن؟ — وإلّا فمحامٍ سابق يُعرض له ما حُصّل في عهده وحده */
  current: boolean;
  /** ما حُصّل من الملفّ في عهد هذا الموظّف (قبل الضريبة) */
  collected: number;
  earned: number;
  expected: number | null;
  beforeLedger: number;
  earnedInLedger: number;
  monthEarned: number;
  paid: number;
  balance: number;
}

export interface SessionRow { ref: string; date: string; amount: number; inLedger: boolean; inMonth: boolean }

export interface PayoutRow {
  id: number;
  kind: PayoutKindId;
  kindLabel: string;
  amount: number;
  period: string;
  periodLabel: string;
  paidAt: string;
  ref: string | null;
  note: string | null;
  voided: boolean;
  voidReason: string | null;
}

export interface KindTotals { label: string; earned: number; paid: number; balance: number; monthEarned: number }

export interface StaffEarnings {
  month: string;
  monthLabel: string;
  ledgerStart: string;
  ledgerStartLabel: string;
  payType: 'salary' | 'pct' | 'both' | 'session' | null;
  payLabel: string;
  salary: { applies: boolean; monthly: number; months: SalaryMonth[] };
  shares: ShareRow[];
  sessions: { applies: boolean; fee: number; rows: SessionRow[] };
  payouts: PayoutRow[];
  totals: {
    byKind: Record<PayoutKindId, KindTotals>;
    earned: number;
    paid: number;
    balance: number;
    monthEarned: number;
    monthPaid: number;
  };
}

export interface PayoutKindOption { id: PayoutKindId; label: string; needsFile: boolean }

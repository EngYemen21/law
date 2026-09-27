// ============================================================
// ملخّص الملفّ (مساره ونوع بياناته)
// ============================================================
//
// حُذفت البيانات التجريبيّة الثابتة الموروثة من النموذج (قرار المالك 2026-09-27، ناقضاً قرار
// «لا حذف» في 2026-08-21): لا مستوردَ لها، وقيمها تخالف الخادم. تبقى في تاريخ git.
// يستورده الملخّص وشاشة الاعتمادات.

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
  /** اعتمده المحامي ويُنتظر اعتماد الإدارة (قرار المالك 2026-09-14) */
  lawyerApproved?: boolean;
  aiGenerated?: boolean; // false = قالب مبدئي لم يكتمل تحليله الذكي
  result?: string;
  resultStatus?: string; // none | approved | rejected (pending_admin وpending_lawyer حُذفتا 2026-09-19)
}

// موضع الملخص على مسار SUM_FLOW — من اعتماده نفسه (`lawyerApproved` ثمّ `approved`)،
// لا من `summary.status` الذي قيمه awaiting_lawyer|approved فقط فكانت مرحلة «اعتماد الإدارة» لا تُعرض
export function sumStage(s?: Pick<SummaryData, 'approved' | 'lawyerApproved'>): number {
  // المسار يُقرأ من اعتماد الملخّص نفسه: إنشاء ← اعتماد المحامي ← اعتماد الإدارة وإرساله للعميل
  if (s?.approved) {
    return 3;
  }

  return s?.lawyerApproved ? 2 : 1;
}

/**
 * **تصنيفات سبب إغلاق التذكرة** — تطابق `App\Domain\Journey\Enums\ClosureReasonCode` رمزاً برمز (يرفض الخادم غيرها).
 * كانت تُصدَّر من `CloseTicketModal`، مكوّنٍ ميّت لا يُرسم ويرسل إلى مسارٍ غير موجود — حُذف (جرد الأزرار 2026-10-03،
 * البند ٤) وبقيت التصنيفات هنا لبطاقة القرار وحدها.
 */
export const CLOSURE_REASONS = [
  { code: 'OPINION_SATISFIED', label: 'اكتفاء بالرأي القانوني دون وجود نزاع' },
  { code: 'SETTLED_AMICABLY', label: 'تمت التسوية الودية والصلح بين الأطراف' },
  { code: 'NO_LEGAL_MERIT', label: 'انعدام السند النظامي أو ضعف الجدوى من التقاضي' },
  { code: 'OUTSIDE_FIRM_SCOPE', label: 'الموضوع يخرج عن نطاق اختصاص المكتب' },
  { code: 'CLIENT_INACTIVITY_DROP', label: 'حفظ الملف لعدم تجاوب العميل واستكمال النواقص' },
  { code: 'CLIENT_REQUESTED_CLOSURE', label: 'رغبة العميل الصريحة في عدم متابعة الإجراءات' },
  { code: 'OTHER_WITH_REASON', label: 'سبب نظامي آخر (مع تسبيب مفصل)' },
] as const;

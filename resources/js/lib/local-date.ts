/**
 * تاريخ اليوم `YYYY-MM-DD` **بالتوقيت المحلي** — المصدر الواحد لحقول التاريخ ومقارنات «اليوم».
 *
 * `toISOString` تُرجع UTC، فتُعطي «أمس» بعد منتصف الليل المحلي: تفتح فتراتٍ ماضية وتقفل صالحة.
 * كانت تسكن `components/SpecialistPicker.tsx` (مُنتقي العميل القديم) وتُستورد منه وحده؛ وحُذف
 * المُنتقي حين صار الطاقم يحدّد الموعد (2026-09-14) — فانتقلت هنا بلا تغيير.
 */
export function todayISO(): string {
  return dateISOAfter(0);
}

/**
 * تاريخ `YYYY-MM-DD` بعد `days` يوماً **بالتوقيت المحلي** — لأزرار «غداً / بعد أسبوع».
 * كانت شاشة المهام تحسبه بـ`toISOString` (UTC)، فبعد منتصف الليل بتوقيت الرياض وقبل الثالثة فجراً
 * يصير «اليوم» أمسَ و«غداً» اليوم — استحقاقٌ يُسجَّل متأخّراً يوماً.
 */
export function dateISOAfter(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * أوّل الشهر الجاري `YYYY-MM-01` **بالتوقيت المحلي** — لفلتر «هذا الشهر». كان يُحسب بـ`toISOString` من
 * منتصف ليل اليوم الأوّل محلّياً، وهو ٢١:٠٠ من آخر أيّام الشهر السابق بتوقيت UTC — فيبدأ الفلتر قبل الشهر بيوم طوال اليوم.
 */
export function firstOfMonthISO(): string {
  return `${todayISO().slice(0, 8)}01`;
}

/**
 * أسماء أيّام الأسبوع بترتيب `Date.getDay()` (الأحد=0) — وهو ترتيب Carbon في الخادم (`consult_work_days`).
 * المصدر الواحد لرأس التقويم وأزرار «أيّام دوام المكتب» في الإعدادات.
 */
export const WEEK_DAY_NAMES = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'] as const;

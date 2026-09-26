/**
 * مدّةٌ بالدقائق مصوغةً بوحدتها الطبيعيّة — «10 دقائق»، «ساعة واحدة»، «ساعة ونصف»، «ساعتين»،
 * «3 ساعات»، «يوم واحد»، «ساعة و15 دقيقة».
 *
 * **لماذا دالّة مشتركة؟** كانت بطاقة «متوسط انتظار الطلبات المفتوحة» تعرض «14325 دقيقة» —
 * عشرة أيّامٍ معروضةً بالدقائق، رقمٌ لا يقرؤه أحد (رُصد 2026-09-25). وإصلاح البطاقة وحدها
 * يترك الباب مفتوحاً لكلّ مؤشّرٍ قادم، فالصياغة في موضعٍ واحد تستعمله الشاشات كلّها.
 *
 * **ونظيرُها في الخادم `App\Support\ArabicCount::duration` يطابقها حرفاً** (قرار المالك 2026-09-26:
 * قاعدةٌ واحدة لكلّ نصٍّ مبنيٍّ من مهلة) — يحرسهما `tests/Feature/HumanDurationTest.php` بجدولٍ واحد
 * يُشغَّل على الاثنين. لذا هذا الملفّ **بلا استيراد**: يُحمَّل في node مباشرةً داخل ذلك الاختبار.
 *
 * القاعدة: أكبر وحدةٍ ذات معنى، ثمّ بقيّتها بالوحدة التي تليها، والنصف «ونصف» (٣٠ دقيقة بعد
 * الساعات، ١٢ ساعة بعد الأيّام). ما دون الساعة بعد الأيّام لا يُذكر.
 *
 * @param minutes الدقائق — `null` أو سالبٌ يعطي `null` كي يعرض المنادي «—»
 */
export function humanDuration(minutes?: number | null): string | null {
  if (minutes == null || !Number.isFinite(minutes) || minutes < 0) return null;

  const mins = Math.round(minutes);
  if (mins < 60) return alone(mins, MINUTE);
  if (mins < 1440) return compound(Math.floor(mins / 60), mins % 60, 30, HOUR, MINUTE);

  return compound(Math.floor(mins / 1440), Math.floor((mins % 1440) / 60), 12, DAY, HOUR);
}

/*
 * **العدد ومعدوده** — نظير `App\Support\ArabicCount`. صارت المدد إعدادات، فالنصّ المبنيّ منها
 * يقع على «2 ساعة» و«5 ساعة» إن أُلصق المعدود بالرقم إلصاقاً.
 * [الواحد وحده، الواحد صدراً، مثنّى، جمع ٣–١٠، مفرد ١١–٩٩، مفرد بعد المئة و0]
 */
type CountForms = [string, string, string, string, string, string];
const MINUTE: CountForms = ['دقيقة واحدة', 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة', 'دقيقة'];
const HOUR: CountForms = ['ساعة واحدة', 'ساعة', 'ساعتين', 'ساعات', 'ساعة', 'ساعة'];
const DAY: CountForms = ['يوم واحد', 'يوم', 'يومين', 'أيام', 'يوماً', 'يوم'];

function count(n: number, one: string, [, , two, few, many, hundred]: CountForms): string {
  const tail = n % 100;
  if (n === 1) return one;
  if (n === 2) return two;
  if (tail >= 3 && tail <= 10) return `${n} ${few}`;
  if (tail >= 11) return `${n} ${many}`;

  return `${n} ${hundred}`; // 0 والمئات: «100 دقيقة»
}

/** الوحدة وحدها — «ساعة واحدة». */
function alone(n: number, forms: CountForms): string {
  return count(n, forms[0], forms);
}

/** صدرٌ وبقيّة — «ساعة و15 دقيقة»، «ساعتين ونصف». */
function compound(whole: number, remainder: number, half: number, head: CountForms, rest: CountForms): string {
  if (remainder === 0) return alone(whole, head);
  const tail = remainder === half ? 'نصف' : count(remainder, rest[1], rest);

  return `${count(whole, head[1], head)} و${tail}`;
}

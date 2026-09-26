/**
 * **العدد ومعدوده بالعربيّة** — نظير `App\Support\ArabicCount::of` في الخادم، بالقاعدة نفسها:
 * ١ و٢ لفظان مستقلّان، ٣–١٠ جمع، ١١–٩٩ مفرد منصوب، والمئات وما يليها (و٠) مفرد.
 *
 * كانت بطاقات اللوحة تُلصق المعدود بالرقم إلصاقاً: «5 قضية»، «12 تذكرة»، «0 استشارة» — خطأٌ
 * يقرؤه المدير في أوّل شاشة (رُصد في المتصفّح 2026-09-26). والصيغ لكلّ اسمٍ معرَّفة هنا مرّةً.
 */
export interface NounForms {
  /** بعد الواحد — «قضية» (والبطاقة تكتب الرقم ١ قبله) */
  one: string;
  /** المثنّى — «قضيتان» */
  two: string;
  /** ٣–١٠ — «قضايا» */
  few: string;
  /** ١١–٩٩ — «قضية» (أو «يوماً» للمنصوب المنوّن) */
  many: string;
  /** المئات و٠ — «قضية» */
  hundred?: string;
}

/** المعدود وحده الملائم للرقم — للبطاقات التي تكتب الرقم كبيراً والاسم بجانبه صغيراً. */
export function countNoun(n: number, forms: NounForms): string {
  const tail = Math.abs(n) % 100;
  if (n === 1) return forms.one;
  if (n === 2) return forms.two;
  if (tail >= 3 && tail <= 10) return forms.few;
  if (tail >= 11) return forms.many;

  return forms.hundred ?? forms.many;
}

/** الرقم ومعدوده جملةً — «5 قضايا»، «قضيتان». */
export function arabicCount(n: number, forms: NounForms): string {
  return n === 2 ? forms.two : `${n} ${countNoun(n, forms)}`;
}

export const NOUN = {
  case: { one: 'قضية', two: 'قضيتان', few: 'قضايا', many: 'قضية' },
  ticket: { one: 'تذكرة', two: 'تذكرتان', few: 'تذاكر', many: 'تذكرة' },
  client: { one: 'عميل', two: 'عميلان', few: 'عملاء', many: 'عميلاً', hundred: 'عميل' },
  execFile: { one: 'ملف تنفيذ نشط', two: 'ملفّا تنفيذ نشطان', few: 'ملفات تنفيذ نشطة', many: 'ملف تنفيذ نشطاً', hundred: 'ملف تنفيذ نشط' },
  videoConsult: { one: 'استشارة مرئية قادمة', two: 'استشارتان مرئيتان قادمتان', few: 'استشارات مرئية قادمة', many: 'استشارة مرئية قادمة' },
} satisfies Record<string, NounForms>;

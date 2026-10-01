/**
 * **قرارات وقت الحجز في نوافذ الجدولة — مصدرٌ واحد** (إصلاح 2026-09-30: الوقت المخصّص كان يُمسح).
 *
 * كانت نافذة «إرسال دعوة اجتماع للعميل» تمسح أيّ وقتٍ ليس من الشرائح الجاهزة، فيختفي الوقت المخصّص (11:20)
 * لحظة إدخاله؛ ونافذة جدولة محادثة التذكرة تمسحه كلّما حُمّلت الشرائح. الحكم هنا واحد للنافذتين.
 *
 * هذا الملفّ **بلا استيراد** عمداً: يُحمَّل في node مباشرةً داخل `BookingTimeTest`.
 */

export interface SlotLike {
  time: string;
  taken?: boolean;
}

/**
 * الوقت المختار بعد تحديث الشرائح (تغيير المحامي أو اليوم): **يُمسح فقط إن كان شريحةً صارت محجوزة.**
 * الوقت المخصّص ليس في الشبكة أصلاً فيبقى — والخادم يبقى الحَكَم: يرفض الماضي وخارج الدوام والتعارض برسالة.
 */
export function keepChosenTime(chosen: string, slots: ReadonlyArray<SlotLike | string>): string {
  if (chosen === '') {
    return '';
  }

  const taken = slots.some((s) => typeof s !== 'string' && s.time === chosen && s.taken === true);

  return taken ? '' : chosen;
}

/** «صباحاً» · «ظهراً» · «مساءً» لوقتٍ بعينه (HH:MM) — يُحسب من الوقت المعروض نفسه لا من بداية الشريحة. */
export function periodOf(hhmm: string): string {
  const hour = parseInt(hhmm.split(':')[0], 10);

  if (hour < 12) {
    return 'صباحاً';
  }

  return hour === 12 ? 'ظهراً' : 'مساءً';
}

/**
 * العميل الذي تُفتح عليه نافذة «حجز موعد جديد»: المختار حاليّاً إن كانت له استشارةٌ مدفوعة بانتظار موعد،
 * وإلّا أوّل عميلٍ له ذلك، وإلّا المختار أو أوّل عميل. كانت تُفتح على أوّل عميلٍ في القائمة فيبدو زرّ التأكيد معطّلاً.
 */
export function bookingClientId(
  current: number | '',
  awaitingClientIds: ReadonlyArray<number>,
  clientIds: ReadonlyArray<number>,
): number | '' {
  if (current !== '' && awaitingClientIds.includes(current)) {
    return current;
  }

  if (awaitingClientIds.length > 0) {
    return awaitingClientIds[0];
  }

  return current !== '' ? current : (clientIds[0] ?? '');
}

/**
 * «HH:MM» بصيغة ٢٤ ساعة من أيّ صيغةٍ مخزَّنة: الجديدة «14:20»، والقديمة «02:00 PM» أو «2:00 م».
 * كانت القائمة تعرض الخامّ فيُقرأ «PM 02:00» مقلوباً في السطر العربيّ. ما لا يُفهم يُعاد كما هو.
 */
export function to24h(time: string): string {
  const m = /^\s*(\d{1,2}):(\d{2})\s*(AM|PM|ص|م)?\s*$/i.exec(time ?? '');

  if (!m) {
    return (time ?? '').trim();
  }

  let hour = parseInt(m[1], 10);
  const suffix = (m[3] ?? '').toUpperCase();

  if ((suffix === 'PM' || suffix === 'م') && hour < 12) {
    hour += 12;
  } else if ((suffix === 'AM' || suffix === 'ص') && hour === 12) {
    hour = 0;
  }

  return `${String(hour).padStart(2, '0')}:${m[2]}`;
}

/** اسم الشهر واليوم بالعربيّة من مُنسِّق المتصفّح (تقويمٌ ميلاديّ) — لا قائمة أسماءٍ ثانية بجانب `WEEK_DAY_NAMES`. */
const arDate = (date: Date, part: 'month' | 'weekday'): string =>
  new Intl.DateTimeFormat('ar-u-ca-gregory-nu-latn', { timeZone: 'UTC', [part]: 'long' }).format(date);

export interface WhenParts {
  /** مفتاح ترتيب «YYYY-MM-DD HH:MM» — '' حين لا تاريخ مفهوماً */
  key: string;
  day: string;
  month: string;
  weekday: string;
  /** «2:00 م» */
  time: string;
}

/** أجزاء الموعد للعرض — من يومٍ «YYYY-MM-DD» ووقتٍ بأيّ صيغةٍ مخزَّنة. */
export function whenParts(day: string, time: string): WhenParts {
  const hhmm = to24h(time);
  const t = /^(\d{2}):(\d{2})$/.exec(hhmm);
  const label = t ? `${parseInt(t[1], 10) % 12 === 0 ? 12 : parseInt(t[1], 10) % 12}:${t[2]} ${parseInt(t[1], 10) >= 12 ? 'م' : 'ص'}` : hhmm;
  const d = /^(\d{4})-(\d{2})-(\d{2})$/.exec((day ?? '').trim());

  if (!d) {
    return { key: '', day: '—', month: (day ?? '').trim(), weekday: '', time: label };
  }

  const date = new Date(Date.UTC(+d[1], +d[2] - 1, +d[3]));

  return {
    key: `${d[1]}-${d[2]}-${d[3]} ${t ? hhmm : '00:00'}`,
    day: String(+d[3]),
    month: arDate(date, 'month'),
    weekday: arDate(date, 'weekday'),
    time: label,
  };
}

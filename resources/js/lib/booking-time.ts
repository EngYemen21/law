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

import { useCallback, useMemo } from 'react';
import { useSettings } from '@/lib/settings';
import type { SharedSettings } from '@/lib/settings';

/**
 * **شبكة شرائح الاستشارة في الواجهة — مبنيّةً من إعدادات الخادم لا منقوشة.**
 *
 * كانت ثلاث نسخ لا تتّفق: المحرّك (`LawyerAvailability`) يولّد ٠٠–٢٣ بساعة، وشبكة الموظّف
 * اليوميّة ٠٩–٢٢، والمنتقي حين لا تصله شرائح ٠٨–٢٢ بنصف ساعة — فيعرض الموظّف ساعاتٍ لا
 * يولّدها المحرّك ويُخفي أخرى يولّدها. صار الثلاثة يقرؤون `consult_day_start/end/slot_minutes`
 * من الخاصّيّة المشتركة، وقاعدة التوليد هنا نسخةُ قاعدة `LawyerAvailability::slotsFromIntervals`:
 * الخطوة طول الشريحة، ولا شريحة تبدأ ما لم تنتهِ قبل نهاية الساعات.
 */

type SlotSettings = Pick<SharedSettings, 'consult_day_start' | 'consult_day_end' | 'consult_slot_minutes'>;

const hm = (minutes: number): string =>
  `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

/** بدايات الشرائح «HH:MM» لليوم. */
export function consultSlotGrid(s: SlotSettings): string[] {
  const out: string[] = [];
  const length = s.consult_slot_minutes;

  for (let from = s.consult_day_start * 60; from + length <= s.consult_day_end * 60; from += length) {
    out.push(hm(from));
  }

  return out;
}

/** نهاية الشريحة التي تبدأ عند `time` — «14:30» + ٦٠ ⇐ «15:30». */
export function slotEnd(time: string, minutes: number): string {
  const [h, m] = time.split(':').map((v) => parseInt(v, 10));

  return hm(Math.min(24 * 60, h * 60 + (m || 0) + minutes));
}

/** يوم «YYYY-MM-DD» من أيّام الدوام؟ (`consult_work_days`: «0,1,2,3,4» بالأحد=0 كما في الخادم) */
export function isWorkDay(workDays: string, dateISO: string): boolean {
  const day = new Date(`${dateISO}T00:00:00`).getDay();

  return workDays.split(',').map(Number).includes(day);
}

const NO_SLOTS: string[] = [];

/**
 * الشبكة وطول الشريحة من الخاصّيّة المشتركة، و`gridOn(date)` شبكةُ يومٍ بعينه — فارغةٌ في يوم العطلة
 * كما يُرجعها المحرّك (`LawyerAvailability::slotsFor`)، فلا تعرض شاشةٌ شرائح يرفضها الخادم.
 */
export function useConsultSlots(): { grid: string[]; slotMinutes: number; gridOn: (dateISO: string) => string[]; allowOverlap: boolean } {
  const { consult_day_start, consult_day_end, consult_slot_minutes, consult_work_days, consult_allow_overlap, consult_allow_outside_office } = useSettings();

  // مصفوفةٌ ثابتة الهويّة ما لم تتغيّر القيم — الشاشات تضعها في تبعيّات `useMemo`
  const grid = useMemo(
    () => consultSlotGrid({ consult_day_start, consult_day_end, consult_slot_minutes }),
    [consult_day_start, consult_day_end, consult_slot_minutes],
  );
  // بلا تاريخٍ بعدُ تُعرض شبكة الدوام — «لم يُختر يوم» ليس «يوم عطلة». ومع «السماح بالحجز خارج الدوام»
  // يُعرض يوم العطلة بشبكة الساعات نفسها (والخادم يقبله بتنبيه — `ConsultBooking::officeHoursVerdict`)
  const allowOffHours = consult_allow_outside_office === 1;
  const gridOn = useCallback(
    (dateISO: string) => (dateISO === '' || allowOffHours || isWorkDay(consult_work_days, dateISO) ? grid : NO_SLOTS),
    [grid, consult_work_days, allowOffHours],
  );

  // «السماح بحجزٍ متداخل» — المنتقي يتيح المحجوز، والخادم يقرّر (`ConsultBooking::conflictVerdict`)
  return { grid, slotMinutes: consult_slot_minutes, gridOn, allowOverlap: consult_allow_overlap === 1 };
}

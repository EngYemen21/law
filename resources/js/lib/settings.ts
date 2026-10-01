import { usePage } from '@inertiajs/react';
import { humanDuration } from '@/lib/human-duration';

/**
 * **متغيّرات النظام كما يرسلها الخادم — القارئ الواحد في الواجهة.**
 *
 * مصدرها `SettingsRegistry` (ونسبة الضريبة عبر `Setting::vatRate()`)، مشارَكةً في كلّ صفحة تحت
 * `settings` من `HandleInertiaRequests::SHARED_SETTINGS`. كانت الشاشات تنقش نسخها («٣ دفعات»،
 * «(15%)»، اسم المكتب وهاتفه) فيغيّرها المدير ولا يتغيّر ما يقرؤه العميل — ويحرس عودتها
 * `SettingsNotHardcodedTest`.
 *
 * **لإضافة مفتاح**: أضِفه إلى `SHARED_SETTINGS` في الخادم، ثمّ حقلَه هنا بالاسم نفسه.
 */
export interface SharedSettings {
  /** عدد دفعات خطّة التقسيط للخطط الجديدة (الخطّة المحفوظة تحمل عددها في ملفّها). */
  installments_count: number;
  /** سقف نسبة أتعاب التنفيذ من المحصّل (٪) — ما فوقه يردّه الخادم خطأَ إدخال. */
  exec_max_collection_pct: number;
  /** نسبة ضريبة القيمة المضافة الحاليّة (٪) — الفاتورة الصادرة تحمل نسبتها المجمَّدة. */
  vat_rate: number;
  /**
   * اسم المكتب — الاسم الواحد في كلّ مكان: المستندات والبريد والصفحة الترويجيّة وصفحة الدخول
   * وعنوان التبويب (`app.tsx`) والقائمة الجانبيّة. لا تنقشه في شاشة؛ يحرس ذلك `SettingsNotHardcodedTest`.
   */
  office_name: string;
  office_phone: string;
  office_url: string;
  /** أوّل ساعة حجز (0–23) — الشبكة تُبنى منها في `consult-slots.ts`. */
  consult_day_start: number;
  /** ساعة انتهاء آخر شريحة (1–24). */
  consult_day_end: number;
  /** أيّام الدوام «0,1,2,3,4» (الأحد=0) — لا شرائح حجز في غيرها (`isWorkDay` في `consult-slots.ts`). */
  consult_work_days: string;
  /** ١ = يُقبل حجز الاستشارة فوق انشغالٍ آخر للمحامي بتنبيه (عدا جلسة المحكمة)، ٠ = يُرفض. */
  consult_allow_overlap: number;
  /** ١ = يُقبل حجز الاستشارة خارج أيّام الدوام وساعاته بتنبيه — شبكة يوم العطلة تُعرض للحجز، ٠ = يُرفض. */
  consult_allow_outside_office: number;
  /** طول الشريحة ومدّة الاستشارة بالدقائق. */
  consult_slot_minutes: number;
  /** عمر الطلب المفتوح بالدقائق الذي يُعدّ بعده «متأخّراً» في شاشتي الاستشارات. */
  consult_request_late_minutes: number;
  /** يُفعَّل زرّ الدخول للجلسة المرئيّة قبل الموعد بهذه الدقائق — النصّ منها بـ`joinOpensText`. */
  session_join_opens_minutes: number;
  /** يستطيع الطاقم بدء الاستشارة قبل الموعد بهذه الدقائق — النصّ منها بـ`useStaffStartText`. */
  consult_staff_start_minutes: number;
  /** الحضور قبل الموعد الحضوريّ بالدقائق — نصّ بطاقة الموعد (ونظيرها `AppointmentCardPdf`). */
  office_arrival_minutes: number;
  /** مهلة الاستئناف بالأيّام للأحكام التي تُسجَّل الآن — النصّ منها بـ`useAppealDaysText`. */
  appeal_deadline_days: number;
}

/** متغيّرات النظام من الخاصيّة المشتركة. */
export function useSettings(): SharedSettings {
  return usePage().props.settings;
}

/**
 * «N دفعات» بصيغة العدد الصحيحة. العدد إعدادٌ مداه ٢..٦ (`SettingsRegistry`)، فلا يكفي
 * إلصاق «دفعات» بالرقم: «٢ دفعات» خطأ، والمثنّى «دفعتين».
 */
export function installmentsText(count: number): string {
  return count === 2 ? 'دفعتين' : `${count} دفعات`;
}

/** «5 دقائق» — مهلة فتح الدخول بوحدتها الطبيعيّة، لكلّ نصٍّ يعلنها للعميل (نظير `SessionWindow::joinOpensLabel`). */
export function useJoinOpensText(): string {
  return humanDuration(useSettings().session_join_opens_minutes) ?? '';
}

/** «30 يوماً» — مهلة الاستئناف بوحدتها (نظير `RecordRuling` حين يكتب `update_text`). */
export function useAppealDaysText(): string {
  return humanDuration(useSettings().appeal_deadline_days * 1440) ?? '';
}

/** «15 دقيقة» — نافذة بدء الطاقم بوحدتها الطبيعيّة (نظير `SessionWindow::staffStartLabel`). */
export function useStaffStartText(): string {
  return humanDuration(useSettings().consult_staff_start_minutes) ?? '';
}

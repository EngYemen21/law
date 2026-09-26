/**
 * **غرفةُ الجلسة المرئيّة — النوعُ الواحد ومعجمُ الكلمات الواحد.**
 *
 * كانت الغرف الثماني (العميل · الموظّف · المحامي · الإدارة × استشارة · اجتماع) تبني تفاصيلها
 * في الواجهة من بطاقاتٍ مختلفة (`ConsultCard` · `FullMeetingCard` · `Meeting::toCard`)، فتختلف
 * الصفوف والعناوين وشرطُ زرّ الإنهاء وشارةُ التسجيل من غرفةٍ لأخرى. فصار الخادم يرسل عقداً واحداً
 * `room` في كلّ صفحة غرفة، والواجهة تعرضه ولا تشتقّ منه قراراً:
 *
 * - `recording` يصل **false للعميل دائماً** — والواجهة لا تستنتجه من أحداث Zoom (قرار المالك ٢٠٢٦-٠٩-٢٦).
 * - `endAction` للطاقم وحده، وشرطُه الواحد `enabled` (الجلسة منعقدة) يحسبه الخادم.
 * - `measuredDuration` هي المدّة التي قاسها Zoom، ولا تصل إلا بعد الانتهاء — لا مؤقّتَ يُختلق مدّة.
 */

export type RoomKind = 'consult' | 'meeting';

export interface RoomRow {
  label: string;
  value: string;
}

export interface RoomEndAction {
  url: string;
  label: string;
  /** الشرط الواحد لزرّ الإنهاء: الجلسة منعقدة الآن (يحسبه الخادم لا الواجهة). */
  enabled: boolean;
  /** وجهة ما بعد الإنهاء الناجح. */
  redirect: string;
  placeholder: string;
}

export interface Room {
  kind: RoomKind;
  ref: string;
  title: string;
  statusLabel: string;
  live: boolean;
  ended: boolean;
  rows: RoomRow[];
  /** للطاقم وحده — العميل يصله false دائماً. */
  recording: boolean;
  /** المدّة كما قاسها Zoom — بعد الانتهاء فقط. */
  measuredDuration: string | null;
  /** إجراء الإنهاء — للطاقم وحده (null للعميل). */
  endAction: RoomEndAction | null;
  summaryHref: string | null;
  back: string;
  /** قناة حالة الغرفة المشتركة (خاصّة). */
  channel: string;
  /** قناة الطاقم — تزيد `recording`؛ null للعميل. */
  staffChannel: string | null;
}

/** حمولة `.room.state` على `channel` — وقناة الطاقم تزيد `recording`. */
export interface RoomStatePayload {
  live: boolean;
  ended: boolean;
  statusLabel: string;
  measuredDuration: string | null;
  /** عدد الحاضرين الآن كما يرسله الخادم. */
  participants: number | null;
  recording?: boolean;
}

/**
 * الطاقم يُعرَف بقناته لا بدورٍ تستنتجه الواجهة: الخادم لا يمنح العميل قناة الطاقم.
 * وهذا الشرط وحده يفتح شارة التسجيل — فلا يراها العميل ولو أخطأ الخادم في `recording`.
 */
export const isStaffRoom = (room: Room): boolean => room.staffChannel !== null;

/** مفتاح الجلسة الواحد: النوع والمرجع (CN-… · M-…). */
export const roomKey = (room: Pick<Room, 'kind' | 'ref'>): string => `${room.kind}:${room.ref}`;

/**
 * **معجمُ الغرفة — مصدرُ كلماتها الواحد.** كانت الغرفة تقول «داخل المنصّة» مرّةً و«داخل الموقع»
 * أخرى، و«المستشار» في غرفة و«المحامي» في أخرى، ولأزرار الفعل الواحد أسماءٌ شتّى. فكلّ نصٍّ
 * ثابتٍ في الغرفة والشريط العائم يُقرأ من هنا.
 */
export const ROOM_TEXT = {
  preparing: 'يجري تجهيز غرفة الجلسة…',
  joining: 'يجري الانضمام إلى الجلسة…',
  leave: 'مغادرة',
  leaveHint: 'تغادر أنت وحدك — الجلسة تبقى قائمةً للآخرين',
  back: 'رجوع',
  backHint: 'تبقى الجلسة مفتوحةً في نافذةٍ مصغّرة',
  returnToRoom: 'العودة إلى الجلسة',
  rejoin: 'الانضمام من جديد',
  retry: 'إعادة المحاولة داخل الموقع',
  details: 'التفاصيل',
  detailsTitle: 'تفاصيل الجلسة',
  close: 'إغلاق',
  dismiss: 'إخفاء',
  recording: 'تسجيل',
  ended: 'انتهت الجلسة',
  endedThanks: 'شكراً لك.',
  measuredDuration: 'المدّة الفعليّة',
  durationPending: 'تُحتسب المدّة من Zoom بعد قليل',
  participants: 'الحاضرون الآن',
  left: 'غادرتَ الجلسة',
  leftHint: 'الجلسة ما زالت قائمة — يمكنك الانضمام من جديد.',
  notConfigured: 'تضمين Zoom غير مُهيّأ بعد.',
  prepareFailed: 'تعذّر تجهيز غرفة الجلسة.',
  sdkFailed: 'تعذّر تحميل مكتبة Zoom.',
  notStarted: 'لم يبدأ المضيف الجلسة بعد — حاول مرة أخرى عند بدء الموعد.',
  joinFailed: 'تعذّر الانضمام إلى الجلسة.',
  failedTitle: 'تعذّر بدء الجلسة داخل الموقع',
  otherActive: 'أنت الآن في جلسةٍ أخرى',
  otherActiveHint: 'لا تُفتح جلستان معاً. عُد إليها، أو غادرها وانضمّ إلى هذه.',
  leaveAndJoin: 'مغادرتها والانضمام هنا',
  resumeTitle: 'انقطع اتصالك بالجلسة عند إعادة تحميل الصفحة',
  endHint: 'يُنهي الجلسة للجميع ولا يُتراجع عنه. التدوين يُبنى عليه الملخّص — وبلا تدوين لا يُكتب شيء.',
  endNotesLabel: 'تدوين الجلسة (اختياريّ)',
  endDisabled: 'الإنهاء متاحٌ حين تنعقد الجلسة',
  endFailed: 'تعذّر إنهاء الجلسة',
  unloadWarning: 'أنت داخل جلسة مرئيّة — مغادرة الصفحة تقطع اتصالك بها.',
  missing: 'لا توجد جلسة محدّدة',
} as const;

/** اسم الكيان في الجمل: الاستشارة لا «اجتماع»، والاجتماع لا «استشارة». */
export const roomNoun = (kind: RoomKind): string => (kind === 'meeting' ? 'الاجتماع' : 'الاستشارة');

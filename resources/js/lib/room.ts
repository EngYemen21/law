import { router } from '@inertiajs/react';
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
  /** كم دخل من خارج المنصّة (تطبيق Zoom أو رقم الاجتماع مباشرةً) — للطاقم وحده، والعميل يصله 0. */
  outsiders: number;
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
  outsiders?: number;
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
 * ثابتٍ في الغرفة يُقرأ من هنا.
 */
export const ROOM_TEXT = {
  preparing: 'يجري تجهيز غرفة الجلسة…',
  joining: 'يجري الانضمام إلى الجلسة…',
  leave: 'مغادرة',
  leaveHint: 'تغادر أنت وحدك — الجلسة تبقى قائمةً للآخرين',
  back: 'رجوع',
  backHint: 'تغادر الجلسة وتعود إلى صفحتك',
  backConfirmTitle: 'مغادرة الجلسة؟',
  backConfirm: 'الرجوع يُخرجك من الجلسة، وتبقى قائمةً للآخرين. يمكنك العودة إليها من زرّ الدخول.',
  rejoin: 'الانضمام من جديد',
  retry: 'إعادة المحاولة داخل الموقع',
  details: 'التفاصيل',
  detailsTitle: 'تفاصيل الجلسة',
  close: 'إغلاق',
  recording: 'تسجيل',
  outsiders: (n: number) => `⚠️ ${n === 1 ? 'مشاركٌ دخل' : `${n} مشاركين دخلوا`} من خارج المنصّة — راجع قائمة المشاركين في Zoom وأزِل من لا يخصّ الجلسة.`,
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
  endHint: 'يُنهي الجلسة للجميع ولا يُتراجع عنه. التدوين يُبنى عليه الملخّص — وبلا تدوين لا يُكتب شيء.',
  endNotesLabel: 'تدوين الجلسة (اختياريّ)',
  endDisabled: 'الإنهاء متاحٌ حين تنعقد الجلسة',
  endFailed: 'تعذّر إنهاء الجلسة',
  unloadWarning: 'أنت داخل جلسة مرئيّة — مغادرة الصفحة تقطع اتصالك بها.',
  missing: 'لا توجد جلسة محدّدة',
} as const;

/**
 * **الجلسة في تبويبها** (قرار المالك 2026-10-03): غرفة Zoom صفحةٌ مستقلّة — ما يوصي به توثيق Zoom
 * («Dedicated route… recommended»، import-sdk) — فيبقى التبويب الأصليّ للتصفّح. اسمٌ واحد للتبويب: دخولٌ ثانٍ
 * يعود إليه بدل فتح جلستين معاً (كاميرا وميكروفون واحدان). وإن حجب المتصفّح النافذة تُفتح في التبويب نفسه.
 */
export const ROOM_TAB = 'salasel-room';

export function openRoomTab(href: string | null | undefined): void {
  if (!href) {
    return;
  }

  const tab = window.open(href, ROOM_TAB);

  if (tab) {
    tab.focus();
  } else {
    window.location.assign(href);
  }
}

/**
 * **هل الوجهة غرفة جلسة؟** — روابط الغرف تأتي من الخادم في حقولٍ شتّى (`joinLink` · `slink` · `meetLink` · `link`
 * والإشعارات)، فيُحكم بالمسار لا باسم الحقل: كلّ وجهةٍ إلى غرفة تُفتح في تبويبها.
 */
const ROOM_PATH = /^\/(?:consults\/room|meetingroom|(?:admin|employee|lawyer)\/(?:videoroom|meetingroom))$/;

export function isRoomHref(href: string): boolean {
  try {
    return ROOM_PATH.test(new URL(href, window.location.origin).pathname);
  } catch {
    return false;
  }
}

/** انتقالٌ عامّ إلى وجهةٍ من الخادم: الغرفة في تبويبها، وما سواها انتقالٌ عاديّ. */
export function visitHref(href: string | null | undefined): void {
  if (!href) {
    return;
  }

  if (isRoomHref(href)) {
    openRoomTab(href);
  } else {
    router.visit(href);
  }
}

/** اسم الكيان في الجمل: الاستشارة لا «اجتماع»، والاجتماع لا «استشارة». */
export const roomNoun = (kind: RoomKind): string => (kind === 'meeting' ? 'الاجتماع' : 'الاستشارة');

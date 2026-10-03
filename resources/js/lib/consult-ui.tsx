import { Link, router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import type { ConfirmRequest } from '@/components/babylon/ConfirmDialog';
import Modal from '@/components/babylon/Modal';
import RevisionHistoryButton from '@/components/babylon/RevisionHistoryButton';
import RichTextEditor, { htmlToText, RichHtmlView } from '@/components/babylon/RichTextEditor';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { stageChanged, staffPatch } from '@/lib/consult-live';
import { RescheduleRequestNotice, useConsultReschedule } from '@/lib/consult-reschedule';
import { echo } from '@/lib/echo';
import {
  CONSULT_CHANNELS, CONSULT_FLOW, CONSULT_BOOKING_FLOW, CONSULT_PRIORITIES, cBookingStage, cStage, cHasStage,
  crChannelIcon, crChannelTone, maskClient
} from '@/lib/employee-data';
import type {AuditEntry} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan, useMasker } from '@/lib/permissions';
import { consultMediaUrls, SessionMediaPanel, TranscriptModal } from '@/lib/recording-ui';
import type { SessionMedia } from '@/lib/recording-ui';
import { openRoomTab } from '@/lib/room';
import { firstError } from '@/lib/server-message';
import { useStaffStartText } from '@/lib/settings';
import { inSessionSuffix, useInSession } from '@/lib/staff-presence';
import { useServerAction } from '@/lib/use-server-action';

// ============================================================
// واجهة الاستشارات المشتركة (سجلّ Consult الحقيقي من الخادم)
// يطابق consultRecvView + videoRoomView + vrEnd في index (82).html
// ============================================================

/**
 * **تأكيد ختم جلسة الاستشارة — نصٌّ واحد لكلّ شاشةٍ فيها زرّ «إنهاء الجلسة».** (قرار المالك 2026-09-26)
 * الختم لا يُتراجع عنه: يُغلق غرفة Zoom ويُبلغ الموكّل ويبدأ الملخّص — فلا يقع بنقرةٍ عابرة.
 */
export const CONFIRM_END_CONSULT: ConfirmRequest = {
  title: 'إنهاء الجلسة للجميع؟',
  message: 'تُختم الجلسة وتُغلق غرفتها في Zoom ويُبلَّغ العميل بانتهائها، ثمّ يُعدّ ملخّصها. لا يمكن استئنافها بعد الإنهاء.',
  confirmLabel: 'إنهاء الجلسة',
  cancelLabel: 'تراجع',
  tone: 'danger',
};

/** تأكيد إلغاء طلب الاستشارة قبل الجلسة — لكلّ شاشةٍ فيها الزرّ (الإدارة · طابور ما قبل الجلسة). */
export const CONFIRM_CANCEL_CONSULT_REQUEST: ConfirmRequest = {
  title: 'إلغاء طلب الاستشارة؟',
  message: 'يُلغى الطلب وفواتيره غير المدفوعة ويُبلَّغ العميل بالسبب. لا يمكن التراجع.',
  confirmLabel: 'إلغاء الطلب',
  cancelLabel: 'تراجع',
  tone: 'danger',
};

/**
 * **«لم يحضر» — نصٌّ واحد لكلّ شاشة** (قرار المالك 2026-09-27). يُشعر العميل ويُغلق الموعد
 * بلا جلسة؛ كان في صفحة المحامي وحدها، والشاشات الأخرى تُرسله بنقرة.
 */
export const CONFIRM_NO_SHOW: ConfirmRequest = {
  title: 'تسجيل عدم حضور العميل؟',
  message: 'تُسجَّل الجلسة غيابَ موكّل ويُشعَر بذلك — راجع غرفة الاجتماع قبل التأكيد.',
  confirmLabel: 'تسجيل الغياب',
  cancelLabel: 'تراجع',
  tone: 'danger',
};

/** بدء الجلسة — يُشعر العميل ويبدأ توقيتها (قرار المالك 2026-09-27). */
export const CONFIRM_START_CONSULT: ConfirmRequest = {
  title: 'بدء الجلسة الآن؟',
  message: 'تبدأ الجلسة ويُبلَّغ العميل ويُحتسب وقتها من الآن.',
  confirmLabel: 'بدء الجلسة',
  cancelLabel: 'تراجع',
};

/** اعتماد الإدارة ملخّصَ الاستشارة — يصل العميلَ وتكتمل تذكرته، ولا يُعدَّل بعده. */
export const CONFIRM_APPROVE_CONSULT_SUMMARY: ConfirmRequest = {
  title: 'اعتماد الملخّص وإرساله للعميل؟',
  message: 'يصل الملخّص العميلَ وتُنشر نتيجة تذكرته. لا يُعدَّل الملخّص بعد اعتماده.',
  confirmLabel: 'اعتماد وإرسال',
  cancelLabel: 'تراجع',
};

// نصّ قرار آمن للعرض — القرارات نصوص عادةً، لكن بيانات قديمة قد تحمل كائن مهمّة {title,...}
// (نظير الحارس نفسه في DecisionTasks::create على الخادم) فلا يُكسَر React عند عنصر غير نصّي.
function decisionText(x: unknown): string {
  if (typeof x === 'string') {
    return x;
  }

  return (x as { title?: string })?.title ?? JSON.stringify(x);
}

// بطاقة الاستشارة كما يعيدها الخادم (Consult::toCard)
export interface ConsultCard {
  /** وقائع العميل كما كتبها عند الحجز (`consults.details`) — حقلٌ مستقلّ عن الموضوع. */
  details?: string | null;
  /** سبب تعذّر الإسناد الآن بحسب مرحلة الحجز — من الخادم (`Consult::toCard`)؛ `null` = لا مانع. */
  assignBlocker?: string | null;
  /** في دورة الحجز المحامي مرشَّحٌ من التذكرة لا مُسنَد. */
  lawyerTentative?: boolean;
  /** الموعد المقترح بانتظار الاعتماد (قبل نشره). */
  proposedWhen?: string | null;
  /** مقترح المآل مفتوحٌ من بطاقة التذكرة: انتهت الجلسة ولم تُحوَّل التذكرة — من الخادم. */
  canProposeOutcome?: boolean;
  id: number;
  ref: string;
  client: string;
  subject: string;
  specialty?: string; // تخصّص الاستشارة (لتصفية منتقي المستشارين عند اختيار الموعد)
  /** خانة «التخصص» للعرض — التخصّص أو النوع، من الخادم (`Consult::specialtyLabel`). */
  specialtyLabel: string;
  channel: string; // مرئية / حضورية / هاتفية
  lawyer: string;
  when: string;
  place: string;
  phone: string;
  slink: string;
  canJoin?: boolean; // زر الدخول مفعّل؟ (بعد إطلاق الرابط قبل الموعد بـ`session_join_opens_minutes`)
  missed?: boolean; // فات موعدها بلا جلسة (يشتقه الخادم)
  /** سُجّلت «لم تُعقد» — علمٌ مستقلّ عن `missed` في البطاقتين (قرار المالك 2026-09-27). */
  notHeld?: boolean;
  /** لون شارة الحالة (`ConsultStatus::tone`) ولون شارة الجلسة (`SessionState::tone`) — من الخادم. */
  tone: string;
  sessionTone: string;
  /** مجموعات الحالة من الخادم (`Consult::TERMINAL_STATUSES` · `CLOSED_STATUSES` · `SESSION_ENDED`) — لا قوائم في الواجهة. */
  isTerminal: boolean;
  isClosed: boolean;
  sessionEnded: boolean;
  /** كم مرّة أُعيدت جدولتها — السقف في `reschedule.limit` المشترك من الخادم. */
  rescheduleCount?: number;
  /** يسمح حارس `RescheduleConsult` بإعادة جدولتها الآن — الزرّ يتبعه لا يخمّن. */
  canReschedule?: boolean;
  /** يسمح حارس `MarkNoShow` بوسمها «لم يحضر» الآن. */
  canMarkNoShow?: boolean;
  /** شروط `approveSummary` (`Consult::summaryApprovalBlocker`) — يُظهر زرّ الاعتماد. */
  canApproveSummary?: boolean;
  /** طلب العميل تغيير الموعد، معلّقٌ حتى يُعاد جدولتها أو يُرفض. */
  clientRescheduleRequest?: { at: string; note: string | null } | null;
  startable?: boolean; // «بدء الجلسة» ضمن نافذة الموعد فقط (يشتقه الخادم — بطاقة المكتب)
  startsAt?: string | null;
  session: string; // بانتظار الجلسة / جلسة جارية / منتهية
  status: string;
  summary: string | null;
  /** الملخّص بتنسيق المحامي/الإدارة — منقّى في الخادم (`HasRichText::html`) */
  summaryHtml?: string | null;
  /** الملخّص محجوبٌ عن العميل حتى يعتمده محامٍ — انظر `Consult::toClientCard`. */
  summaryPending?: boolean;
  summaryApproved?: boolean;
  /** حُرّر الملخّص بيد إنسان قبل الاعتماد (بطاقة المكتب وحدها). */
  summaryEdited?: boolean;
  /** ختم الاعتماد (ISO) — `null` يعني لم يُعتمد بعد. */
  summaryApprovedAt?: string | null;
  /** اعتمده المستشار ويُنتظر اعتماد الإدارة */
  summaryLawyerApproved?: boolean;
  /** اقتراح الموظّف بانتظار اعتماد الإدارة (قرار المالك 2026-09-14) */
  proposal?: { date: string | null; time: string | null; lawyer: string; lawyerId: number | null; channel: string } | null;
  /**
   * مصدر **الملخّص** وحده — غير `aiSource` الذي يصف تحليل ما قبل الجلسة.
   * خلطُهما كان يُعيد وسم تحليلٍ نجح بأنّه فاشل. و`null` = لم يُقَس.
   */
  summaryAiSource?: '' | 'ai_success' | 'fallback' | 'manual_required' | 'human_approved' | null;
  /** مادّة جلسة Zoom كما وردت — مفصولة عن الملخّص الذي يصل العميل. */
  zoomSummary?: string | null;
  /** سجلّ الحضور من Zoom — من دخل ومتى وكم مكث. يُرسله `toCard` ولم يكن في العقد. */
  zoomParticipantsLog?: Array<{ name?: string; join_time?: string; leave_time?: string; duration_sec?: number }> | null;
  /** خطوات Zoom AI المقترحة — مادّةٌ للبناء لا قرارات معتمدة. */
  zoomAiNextSteps?: string[] | null;
  /** رقم التذكرة الأمّ — **رقماً لا معرّفاً**: مسار التحويل يربط بـ`number`. */
  /**
   * معرّف المحامي المسنَد — `null` يعني **لا إسناد**.
   * ولا يُستدلّ عليه بـ`lawyer` النصّيّ: الخادم يضمن له نائباً («المستشار القانوني»)
   * فلا يخلو أبداً، وشرطُ `!lawyer` كاذبٌ دائماً.
   */
  lawyerId?: number | null;
  ticketNo?: string | null;
  /**
   * رقم القضيّة إن تحوّلت التذكرة — **إشارةُ التحويل الحقيقيّة**.
   * كانت الشاشات تعدّ حالةً `'محولة إلى قضية'` لا يكتبها أيّ مسار، فمؤشّر
   * «استشارة أصبحت قضية» صفرٌ أبداً. والتحويل يقع على التذكرة لا الاستشارة.
   */
  caseNo?: string | null;
  /** تدوين الجلسة (بطاقة المكتب وحدها). */
  sessionNotes?: string | null;
  duration: string | null;
  /** المدّة المقيسة من Zoom بالثواني — null تعني «لم تُقَس». */
  durationSec?: number | null;
  /** مخرجات الجلسة للتشغيل والتنزيل عبر الخادم — أعلامٌ لا روابط Zoom (`RecordingArchive::availability`). */
  media?: SessionMedia;
  total: number;
  // دورة الحجز/الدفع (تسعير → فاتورة → دفع محاكى → اختيار الموعد)
  price?: number;
  vat?: number;
  priced?: boolean;
  paid?: boolean;
  paidAgo?: string | null; // «دُفع منذ …» لطلبات الإدارة المعلقة
  invoiceNo?: string | null;
  // رحلة المعالجة (CONSULT_FLOW)
  type: string;
  priority: string;
  received: string;
  employee: string;
  /** دقائقُ منذ الاستقبال — `null` تعني «لم تُقَس»، ولا تُقرأ صفراً. */
  ageMins: number | null;
  aiDone: boolean;
  /** App\Enums\AiSource — '' لصفوف ما قبل هجرة المصدر. */
  aiSource: '' | 'ai_success' | 'fallback' | 'manual_required' | 'human_approved';
  aiClass: string;
  aiSummary: string;
  aiLawyer: string;
  missing: string[];
  audit: AuditEntry[];
  decisions: string[];
  tasksCreated: boolean;
  /** أعلام الإجراءات من الخادم (`Consult::toCard`) — بدل مقارنة نصّ الحالة في الواجهة */
  needsPricing?: boolean;
  canRemindSchedule?: boolean;
  canApproveAnalysis?: boolean;
  /** مرحلة دورة الحجز من الخادم — `null` لما تجاوزها */
  bookingStage?: 'pricing' | 'payment' | 'scheduling' | 'approval' | null;
}

/**
 * **بطاقة العميل — ما يرسله `Consult::toClientCard` فعلاً، لا أكثر.**
 *
 * كانت صفحة «استشاراتي» تعلن `ConsultCard[]` بينما الخادم يمرّر `toClientCard()`
 * (٢٦ مفتاحاً من ٥٥): فحقولٌ **إلزاميّة** في النوع — `client`, `phone`, `hostLink`,
 * `type`, `priority`, `employee`, `ageMins`, `aiSummary`, `audit`, `missing` وغيرها —
 * لا تصل صفحة العميل أبداً. لا عطلَ اليوم لأنها لا تُقرأ، **لكنّ TypeScript يضمن
 * وجودها كذباً**: أوّل سطرٍ يقرأ `c.priority` يمرّ الفحص وينكسر في المتصفّح صامتاً.
 *
 * وحجبُ التحليل والمستندات الناقصة وسجلّ التدقيق عن العميل **مقصود** — فالنوع هنا
 * يصف قراراً لا نقصاً.
 */
export interface ClientConsultCard {
  id: number;
  ref: string;
  subject: string;
  details?: string | null;
  specialty?: string;
  channel: string;
  lawyer: string;
  when: string;
  place: string;
  slink: string;
  canJoin?: boolean;
  missed?: boolean;
  notHeld?: boolean;
  tone?: string;
  sessionTone?: string;
  /** في دورة الحجز — علمٌ لا مرحلة (لا يكشف «اعتماد الموعد» الداخليّ للعميل). */
  inBooking?: boolean;
  /** طلب تغيير الموعد — يُتاح وفق `Consult::rescheduleRequestBlocker` في الخادم وحده. */
  rescheduleRequest?: { pending: boolean; canRequest: boolean };
  session: string;
  status: string;
  /** ما ينتظره المكتب من الموكّل — يُملأ حين تُطلب مستندات. */
  missing?: string[];
  /** `null` ما لم يعتمده محامٍ — الحجب في الخادم لا في الواجهة. */
  summary: string | null;
  /** الملخّص بتنسيق المحامي/الإدارة — منقّى في الخادم (`HasRichText::html`) */
  summaryHtml?: string | null;
  summaryPending?: boolean;
  summaryApproved?: boolean;
  duration: string | null;
  price?: number;
  vat?: number;
  total: number;
  priced?: boolean;
  paid?: boolean;
  paidAgo?: string | null;
  invoiceNo?: string | null;
  /** تتبع الملخّص في الحجب — كانت تصل قبله. */
  decisions: string[];
  startsAt?: string | null;
}

// `lawyerFirst` أُزيلت (2026-09-11): كانت تقصّ اسم المحامي لكلمته الأولى في شاشة العميل،
// فتبتر «محمد. ب» إلى «محمد.» و«مستشار المكتب» إلى «مستشار». الخادمُ يقرّر الصيغة الآن (LawyerName).

// يطابق fmtDur
/**
 * طابعُ قيدِ التدقيق للعرض.
 *
 * `logAudit` يكتب مفتاحين بقصد: `time` نصٌّ عربيٌّ بصيغة ١٢ ساعة، و`at` بصيغة ISO
 * للفرز والحساب. والشاشة كانت تعرض `time` وحده — وقيودُ ما قبل هجرة ص/م تُكتب
 * «2026/09/06 12:48» فتُقرأ ظهراً وهي **00:48 فجراً**، فيبدو ترتيب السجلّ مقلوباً
 * وهو سليم. فحين يتوفّر `at` يُشتقّ منه طابعٌ لا يلتبس.
 */
function fmtAuditTime(a: AuditEntry): string {
    if (!a.at) {
        return a.time;
    }

    const d = new Date(a.at);
    if (Number.isNaN(d.getTime())) {
        return a.time;
    }

    const p = (n: number) => String(n).padStart(2, '0');
    const h = d.getHours();

    return `${d.getFullYear()}/${p(d.getMonth() + 1)}/${p(d.getDate())} `
        + `${p(h % 12 === 0 ? 12 : h % 12)}:${p(d.getMinutes())} ${h < 12 ? 'ص' : 'م'}`;
}

/**
 * تاريخ الموعد المقترح بالعربية («15 سبتمبر · 03:00») — الوقت كما اختاره الموظّف بلا تحويل منطقة.
 * التاريخ غير الصالح يُعرض كما ورد بدل «Invalid Date».
 */
export function proposalWhen(p: { date: string | null; time: string | null }): string {
  const d = p.date ? new Date(`${p.date}T00:00:00`) : null;
  const day = d && !Number.isNaN(d.getTime())
    ? d.toLocaleDateString('ar-SA-u-ca-gregory-nu-latn', { day: 'numeric', month: 'long' })
    : (p.date ?? '—');

  return `${day} · ${p.time ?? '—'}`;
}

export function fmtDur(s: number): string {
  const m = Math.floor(s / 60);
  const ss = s % 60;

  return `${m < 10 ? '0' : ''}${m}:${ss < 10 ? '0' : ''}${ss}`;
}

// يطابق vrInitials
export function vrInitials(n: string): string {
  if (!n) {
return '؟';
}

  const p = String(n).trim().split(/\s+/);

  return (p[0] ? p[0][0] : '') + (p[1] ? ` ${p[1][0]}` : '');
}

// ============================================================
// حالة ملخّص الجلسة — مصدرٌ واحد تقرؤه الشاشات الثلاث
// ============================================================

export type SummaryState = 'none' | 'pending' | 'approved';

/**
 * حالةُ الملخّص كما يقرّرها الخادم — لا كما تشتقّها كلّ شاشة على حدة.
 *
 * كانت شاشة الموظّف تعرض النصّ **بلا أيّ إشارة** إلى أنه غير معتمَد، فيقرأ رأياً
 * قانونياً محجوباً عن الموكّل ويحسبه نهائياً.
 */
export function summaryState(c: Pick<ConsultCard, 'summary' | 'summaryApproved' | 'summaryPending'>): SummaryState {
  if (c.summaryApproved) {
return 'approved';
}

  if (c.summaryPending || c.summary) {
return 'pending';
}

  return 'none';
}

/** وصفُ مصدر النصّ — و«لم يُسجَّل» لا «فشل»: `null` تعني لم يُقَس. */
export function summarySourceLabel(c: Pick<ConsultCard, 'summaryAiSource' | 'summaryEdited'>): string | null {
  if (c.summaryEdited) {
return 'حُرّر بيد محامٍ';
}

  switch (c.summaryAiSource) {
    case 'ai_success': return 'صياغة آليّة';
    case 'fallback': return 'تعذّرت الصياغة الآليّة';
    case 'manual_required': return 'يلزمه تحريرٌ بشريّ';
    case 'human_approved': return 'بصياغة بشريّة';
    default: return null; // لم يُقَس — لا يُدّعى مصدر
  }
}

/** شارةُ حالة الملخّص — تُغني عن اشتقاقٍ محلّي في كل شاشة. */
/**
 * **نصُّ نموذجٍ توليديّ يُعرض منسَّقاً لا بنجومه.**
 *
 * تعليمة `consultSummarySystem` مكتوبةٌ هي نفسها بـMarkdown (`**حصراً**`)، فالنموذج
 * يحاكي أسلوبها ويردّ بـ`**ملخص استشارة قانونية**`. والمشروع بلا مُصيِّر Markdown، وكلّ
 * مواضع العرض `white-space: pre-wrap` وحدها — فالنجوم تصل الشاشة حرفيّاً، **وشاشة
 * العميل منها** (`myconsults`).
 *
 * **ولا `dangerouslySetInnerHTML` هنا بحال:** المصدر نموذجٌ توليديّ، وإدراج مخرجه
 * HTML خامّاً بابُ حقنٍ لا يُفتح. React يُهرِّب النصّ تلقائياً، والتوكيد يُبنى عقداً
 * (`<strong>`) لا وسماً مُلصقاً.
 */
/** التوكيد داخل السطر: `**عريض**` و`*مائل*` — ونجمةٌ منفردة تبقى حرفاً لا تكسر ما بعدها. */
function inlineMarks(line: string): React.ReactNode[] {
  return line.split(/(\*\*[^*\n]+\*\*|(?<![*\w])\*[^*\s][^*\n]*?\*(?![*\w]))/g).map((part, i) => {
    if (/^\*\*[^*]+\*\*$/.test(part)) {
      return <strong key={i}>{part.slice(2, -2)}</strong>;
    }
    if (/^\*[^*\s][^*]*\*$/.test(part)) {
      return <em key={i}>{part.slice(1, -1)}</em>;
    }

    return <React.Fragment key={i}>{part}</React.Fragment>;
  });
}

type RtBlock =
  | { kind: 'p'; lines: string[] }
  | { kind: 'h'; text: string }
  | { kind: 'ul' | 'ol'; items: string[] }
  | { kind: 'hr' };

const RT_BULLET = /^\s*[-*•·]\s+/;
const RT_NUMBER = /^\s*[0-9٠-٩]+[.)،-]\s+/;

/**
 * **نصّ الملخّص فقراتٍ وقوائم — عناصر React لا HTML** (ملاحظة المالك 2026-09-27).
 *
 * كان المُصيِّر يفهم `**عريض**` والعناوين وحدها، فتظهر القوائم (`- `، `1.`) والفواصل (`---`) رموزاً
 * خاماً، ونجمةٌ منفردة تكسر العريض، وتضيع الأسطر في أيّ حاويةٍ بلا `pre-wrap`. الآن يُقسَّم النصّ
 * كتلاً: فقرة · عنوان · قائمة نقطيّة · قائمة مرقّمة · فاصل — فلا يعتمد على `pre-wrap` حاويته.
 * ولا `dangerouslySetInnerHTML`: المصدر نموذجٌ توليديّ، و React يُهرِّب النصّ.
 */
export const RichText: React.FC<{ text?: string | null; fallback?: string }> = ({ text, fallback }) => {
  const raw = (text ?? '').replace(/\r\n?/g, '\n').trim();

  if (raw === '') {
    return <>{fallback ?? ''}</>;
  }

  const blocks: RtBlock[] = [];
  const last = () => blocks[blocks.length - 1];

  for (const line of raw.split('\n')) {
    const t = line.trim();

    if (t === '') {
      blocks.push({ kind: 'p', lines: [] });
      continue;
    }
    if (/^([-*_])\1{2,}$/.test(t)) {
      blocks.push({ kind: 'hr' });
      continue;
    }
    if (/^#{1,6}\s+/.test(t)) {
      blocks.push({ kind: 'h', text: t.replace(/^#{1,6}\s+/, '') });
      continue;
    }

    const list = RT_BULLET.test(line) ? 'ul' : RT_NUMBER.test(line) ? 'ol' : null;
    if (list) {
      const item = line.replace(list === 'ul' ? RT_BULLET : RT_NUMBER, '');
      const prev = last();
      if (prev && prev.kind === list) {
        prev.items.push(item);
      } else {
        blocks.push({ kind: list, items: [item] });
      }
      continue;
    }

    const prev = last();
    if (prev && prev.kind === 'p') {
      prev.lines.push(t);
    } else {
      blocks.push({ kind: 'p', lines: [t] });
    }
  }

  return (
    <div className="rt" style={{ whiteSpace: 'normal', lineHeight: 1.8 }}>
      {blocks.map((b, bi) => {
        switch (b.kind) {
          case 'hr':
            return <hr key={bi} style={{ border: 0, borderTop: '1px solid var(--line, #e2e8f0)', margin: '10px 0' }} />;
          case 'h':
            return <div key={bi} style={{ fontWeight: 800, margin: '10px 0 4px' }}>{inlineMarks(b.text)}</div>;
          case 'ul':
          case 'ol': {
            const List = b.kind;

            return (
              <List key={bi} style={{ margin: '4px 0 8px', paddingInlineStart: 22 }}>
                {b.items.map((it, ii) => <li key={ii} style={{ margin: '2px 0' }}>{inlineMarks(it)}</li>)}
              </List>
            );
          }
          default:
            return b.lines.length === 0 ? null : (
              <p key={bi} style={{ margin: '0 0 8px' }}>
                {b.lines.map((l, li) => (
                  <React.Fragment key={li}>
                    {li > 0 && <br />}
                    {inlineMarks(l)}
                  </React.Fragment>
                ))}
              </p>
            );
        }
      })}
    </div>
  );
};

/** ملخّص الجلسة كما يصل الموكّل: بتنسيقه إن حُرّر منسّقاً، وإلّا نصّه فقراتٍ وقوائم (`RichText`). */
export const ConsultSummaryText: React.FC<{ consult: { summary?: string | null; summaryHtml?: string | null }; fallback?: string }> = ({ consult, fallback }) =>
  consult.summaryHtml ? <RichHtmlView html={consult.summaryHtml} /> : <RichText text={consult.summary} fallback={fallback} />;

export const SummaryStateBadge: React.FC<{ consult: ConsultCard }> = ({ consult }) => {
  const state = summaryState(consult);

  if (state === 'none') {
return null;
}

  const source = summarySourceLabel(consult);

  return (
    <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
      <Badge
        tone={state === 'approved' ? 'b-green' : 'b-amber'}
        text={state === 'approved' ? 'معتمَد — وصل الموكّل' : 'غير معتمَد — محجوب عن الموكّل'}
      />
      {source && <Badge tone="b-grey" text={source} />}
    </span>
  );
};

// نافذة ملخص ومحضر الاستشارة (المكون الموحد لملخص الاستشارة للعميل والمكتب)
export type SummaryModalConsult =
  | (Partial<ConsultCard> & Partial<ClientConsultCard> & { ref: string; summary: string | null })
  | null;

export const SummaryModal: React.FC<{
  consult: SummaryModalConsult;
  onClose: () => void;
}> = ({ consult, onClose }) => {
  const toast = useToast();
  const [copied, setCopied] = useState(false);

  if (!consult) {
    return null;
  }

  const handleCopy = () => {
    if (!consult.summary) {
      return;
    }
    navigator.clipboard.writeText(consult.summary).then(() => {
      setCopied(true);
      toast('تم نسخ خلاصة الاستشارة إلى الحافظة بنجاح');
      setTimeout(() => setCopied(false), 2200);
    }).catch(() => {
      toast('تعذّر نسخ النص');
    });
  };

  const sourceLabel = (consult.summaryEdited || consult.summaryAiSource)
    ? summarySourceLabel({ summaryEdited: consult.summaryEdited, summaryAiSource: consult.summaryAiSource })
    : null;

  const isApproved = Boolean(consult.summaryApproved);
  const isLawyerApproved = Boolean(consult.summaryLawyerApproved);
  const isPending = Boolean(consult.summaryPending) || (!isApproved && !isLawyerApproved);

  const headerBadge = (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
      {isApproved ? (
        <Badge tone="b-green" text="معتمد رسمياً" />
      ) : isLawyerApproved ? (
        <Badge tone="b-blue" text="بانتظار اعتماد الإدارة" />
      ) : isPending ? (
        <Badge tone="b-amber" text="بانتظار اعتماد المستشار" />
      ) : (
        <Badge tone="b-grey" text="مسودة قيد الإعداد" />
      )}
      {sourceLabel && <Badge tone="b-grey" text={sourceLabel} />}
    </div>
  );

  const decisionsList = (consult.decisions && consult.decisions.length > 0)
    ? consult.decisions
    : (consult.zoomAiNextSteps && consult.zoomAiNextSteps.length > 0)
      ? consult.zoomAiNextSteps
      : [];

  const channelIcon = consult.channel === 'مرئية'
    ? 'video'
    : consult.channel === 'هاتفية'
      ? 'phone'
      : 'office';

  return (
    <Modal
      title={`محضر وخلاصة الاستشارة — ${consult.ref}`}
      subtitle={`المستشار: ${consult.lawyer || 'مستشار المكتب المختص'} · الموعد: ${consult.when || '—'}`}
      badge={headerBadge}
      open={Boolean(consult)}
      onClose={onClose}
      maxWidth={720}
    >
      <div className="csd-container">
        {/* شريط معلومات الجلسة التنفيذي */}
        <div className="csd-meta-grid">
          <div className="csd-meta-item">
            <span className="csd-meta-label">
              <Icon name="scale" /> الموضوع والتخصص
            </span>
            <span className="csd-meta-val" title={consult.subject || 'استشارة قانونية'}>
              {consult.subject || 'استشارة قانونية'}
              {consult.specialty && <span className="csd-chip">{consult.specialty}</span>}
            </span>
          </div>

          <div className="csd-meta-item">
            <span className="csd-meta-label">
              <Icon name="user" /> المستشار المسؤول
            </span>
            <span className="csd-meta-val" title={consult.lawyer || 'المستشار القانوني'}>
              {consult.lawyer || 'المستشار القانوني'}
            </span>
          </div>

          <div className="csd-meta-item">
            <span className="csd-meta-label">
              <Icon name={channelIcon} /> القناة والمدة
            </span>
            <span className="csd-meta-val">
              {consult.channel || 'جلسة استشارة'}
              {consult.duration ? ` · ${consult.duration}` : ''}
            </span>
          </div>

          <div className="csd-meta-item">
            <span className="csd-meta-label">
              <Icon name="clock" /> تاريخ الانعقاد
            </span>
            <span className="csd-meta-val" title={consult.when || '—'}>
              {consult.when || '—'}
            </span>
          </div>
        </div>

        {/* شريط الروابط والسجلات المرتبطة إن وجدت */}
        {(consult.caseNo || consult.ticketNo) && (
          <div className="csd-linked-strip">
            {consult.caseNo && (
              <Link href={`/cases/${consult.caseNo}`} className="csd-linked-pill" title="الانتقال إلى ملف القضية المرتبطة">
                <Icon name="scale" /> قضية مرتبطة: <b>#{consult.caseNo}</b>
              </Link>
            )}
            {consult.ticketNo && (
              <span className="csd-linked-pill static" title="رقم التذكرة الأساسية">
                <Icon name="ticket" /> تذكرة أساسية: <b>#{consult.ticketNo}</b>
              </span>
            )}
          </div>
        )}

        {/* بطاقة محضر الرأي القانوني الرسمية */}
        <div className="csd-paper-card">
          <div className="csd-paper-header">
            <div className="csd-paper-title">
              <Icon name="doc" />
              <span>خلاصة الرأي القانوني والمداولة</span>
            </div>
            {consult.summary && (
              <button
                type="button"
                className="btn soft sm"
                onClick={handleCopy}
                style={{ fontSize: 12, padding: '3px 10px', height: 28 }}
                title="نسخ نص المحضر إلى الحافظة"
              >
                <Icon name={copied ? 'check' : 'reply'} />
                <span>{copied ? 'تم النسخ' : 'نسخ النص'}</span>
              </button>
            )}
          </div>

          {consult.summary && consult.summary.trim() !== '' ? (
            <div className="csd-paper-body">
              <ConsultSummaryText consult={consult} />
            </div>
          ) : (
            <div className="csd-paper-empty">
              <div className="csd-empty-icon">
                <Icon name="clock" />
              </div>
              <h4>محضر الجلسة قيد المراجعة والاعتماد</h4>
              <p>
                {consult.summaryPending
                  ? 'انتهت الجلسة بنجاح، ويراجع المستشار القانوني صياغة المحضر والقرارات الآن. ستصلك إشعار فور الاعتماد النهائي.'
                  : 'انتهت الجلسة — يُعدّ المحضر والملخص حالياً وسيصلك إشعار فور اكتماله.'}
              </p>
            </div>
          )}

          {/* ختم الاعتماد الموثق في أسفل المحضر */}
          {isApproved && (
            <div className="csd-seal-ribbon">
              <div className="csd-seal-badge">
                <Icon name="check" />
                <span>وثيقة استشارة معتمدة رسمياً — صادرة وموثقة بمحاضر مكتب المحاماة</span>
              </div>
              {consult.summaryApprovedAt && (
                <span className="csd-seal-date">
                  معتمد بتاريخ: {consult.summaryApprovedAt.slice(0, 10)}
                </span>
              )}
            </div>
          )}
        </div>

        {/* التوصيات والقرارات الإجرائية المعتمدة إن وجدت */}
        {decisionsList.length > 0 && (
          <div className="csd-decisions-card">
            <div className="csd-decisions-title">
              <Icon name="check" />
              <span>القرارات والتوصيات الإجرائية المعتمدة:</span>
            </div>
            <ul className="csd-decisions-list">
              {decisionsList.map((d, idx) => (
                <li key={idx} className="csd-decision-item">
                  <Icon name="check" />
                  <span>{decisionText(d)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* أزرار الإجراءات والتصدير */}
        <div className="csd-footer-actions">
          <div className="csd-footer-primary">
            {consult.id && (
              <a
                className="btn soft sm"
                href={`/consults/${consult.id}/report.pdf`}
                download
                target="_blank"
                rel="noopener"
                title="تحميل التقرير الرسمي للاستشارة بصيغة PDF"
              >
                <Icon name="download" /> تحميل التقرير الرسمي (PDF)
              </a>
            )}
            {consult.summary && (
              <button
                type="button"
                className="btn soft sm"
                onClick={handleCopy}
                title="نسخ نص المحضر إلى الحافظة"
              >
                <Icon name={copied ? 'check' : 'reply'} />
                <span>{copied ? 'تم نسخ النص' : 'نسخ النص'}</span>
              </button>
            )}
          </div>
          <button className="btn sm" type="button" onClick={onClose}>
            إغلاق النافذة
          </button>
        </div>
      </div>
    </Modal>
  );
};

// ============================================================
// استقبال الاستشارات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق consultRecvView + crStart/crEnd/crSetFilter
// ============================================================

export const ConsultRecvPage: React.FC<{ consults: ConsultCard[]; base: string }> = ({ consults, base }) => {
  const rescheduleFlow = useConsultReschedule(base);
  const staffStart = useStaffStartText();
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [summaryOf, setSummaryOf] = useState<ConsultCard | null>(null);
  const [items, setItems] = useState<ConsultCard[]>(consults);

  // تزامن لحظي: الويبهوك/زميل آخر قد يبدّل الجلسة — كانت الشاشة ساكنة فيضغط الموظف «بدء» على جلسة تعمل فعلاً
  useEffect(() => {
    setItems(consults);
    consults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: Partial<ConsultCard>) => {
        /*
         * **الحمولة كاملةً — لا ثلاثة مفاتيح.**
         *
         * كان يُلتقط `session` و`status` و`canJoin` وحدها، ويُهمَل `missed`
         * و`startable` — وهما **أُضيفا إلى البثّ خصّيصاً لهذا** (انظر تعليق
         * `ConsultStatusBroadcast`). فالخادم أُصلح ولم يُوصَل به المستهلك:
         *
         * - بعد «لم يحضر» من شاشةٍ أخرى تبقى البطاقة في فرع «فائتة» بزرّيها، فيُضغط
         *   «لم يحضر» ثانيةً ويردّ الخادم ٤٢٢.
         * - وبعد إعادة الجدولة تبقى «فائتة» بموعدها القديم معروضاً.
         *
         * والنمط الصحيح في `lib/consult-live`: تُنسخ الحمولة كلّها إلّا `status` (تسمية
         * العميل) و`summary` — فتلتقط أيّ مفتاحٍ يُضاف مستقبلاً.
         */
        // القاعدة المشتركة (`lib/consult-live`): لا تسمية العميل ولا ملخّصه فوق بطاقة الطاقم
        const rest = staffPatch(e);
        setItems((prev) => prev.map((x) => (x.id === c.id ? { ...x, ...rest } : x)));

        if (stageChanged(e, c)) {
          router.reload({ only: ['consults'] });
        }
      });
    });

    return () => {
 consults.forEach((c) => echo.leave(`consult.${c.id}`)); 
};
     
  }, [consults]);

  const counts: Record<string, number> = { 'مرئية': 0, 'حضورية': 0, 'هاتفية': 0 };
  items.forEach((c) => {
 if (counts[c.channel] != null) {
counts[c.channel]++;
} 
});
  const ended = items.filter((c) => c.session === 'منتهية').length;
  const missedCount = items.filter((c) => c.missed).length;

  const stats: StatItem[] = [
    ['t-blue', 'video', counts['مرئية'], 'مرئية (فيديو)'],
    ['t-green', 'office', counts['حضورية'], 'حضورية'],
    ['t-amber', 'phone', counts['هاتفية'], 'هاتفية'],
    ['t-cyan', 'check', ended, 'منتهية'],
    ['t-red', 'clock', missedCount, 'فائتة'],
  ];

  // قفلٌ موحّد لأفعال الجلسة: بدء · إنهاء · لم يحضر (`useServerAction`)
  const action = useServerAction();

  // يطابق crStart — بدء الجلسة (يبثّ للعميل لحظياً)
  const start = (c: ConsultCard) => {
    const msg = c.channel === 'مرئية' ? 'تم بدء الجلسة المرئية مع العميل'
      : c.channel === 'هاتفية' ? 'تم بدء المكالمة الهاتفية مع العميل'
      : 'تم تسجيل وصول العميل وبدء الجلسة الحضورية';
    // الرفض (خارج نافذة البدء) يُعرض بسببه — كان هذا المسار بلا `onError` فيفشل بصمت
    void action.run(`${base}/consults/${c.id}/start`, {
      key: c.id, confirm: CONFIRM_START_CONSULT, success: msg, fallback: 'تعذّر بدء الجلسة',
    });
  };

  // يطابق crEnd — إنهاء الجلسة مع تدوين ما دار فيها.
  //
  // كان يُرسل حمولةً فارغة `{}` ويُقال «ولّد الفريق القانوني ملخص الاستشارة»
  // ولم يُرسل حرفاً — وحقلُ الملاحظات موجود في الغرفة المرئية وحدها، فالحضورية
  // والهاتفية تنتهيان دائماً بلا مادّة. وبعد حارس «بلا مادّة ⇒ لا نداء» صارت
  // النافذة شرطاً لا تحسيناً: بلا تدوين لا ملخّص أصلاً.
  const [endingOf, setEndingOf] = useState<ConsultCard | null>(null);
  const [endNotes, setEndNotes] = useState('');

  const end = async () => {
    if (endingOf === null) {
      return;
    }

    const notes = endNotes.trim();

    // تأكيدٌ يقول الأثر قبل الإرسال (قرار المالك 2026-09-26) — الختم لا يُتراجع عنه
    await action.run(`${base}/consults/${endingOf.id}/end`, {
      data: { notes },
      key: endingOf.id,
      confirm: CONFIRM_END_CONSULT,
      success: notes === ''
        ? 'خُتمت الجلسة بلا تدوين — لا ملخّص حتّى تُدوّن ما دار فيها'
        : 'خُتمت الجلسة وحُفظ تدوينك — يُعدّ الملخّص لاعتمادك',
      fallback: 'تعذّر إنهاء الجلسة',
      onSuccess: () => {
        setEndingOf(null);
        setEndNotes('');
      },
    });
  };

  // دخول غرفة الجلسة المضمّنة كمضيف — وبدء الجلسة إن لم تكن قد بدأت (يبثّ «جارية الآن» للعميل).
  // كان router.visit يُجهض طلب البدء (سباق Inertia) فيدخل الموظف والجلسة لم تبدأ رسمياً
  const enterRoom = (c: ConsultCard) => {
    const room = `${base}/videoroom?ref=${encodeURIComponent(c.ref)}`;

    if (c.session === 'بانتظار الجلسة') {
      // سبب الرفض من الخادم: «فات الموعد» و«خارج نافذة البدء» فعلان مختلفان.
      void action.run(`${base}/consults/${c.id}/start`, {
        key: c.id, confirm: CONFIRM_START_CONSULT, fallback: 'تعذّر بدء الجلسة',
        onSuccess: () => openRoomTab(room),
      });

      return;
    }

    openRoomTab(room);
  };

  // وسم «لم يحضر» لاستشارة فائتة — كانت الحيلة الوحيدة (بدء+إنهاء فوري) تزوّر السجل جلسةً منعقدة
  const markNoShow = (c: ConsultCard) =>
    action.run(`${base}/consults/${c.id}/no-show`, {
      key: c.id, confirm: CONFIRM_NO_SHOW, success: 'وُسمت الاستشارة «لم يحضر» وأُشعر العميل', fallback: 'تعذّر الوسم',
    });

  // كانت تُرسل الطلب **بلا تأكيد**: ضغطةٌ واحدة تُلغي الموعد واجتماع Zoom. صارت من مسارها الواحد.
  const reschedule = (c: ConsultCard) => rescheduleFlow.open(c);

  const copyLink = (c: ConsultCard) => {
    if (navigator.clipboard) {
void navigator.clipboard.writeText(c.slink);
}

    toast('تم نسخ رابط الاجتماع');
  };

  const tabs: [string, string][] = [...CONSULT_CHANNELS, ['_missed', `فائتة (${missedCount})`]];
  const list = items.filter((c) => (filter === '_missed' ? c.missed : filter === 'all' || c.channel === filter));

  return (
    <>
      {rescheduleFlow.dialog}
      <div className="greet">
        <h2>استقبال الاستشارات</h2>
        <p>تكملة رحلة الاستشارة: استقبال الجلسات حسب القناة — مرئية (فيديو) / حضورية / هاتفية — حتى كتابة الملخص.</p>
      </div>

      <StatRow items={stats} />

      <div className="mtabs">
        {tabs.map((t) => (
          <button
            key={t[0]}
            className={`mtab${filter === t[0] ? ' on' : ''}`}
            onClick={() => setFilter(t[0])}
            type="button"
          >
            {t[1]}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-h">
          <h3>جلسات الاستشارات</h3>
          <span className="sub">{list.length} استشارة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((c) => {
            const extra = c.channel === 'حضورية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="pin" /> {c.place}
              </span>
            ) : c.channel === 'هاتفية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="phone" /> {c.phone || '—'}
              </span>
            ) : null;

            return (
              <div key={c.ref} className="item">
                <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
                <div className="imeta">
                  <b>{c.ref} — {maskClient(c.client)}</b>
                  <span style={{ display: 'block', margin: '2px 0' }}>
                    {c.subject} · {c.lawyer} · {c.when}
                  </span>
                  {extra}
                  {/* طلب العميل تغيير موعده — يراه الموظّف هنا بفعلَيه (إعادة الجدولة · الرفض) */}
                  <RescheduleRequestNotice consult={c} base={base} onReschedule={() => reschedule(c)} />
                </div>
                <div className="iact">
                  <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                  {c.missed ? (
                    /* فات موعدها بلا جلسة — كان زر «بدء» يبقى ظاهراً للأبد بلا أي وسم */
                    <>
                      <Badge text="فائتة — لم تنعقد" tone="b-red" />
                      {c.canMarkNoShow && (
                        <button className="btn soft sm" onClick={() => markNoShow(c)} disabled={action.busyKey === c.id} type="button">
                          <Icon name="clock" /> لم يحضر
                        </button>
                      )}
                      {c.canReschedule && (
                        <button className="btn sm" onClick={() => reschedule(c)} type="button">
                          <Icon name="cal" /> إعادة جدولة
                        </button>
                      )}
                    </>
                  ) : c.notHeld ? (
                    <>
                      <Badge text="لم يحضر" tone="b-red" />
                      {c.canReschedule && (
                        <button className="btn soft sm" onClick={() => reschedule(c)} type="button">
                          <Icon name="cal" /> إعادة جدولة
                        </button>
                      )}
                    </>
                  ) : c.session === 'بانتظار الجلسة' ? (
                    c.startable === false ? (
                      /* موعد مستقبلي خارج نافذة البدء — الخادم يسمح بإعادة جدولته والزرّ كان محصوراً بالفائتة */
                      <>
                        <Badge text={`مجدولة — البدء قبل الموعد بـ${staffStart}`} tone="b-grey" />
                        {c.canReschedule && (
                          <button className="btn soft sm" onClick={() => reschedule(c)} type="button">
                            <Icon name="cal" /> إعادة جدولة
                          </button>
                        )}
                      </>
                    ) : c.channel === 'مرئية' ? (
                      <>
                        <button className="btn sm" onClick={() => enterRoom(c)} disabled={action.busyKey === c.id} type="button">
                          <Icon name="video" /> بدء ودخول جلسة Zoom
                        </button>
                        <button className="btn soft sm" onClick={() => copyLink(c)} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      </>
                    ) : c.channel === 'هاتفية' ? (
                      <button className="btn sm" onClick={() => start(c)} disabled={action.busyKey === c.id} type="button">
                        <Icon name="phone" /> بدء المكالمة
                      </button>
                    ) : (
                      <button className="btn sm" onClick={() => start(c)} disabled={action.busyKey === c.id} type="button">
                        <Icon name="check" /> تسجيل وصول العميل
                      </button>
                    )
                  ) : c.session === 'جلسة جارية' ? (
                    <>
                      <Badge text="جلسة جارية" tone="b-amber" />
                      {c.channel === 'مرئية' && (
                        <button className="btn soft sm" onClick={() => openRoomTab(`${base}/videoroom?ref=${encodeURIComponent(c.ref)}`)} type="button">
                          <Icon name="video" /> دخول جلسة Zoom
                        </button>
                      )}
                      <button className="btn sm" onClick={() => { setEndingOf(c); setEndNotes(''); }} type="button">
                        <Icon name="doc" /> إنهاء وكتابة الملخص
                      </button>
                    </>
                  ) : c.session === 'منتهية' ? (
                    <>
                      <Badge text="منتهية" tone="b-green" />
                      <button className="btn soft sm" onClick={() => setSummaryOf(c)} type="button">
                        <Icon name="out" /> الملخص
                      </button>
                    </>
                  ) : (
                    /*
                     * **ولا تُوسَم جلسةٌ لم تُعقد بوسمِ النجاح.**
                     *
                     * كان هذا فرعاً جامعاً: أيُّ `session` خارج الاثنتين أعلاه تُعرض
                     * **«منتهية» خضراء** — و`AutoCloseMissedConsults` يكتب «لم تُعقد».
                     * فجلسةٌ فاتت العميلَ تُعرض جلسةً تمّت بنجاح، وزرُّ «الملخص» يفتح
                     * فراغاً. صار الفرع صريحاً يعرض ما هو كائن بنغمته.
                     */
                    <>
                      <Badge text={c.session || 'بانتظار الجلسة'} tone={c.sessionTone} />
                      {c.notHeld && (
                        <span style={{ fontSize: 11, color: 'var(--muted)', alignSelf: 'center' }}>
                          لم يُسجَّل حضور — تُعاد جدولتها من صفحة الاستشارة
                        </span>
                      )}
                    </>
                  )}
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="video" /><b>لا استشارات في هذه القناة</b></div>
          )}
        </div>
      </div>

      {/* نافذة التدوين — مادّة الملخّص الوحيدة للقنوات غير المرئية */}
      <Modal
        title={`إنهاء الجلسة وتدوين ما دار — ${endingOf?.ref ?? ''}`}
        open={!!endingOf}
        onClose={() => setEndingOf(null)}
      >
        <div className="field">
          <label>ما دار في الجلسة (وقائع العميل، ما طُلب، ما تقرّر)</label>
          <textarea
            className="input"
            rows={9}
            value={endNotes}
            onChange={(ev) => setEndNotes(ev.target.value)}
            placeholder="مثال: العميل مقاول من الباطن، لم يُصرَف له مستخلصان منذ أربعة أشهر، ويريد وقف العمل والمطالبة…"
          />
        </div>
        <p className="action-hint">
          <Icon name="info" /> الملخّص يُبنى على تدوينك وحده — وبلا تدوين لا يُكتب شيء، لأنّ ما يُكتب من عنوان الموضوع وحده محضرٌ مختلَق.
        </p>
        <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
          <button className="btn" onClick={end} disabled={action.busy || endNotes.trim() === ''} type="button">
            <Icon name="doc" /> إنهاء وحفظ التدوين
          </button>
          <button className="btn soft" onClick={end} disabled={action.busy} type="button">
            <Icon name="check" /> إنهاء بلا تدوين
          </button>
        </div>
      </Modal>

      <SummaryModal consult={summaryOf} onClose={() => setSummaryOf(null)} />
    </>
  );
};

// غرفة الجلسة للمكتب: انتقلت إلى الغرفة الواحدة `RoomPage` (`lib/zoom-room.tsx`) — لكلّ الأدوار
// والنوعين، تفاصيلها وشرطُ إنهائها من عقد الخادم `room` (قرار المالك 2026-09-26).

// ============================================================
// إدارة الاستشارات — قائمة الرحلة (يطابق emConsultsView + cKPIs)
// ============================================================

/*
 * **حُذف `ConsultsListPage`.**
 *
 * مكوّنٌ مُصدَّرٌ بستّين سطراً **لا يستورده ملفٌّ واحد** في المشروع، وفيه كذبتان
 * ورثهما من نسخةٍ قديمة: مؤشّر «متوسط الزمن» مبنيٌّ على `mins` (عمودٌ بلا كاتب،
 * فصفرٌ أبداً)، وعدّاد «منجزة» يحسب `'محولة إلى قضية'` — وهي حالةٌ **لا يكتبها أيّ
 * مسار**، أُخرجت من الكتالوج ولم تُخرَج من هنا.
 *
 * وهو خطرُ الشيفرة الميتة: لا أحد يراها فتُصحَّح، ثمّ يستوردها أحدٌ يوماً فيرث كذبتين.
 */

// ============================================================
// رحلة الاستشارة (يطابق consultView + cRequestDocs/cRunAI/cSaveAI/cApproveAI/cRerun/cRefer)
// ============================================================

export interface LawyerOpt { id: number; name: string; dept: string; }

export const ConsultJourneyPage: React.FC<{ consult: ConsultCard; base: string; isAdmin?: boolean; lawyers: LawyerOpt[]; canApproveSummary: boolean }> = ({ consult: c, base, isAdmin, lawyers, canApproveSummary }) => {
  const inSession = useInSession();
  const askFor = usePrompt();
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  /**
   * أيُّ فعلٍ يجري الآن — `busy` مشتركٌ بين الأفعال كلّها، فكان زرُّ المعالجة يقول
   * «جارٍ التحليل…» أثناء طلب المستندات أو تغيير الأولويّة (قيسَ في المتصفّح 2026-09-10).
   */
  const [running, setRunning] = useState<string | null>(null);

  // مطابقة اقتراح الذكاء الاصطناعي (اسم) بمحامٍ حقيقي — وإلّا فلا اختيار.
  // كان يقع على أوّل محامٍ في القائمة حين لا اقتراح، فيبدو للموظّف ترشيحاً وهو ترتيب أبجديّ.
  const matchLawyer = (name?: string): number | '' => lawyers.find((l) => l.name === name)?.id ?? '';

  // الحقول القابلة للتعديل لتحليل الفريق القانوني (تُزامَن مع الخادم بعد كل إجراء)
  const [aiClass, setAiClass] = useState(c.aiClass);
  const [aiSummary, setAiSummary] = useState(c.aiSummary);
  const [lawyerId, setLawyerId] = useState<number | ''>(matchLawyer(c.aiLawyer));
  const [priority, setPriority] = useState(c.priority);
  useEffect(() => {
    setAiClass(c.aiClass);
    setAiSummary(c.aiSummary);
    setLawyerId(matchLawyer(c.aiLawyer));
    setPriority(c.priority);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c.aiClass, c.aiSummary, c.aiLawyer, c.priority, lawyers]);

  const lawyerName = lawyers.find((l) => l.id === lawyerId)?.name ?? '';

  /**
   * **الرفضُ يُسمَع.** كان هذا المُساعِد بلا `onError`، وتمرّ به أحدَ عشرَ فعلاً —
   * فكلُّ حارسٍ خادميّ (تسعُ رسائلَ عربيّةٍ مكتوبةٍ بعناية) يسقط **صامتاً تماماً**:
   * الزرّ يومض ثمّ يعود، ولا توست ولا تغيّر. قيسَ في المتصفّح 2026-09-08 على
   * «تحديث بيانات الجلسة من Zoom»: ٤٢٢ ولا أثرَ على الشاشة.
   * والملفُّ نفسه يعالج `onError` في خمسة مواضع أخرى — أُصلحت الشاشة المجاورة وتُرك هذا.
   */
  const post = (action: string, data: Record<string, string>, msg: string) => {
    setBusy(true);
    setRunning(action);
    router.post(`${base}/consults/${c.id}/${action}`, data, {
      preserveScroll: true,
      onSuccess: () => toast(msg),
      onError: (e) => toast(firstError(e, 'تعذّر تنفيذ الإجراء')),
      onFinish: () => {
        setBusy(false);
        setRunning(null);
      },
    });
  };

  // كانت ترسل حمولةً فارغة، فيصل العميلَ «مستند إضافي مطلوب» بلا بيان — طلبٌ
  // يعلق به ملفّه بانتظار شيءٍ مجهول. والخادم صار يشترط النصّ.
  const requestDocs = async () => {
    const what = (
      await askFor({
        title: 'طلب استكمال مستند',
        message: 'يصل العميلَ إشعارٌ بنصّ الطلب كما تكتبه هنا — فاذكر المستند باسمه.',
        label: 'ما المستند المطلوب من العميل؟',
        placeholder: 'صورة السجلّ التجاريّ ساريةَ المفعول',
        confirmLabel: 'إرسال الطلب',
      })
    )?.trim();

    if (!what) {
      return;
    }

    post('reqdocs', { docs: what }, 'تم طلب استكمال البيانات وإشعار العميل');
  };
  // «اكتمل» تدّعي نجاحاً لا يضمنه الردّ: `Staff\ConsultController::analyze`
  // يعيد `back()` في الحالين، ويكتب في سجلّ التدقيق «تعذّر — يلزم إعداد يدويّ»
  // عند الاحتياطيّ. والعنوان في البطاقة أُصلح ليتبع `aiSource` ولم يُصلح التوست.
  const runAI = () => post('analyze', {}, 'انتهت المعالجة — راجع نتيجتها في البطاقة أدناه');
  const saveAI = () => post('analysis', { aiClass, aiSummary, aiLawyer: lawyerName }, 'تم حفظ التعديلات في سجل التدقيق');
  const approveAI = () => post('approve', {}, 'تم اعتماد التحليل — الاستشارة جاهزة للمحامي');
  const rerun = () => post('analyze', {}, 'أُعيدت المعالجة — راجع نتيجتها في البطاقة أدناه');
  const refer = () => post('refer', { lawyer_id: String(lawyerId) }, `تمت إحالة الاستشارة إلى المحامي: ${lawyerName}`);

  // تحويل قرارات الاستشارة إلى مهام حقيقية (تُستخرج عند إنهاء الجلسة) — لمرة واحدة
  const [tasksDone, setTasksDone] = useState(c.tasksCreated);
  const makeTasks = () => {
    if (tasksDone || c.decisions.length === 0) {
      return;
    }

    setBusy(true);
    router.post(`${base}/consults/${c.id}/tasks`, {}, {
      preserveScroll: true,
      onSuccess: () => {
 setTasksDone(true); toast(`تم تحويل ${c.decisions.length} قرار إلى مهام`); 
},
      onError: (e) => toast(firstError(e, 'تعذّر إنشاء المهامّ')),
      onFinish: () => setBusy(false),
    });
  };
  const savePriority = () => post('priority', { priority }, 'تم تحديث الأولوية');

  // محرّر ملخّص الجلسة — النصّ الذي سيصل العميل. كان «تعديل واعتماد» خياراً في
  // صندوق المراجعة **بلا حقلٍ يستقبله**، فيُسجّل «عدّل» والمنشور نصّ النموذج حرفياً.
  // النسخة المنسّقة تُحرَّر وتُرسل (`HasRichText::editableInput` يشتقّ منها النصّ)
  const [sessionSummary, setSessionSummary] = useState(c.summaryHtml ?? '');

  // نصّ الموكّل يحرّره ويعتمده **من يملك الصلاحيّة** — لا من يفتح الصفحة.
  const mayEditSummary = useCan()('اعتماد/تعديل ملخص الاستشارة');
  // اعتمده المحامي ورُفع للإدارة — يُقفل عليه والإدارة تعدّله (`Staff/ConsultController::saveSummary`).
  // كان المحرّر وزرّ الاعتماد يبقيان له فيردّهما الخادم ٤٢٢ (تدقيق P4، 2026-09-30)
  const lockedForMe = !isAdmin && Boolean(c.summaryLawyerApproved);
  const canEditHere = mayEditSummary && !lockedForMe;

  /*
   * **زرُّ التحليل يُخفى لمن لا يملك إطلاقه — لا يُترك ليفشل.**
   *
   * صار مسارا `analyze` للموظّف والمحامي محروسَين بـ«تشغيل تلخيص الفريق القانوني»
   * بعد أن كانا يمرّان بـ«استقبال الاستشارات» وحدها. و`EnsurePermission` **لا يردّ
   * ٤٠٣ لطلبات Inertia** بل يعيد التوجيه إلى لوحة المستخدم مع رسالة خطأ — فتركُ
   * الزرّ ظاهراً يعني أنّ الموظّف يضغطه فيجد نفسه مقذوفاً خارج صفحة الاستشارة.
   *
   * **ولا يُخفى عرضُ النتيجة**: الموظّف يظلّ يقرأ التحليل ويحرّره ويعتمده — إنّما
   * لا يُطلقه. ومن يُطلقه هو المحامي المسنَد (الأزرار مشروطةٌ بالحالة لا بالدور)،
   * أو الإدارة (`isSuper` يتجاوز في `useCan` كما في `Gate::before`).
   */
  const mayAnalyze = useCan()('تشغيل تلخيص الفريق القانوني');

  useEffect(() => {
    setSessionSummary(c.summaryHtml ?? '');
  }, [c.summaryHtml]);

  const saveSessionSummary = () => post('summary', { summary_html: sessionSummary }, 'حُفظ الملخّص المحرّر — يصل العميل بعد اعتماده');
  // المستشار يعتمد ويرفع للإدارة؛ والإدارة تعتمد فيُنشر للعميل وتكتمل التذكرة (قرار المالك 2026-09-14)
  const approveSessionSummary = () => post('summary/approve', {}, 'اعتُمد الملخّص');

  const showEmpActions = c.status === 'جديدة'
    || c.status === 'بانتظار استكمال البيانات';
  const showRefer = c.status === 'جاهزة للمحامي';
  /**
   * سببُ تعذّر الإحالة — يطابق حرّاس `Staff\ConsultController::refer` الثلاثة.
   * كان الزرّان معروضَين بلا شرط حالة، فيُعرضان على استشارةٍ منتهيةٍ يردّها الخادم ٤٢٢.
   */
  const referBlocked = c.session === 'جلسة جارية'
    ? 'الجلسة منعقدة الآن — أنهِها قبل تغيير المستشار.'
    : c.isClosed
      ? 'الاستشارة انتهت أو أُلغيت — لا تُحال إلى محامٍ.'
      : c.bookingStage != null
        ? `ما زالت في دورة الحجز — حالتها «${c.status}». أكمل التسعير والسداد واختيار الموعد أوّلاً.`
        : null;
  /** «إعادة التحليل» يردّها الخادم على المنتهية والملغاة (`analyze`). */
  const analyzeBlocked = c.isClosed;
  // البطاقة سطح تحرير الموظّف — تبقى ظاهرة عند تعذّر التحليل (فهو حينها من يكتب الرأي)،
  // لكن بعنوان صادق: كان الاحتياطيّ يظهر تحت «تحليل الفريق القانوني» كأن تحليلاً وقع.
  const aiFailed = c.aiSource === 'fallback';
  const showAiCard = c.aiDone || aiFailed;
  const showApprove = c.status === 'بانتظار اعتماد الموظف';

  /** حجزٌ من تذكرة؟ — يُميَّز بوجود رقم التذكرة في البطاقة. */
  const isBooking = !!c.ticketNo;

  /** هل للجلسة مخرجاتٌ فعليّة؟ — لا يُعرض قسمٌ فارغ يُوهم بجلسةٍ لم تُسجَّل. */
  const mask = useMasker();
  const attendees = c.zoomParticipantsLog ?? [];
  /** المدّة المقيسة وحدها — `fmtDur` معرّفةٌ في هذا الملفّ. */
  const measured = c.durationSec != null && c.durationSec > 0 ? fmtDur(c.durationSec) : null;
  const media = c.media;
  const hasMedia = !!(media && (media.video || media.audio || media.transcript));
  const hasSessionOutputs = !!(c.duration || hasMedia || attendees.length);
  const [transcriptOpen, setTranscriptOpen] = useState(false);

  const journeySteps = isBooking ? CONSULT_BOOKING_FLOW : CONSULT_FLOW;
  const currentStage = isBooking
    ? cBookingStage(c.status, c.session)
    : (cHasStage(c.status) ? cStage(c.status) : 0);

  return (
    <div className="cj-page-container">
      {/* ── 1. الشريط العلوي للإجراءات السريعة والملاحة ── */}
      <div className="cj-topbar-actions">
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Link href={`${base}/consults`} className="cj-back-btn">
            <Icon name="reply" />
            <span>العودة لقائمة الاستشارات</span>
          </Link>
          <span style={{ color: 'var(--line)' }}>|</span>
          <span style={{ fontSize: 13, color: 'var(--muted)', fontWeight: 600 }}>
            ملف رحلة الاستشارة
          </span>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          {c.id && (
            <a
              href={`/consults/${c.id}/report.pdf`}
              download
              target="_blank"
              rel="noopener"
              className="btn soft sm"
              title="تحميل محضر الاستشارة الرسمي بصيغة PDF"
            >
              <Icon name="download" />
              <span>تقرير الاستشارة (PDF)</span>
            </a>
          )}
          {c.session === 'منتهية' && c.channel === 'مرئية' && !c.summaryApproved && (
            <button
              type="button"
              className="btn soft sm"
              onClick={() => post('zoom-sync', {}, 'اكتمل الاستعلام من Zoom — حُدّثت بيانات الجلسة المتوفرة')}
              disabled={busy}
              title="مزامنة بيانات التسجيل والحضور من Zoom"
            >
              <Icon name="video" />
              <span>{busy && running === 'zoom-sync' ? 'جارٍ المزامنة…' : 'تحديث من Zoom'}</span>
            </button>
          )}
          {c.channel === 'مرئية' && c.session === 'جلسة جارية' && (
            <button
              type="button"
              onClick={() => openRoomTab(`${base}/videoroom?ref=${encodeURIComponent(c.ref)}`)}
              className="btn sm"
              style={{ fontWeight: 800 }}
            >
              <Icon name="video" />
              <span>دخول جلسة Zoom</span>
            </button>
          )}
        </div>
      </div>

      {/* ── 2. بطاقة القيادة والملف التعريفي التنفيذي (Executive Hero Card) ── */}
      <div className="cj-hero-card">
        <div className="cj-hero-header">
          <div style={{ minWidth: 0, flex: '1 1 300px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span className="cj-ref-badge">
                <Icon name="scale" style={{ width: 14, height: 14 }} />
                {c.ref}
              </span>
              <button
                type="button"
                className="btn soft sm"
                style={{ padding: '2px 8px', height: 26, fontSize: 11.5 }}
                onClick={() => {
                  navigator.clipboard.writeText(c.ref);
                  toast('تم نسخ الرقم المرجعي للاستشارة');
                }}
                title="نسخ الرقم المرجعي"
              >
                <Icon name="reply" style={{ width: 12, height: 12 }} />
                <span>نسخ</span>
              </button>
              {c.ticketNo && (
                <span className="csd-chip" title="التذكرة الأساسية">
                  تذكرة: #{c.ticketNo}
                </span>
              )}
              {c.caseNo && (
                <Link href={`/cases/${c.caseNo}`} className="csd-chip" title="القضية المرتبطة">
                  قضية: #{c.caseNo}
                </Link>
              )}
            </div>
            <h1 className="cj-title" title={c.subject}>
              {c.subject || 'جلسة استشارة قانونية متخصصة'}
            </h1>
          </div>

          <div className="cj-badges-cluster">
            <Badge text={c.status} tone={c.tone} />
            {c.session ? <Badge text={c.session} tone={c.sessionTone} /> : null}
            {c.channel ? <Badge text={c.channel} tone={crChannelTone(c.channel)} /> : null}
            <Badge
              text={`أولوية: ${c.priority}`}
              tone={c.priority === 'عالية' ? 'b-red' : c.priority === 'متوسطة' ? 'b-amber' : 'b-grey'}
            />
          </div>
        </div>

        {/* شبكة مصفوفة البيانات الستّة */}
        <div className="cj-matrix-grid">
          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="user" /> الموكّل</span>
            <span className="cj-matrix-val" title={mask(c.client)}>{mask(c.client)}</span>
          </div>

          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="scale" /> المستشار المسؤول</span>
            <span className="cj-matrix-val" title={c.lawyer}>{c.lawyer || 'لم يُعيّن بعد'}</span>
          </div>

          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="folder" /> التخصص</span>
            <span className="cj-matrix-val" title={c.specialtyLabel}>{c.specialtyLabel}</span>
          </div>

          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="clock" /> موعد الجلسة</span>
            <span className="cj-matrix-val" title={c.when || 'لم يحدد'}>{c.when || 'بانتظار التحديد'}</span>
          </div>

          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="cal" /> تاريخ الاستلام</span>
            <span className="cj-matrix-val" title={c.received}>{c.received}</span>
          </div>

          <div className="cj-matrix-item">
            <span className="cj-matrix-label"><Icon name="office" /> الموظف المشرف</span>
            <span className="cj-matrix-val" title={c.employee || '—'}>{c.employee || '—'}</span>
          </div>
        </div>
      </div>

      {/* وقائع العميل من نموذج الحجز — كاملةً كما كتبها */}
      {c.details && (
        <div className="card">
          <div className="card-b" style={{ padding: '14px 20px' }}>
            <b style={{ display: 'block', fontSize: 13, color: 'var(--muted)', marginBottom: 6 }}>وقائع العميل وأسئلته</b>
            <div style={{ fontSize: 13.5, lineHeight: 1.8, whiteSpace: 'pre-wrap' }}>{c.details}</div>
          </div>
        </div>
      )}

      {/* ── 3. تنبيه الموعد المقترح بانتظار الاعتماد ── */}
      {c.proposal && (
        <div className="card" role="status" style={{ borderInlineStart: '4px solid #d97706', background: '#fffbeb' }}>
          <div className="card-b" style={{ padding: '14px 20px', display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
            <Icon name="clock" style={{ color: '#d97706', width: 22, height: 22 }} />
            <div>
              <b style={{ display: 'block', fontSize: 13.5, color: '#92400e' }}>
                موعد مقترح: {proposalWhen(c.proposal)} مع المستشار {c.proposal.lawyer}
              </b>
              <span style={{ color: '#b45309', fontSize: 12.5 }}>
                قناة الجلسة: ({c.proposal.channel}) — تم التنسيق مع العميل وبانتظار اعتماد الإدارة العليا.
              </span>
            </div>
            {isAdmin && (
              <Link href="/admin/approvals" className="btn sm" style={{ marginInlineStart: 'auto', background: '#d97706', color: '#fff' }}>
                <Icon name="check" /> اعتماد الموعد فوراً
              </Link>
            )}
          </div>
        </div>
      )}

      {/* ── 4. مسار مراحل رحلة الاستشارة التفاعلي (Interactive Journey Stepper) ── */}
      <div className="cj-stepper-card">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13.5, fontWeight: 800, color: 'var(--deep)' }}>
            <Icon name="compass" style={{ width: 17, height: 17, color: 'var(--cyan)' }} />
            <span>مسار مراحل الاستشارة المعياري</span>
          </div>
          <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>
            {isBooking ? 'مسار حجز التذكرة' : 'مسار الاستشارة المباشرة'}
          </span>
        </div>

        <div className="cj-stepper-track">
          {journeySteps.map((step, idx) => {
            const isDone = idx < currentStage;
            const isCur = idx === currentStage;

            return (
              <React.Fragment key={step}>
                <div className="cj-step-item">
                  <div className={`cj-step-node ${isDone ? 'done' : isCur ? 'cur' : 'pending'}`}>
                    {isDone ? <Icon name="check" style={{ width: 14, height: 14 }} /> : (idx + 1)}
                  </div>
                  <span className={`cj-step-name ${isCur ? 'cur' : ''}`}>
                    {step}
                  </span>
                </div>
                {idx < journeySteps.length - 1 && (
                  <div className={`cj-step-connector ${isDone ? 'done' : ''}`} />
                )}
              </React.Fragment>
            );
          })}
        </div>
      </div>

      {/* ── 5. شريط الإجراءات والقرارات الفورية التشغيلية ── */}
      {(showEmpActions || (showRefer && !referBlocked)) && (
        <div className="cj-actions-banner">
          <div className="cj-actions-banner-text">
            <Icon name="sparkles" />
            <div>
              <b style={{ display: 'block', fontSize: 13.5, color: 'var(--deep)' }}>
                إجراءات تشغيلية مطلوبة في هذه المرحلة
              </b>
              <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                {showEmpActions ? 'يمكنك طلب استكمال مستندات من العميل أو إطلاق معالجة التحليل القانوني' : 'الاستشارة جاهزة للإحالة والاعتماد للمستشار القانوني'}
              </span>
            </div>
          </div>

          <div className="cj-actions-banner-buttons">
            {showEmpActions && (
              <>
                <button className="btn soft sm" onClick={requestDocs} disabled={busy} type="button">
                  <Icon name="upload" /> طلب استكمال مستندات
                </button>
                {mayAnalyze && (
                  <button className="btn sm" onClick={runAI} disabled={busy} type="button">
                    <Icon name="sparkles" /> {running === 'analyze' ? 'جارٍ التحليل…' : 'بدء معالجة الفريق القانوني'}
                  </button>
                )}
              </>
            )}
            {showRefer && !referBlocked && (
              <button className="btn sm" onClick={refer} disabled={busy || !lawyerId} type="button">
                <Icon name="scale" /> {lawyerName ? `إحالة للمحامي (${lawyerName})` : 'إحالة للمحامي — اختر مستشاراً أولاً'}
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── 6. التخطيط الشبكي المنقسم (2-Column Command Grid) ── */}
      <div className="cj-layout-grid">
        {/* العمود الرئيسي (Main Stage) */}
        <div className="cj-main-column">
          {/* محضر وخلاصة الاستشارة (نسخة العميل) */}
          {(c.session === 'منتهية' || c.summary) && (
            <div className="csd-paper-card">
              <div className="csd-paper-header">
                <div className="csd-paper-title">
                  <Icon name="doc" />
                  <span>محضر وخلاصة الاستشارة الرسمية</span>
                </div>
                <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                  {/* نسخ الملخّص: الآلة وZoom وتعديلات الطاقم (طلب المالك 2026-09-29) */}
                  <RevisionHistoryButton kind="consult_summary" refKey={c.id} />
                  <RevisionHistoryButton kind="consult_notes" refKey={c.id} label="نسخ الملاحظات" />
                  <SummaryStateBadge consult={c} />
                </div>
              </div>

              <div className="csd-paper-body">
                {c.summaryApproved ? (
                  <>
                    <ConsultSummaryText consult={c} />
                    <p className="action-hint" style={{ marginTop: 14 }}>
                      <Icon name="info" /> اعتُمد هذا المحضر رسمياً وقرأه الموكّل — تعديله الآن يتطلب قراراً جديداً يُشعر به العميل.
                    </p>
                  </>
                ) : c.summary ? (
                  <>
                    {canEditHere ? (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                        <div className="field" style={{ margin: 0 }}>
                          <label style={{ fontSize: 12.5, fontWeight: 700 }}>
                            النص الذي سيصل الموكّل بعد الاعتماد:
                          </label>
                          {/* ما يُكتب هنا بتنسيقه هو ما يقرؤه الموكّل — المحرّر نفسه معاينةٌ */}
                          <RichTextEditor
                            value={sessionSummary}
                            onChange={setSessionSummary}
                            placeholder="اكتب خلاصة الرأي القانوني وتوجيهات الجلسة هنا..."
                          />
                        </div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                          <button className="btn soft sm" onClick={saveSessionSummary} disabled={busy || htmlToText(sessionSummary) === ''} type="button">
                            <Icon name="check" /> حفظ الملخص المحرر
                          </button>
                          {/* لا مسار اعتمادٍ للموظّف (قرار المالك 2026-09-14) — كان الزرّ يظهر له فيقع ٤٠٤ */}
                          {canApproveSummary && (
                            <button className="btn sm" onClick={approveSessionSummary} disabled={busy} type="button">
                              {/* اعتماد المحامي يرفعه للإدارة ولا يرسله للموكّل — الإرسال باعتماد الإدارة */}
                              <Icon name="scale" /> {isAdmin ? 'اعتماد وإرسال للعميل' : 'اعتماد ورفع للإدارة'}
                            </button>
                          )}
                        </div>
                      </div>
                    ) : (
                      <ConsultSummaryText consult={c} />
                    )}

                    {c.zoomSummary && (
                      <details style={{ marginTop: 14 }}>
                        <summary style={{ cursor: 'pointer', fontWeight: 700, fontSize: 12.8, color: 'var(--primary)' }}>
                          مادّة مسجلة من جلسة Zoom (للبناء عليها)
                        </summary>
                        <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.85, fontSize: 13, marginTop: 8, padding: 12, border: '1px solid var(--line-soft)', borderRadius: 8, background: '#f8fafc' }}>
                          <RichText text={c.zoomSummary} />
                        </div>
                      </details>
                    )}

                    <p className="action-hint" style={{ marginTop: 12 }}>
                      <Icon name="info" /> {lockedForMe
                        ? 'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ قبل إرساله للموكّل.'
                        : canEditHere
                          ? 'حفظ الملخص لا يُطلقه للعميل — الإطلاق يتم بالاعتماد الرسمي.'
                          : 'هذا النص محجوب عن العميل حتى يعتمده المحامي المختص أو الإدارة.'}
                    </p>
                  </>
                ) : (
                  <div className="csd-paper-empty">
                    <div className="csd-empty-icon"><Icon name="clock" /></div>
                    <h4>محضر الجلسة قيد المراجعة والاعتماد</h4>
                    <p>انتهت الجلسة — يُعدّ المحضر والملخص حالياً وسيصلك إشعار فور اكتماله.</p>
                  </div>
                )}
              </div>

              {c.summaryApproved && (
                <div className="csd-seal-ribbon">
                  <div className="csd-seal-badge">
                    <Icon name="check" />
                    <span>وثيقة استشارة معتمدة رسمياً — صادرة وموثقة بمحاضر مكتب المحاماة</span>
                  </div>
                  {c.summaryApprovedAt && (
                    <span className="csd-seal-date">
                      معتمد بتاريخ: {c.summaryApprovedAt.slice(0, 10)}
                    </span>
                  )}
                </div>
              )}
            </div>
          )}

          {/* القرارات والتوصيات والمهام الإجرائية المستخرجة */}
          {c.decisions && c.decisions.length > 0 && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <Icon name="check" style={{ color: 'var(--success)' }} />
                  <h3 style={{ margin: 0 }}>القرارات والتوصيات المعتمدة ({c.decisions.length})</h3>
                </div>
                <button className="btn soft sm" onClick={makeTasks} disabled={busy || tasksDone} type="button">
                  <Icon name="check" /> {tasksDone ? 'حُوّلت إلى مهام عمل' : 'تحويل القرارات إلى مهام'}
                </button>
              </div>
              <div className="card-b" style={{ padding: '16px 18px' }}>
                <ul className="csd-decisions-list">
                  {c.decisions.map((d, i) => (
                    <li key={i} className="csd-decision-item">
                      <Icon name="check" />
                      <span>{decisionText(d)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}

          {/* مخرجات الجلسة ومكتبة الوسائط والتفريغ النصي */}
          {hasSessionOutputs && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <Icon name="video" style={{ color: 'var(--primary)' }} />
                  <h3 style={{ margin: 0 }}>مخرجات وتسجيلات الجلسة</h3>
                </div>
                {measured && <span className="sub">المدة المقيسة: {measured}</span>}
              </div>
              <div className="card-b" style={{ padding: '16px 18px' }}>
                <div className="cj-outputs">
                  {measured && <div><span>المدة الفعلية</span><b>{measured}</b></div>}
                  {c.when && <div><span>موعد الانعقاد</span><b>{c.when}</b></div>}
                  {attendees.length > 0 && <div><span>عدد الحضور</span><b>{attendees.length} مشارك</b></div>}
                </div>

                {media && (hasMedia || media.locked) && (
                  <div style={{ marginTop: 14 }}>
                    <SessionMediaPanel
                      media={media}
                      urls={consultMediaUrls(base, c.id)}
                      onViewTranscript={() => setTranscriptOpen(true)}
                    />
                    <TranscriptModal
                      title={`النص الحرفي وتفريغ الجلسة — ${c.ref}`}
                      url={consultMediaUrls(base, c.id).transcript}
                      open={transcriptOpen}
                      onClose={() => setTranscriptOpen(false)}
                    />
                  </div>
                )}

                {attendees.length > 0 && (
                  <details style={{ marginTop: 14 }}>
                    <summary style={{ cursor: 'pointer', fontWeight: 700, fontSize: 13, color: 'var(--ink)' }}>
                      سجل حضور المشاركين ({attendees.length})
                    </summary>
                    <div className="t-wrap" style={{ marginTop: 8 }}>
                      <table className="tbl">
                        <thead>
                          <tr>
                            <th>المشارك</th>
                            <th>الدخول</th>
                            <th>الخروج</th>
                            <th>المدة</th>
                          </tr>
                        </thead>
                        <tbody>
                          {attendees.map((a, i) => (
                            <tr key={i}>
                              <td>{a.name || '—'}</td>
                              <td className="mono">{a.join_time || '—'}</td>
                              <td className="mono">{a.leave_time || '—'}</td>
                              <td>{a.duration_sec != null ? `${Math.round(a.duration_sec / 60)} دقيقة` : '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </details>
                )}

                {c.zoomAiNextSteps && c.zoomAiNextSteps.length > 0 && (
                  <details style={{ marginTop: 12 }}>
                    <summary style={{ cursor: 'pointer', fontWeight: 700, fontSize: 13, color: 'var(--cyan)' }}>
                      خطوات اقترحها Zoom AI ({c.zoomAiNextSteps.length})
                    </summary>
                    <ul style={{ margin: '8px 0 0', paddingInlineStart: 20, fontSize: 13, lineHeight: 1.9 }}>
                      {c.zoomAiNextSteps.map((t, i) => <li key={i}>{t}</li>)}
                    </ul>
                  </details>
                )}

                <p className="action-hint" style={{ marginTop: 12 }}>
                  <Icon name="info" /> مخرجات وتفريغ الجلسة تُحدّث تلقائياً أو عبر زر «تحديث من Zoom» في الشريط العلوي.
                </p>
              </div>
            </div>
          )}
        </div>

        {/* العمود الجانبي (Side Column) */}
        <div className="cj-side-column">
          {/* بطاقة تحليل الفريق القانوني والذكاء الاصطناعي */}
          {showAiCard && (
            <div className="cj-ai-card">
              <div className="cj-ai-header">
                <div className="cj-ai-title">
                  <Icon name="sparkles" />
                  <span>{aiFailed ? 'إعداد يدوي مطلوب' : 'تحليل الفريق القانوني'}</span>
                </div>
                {aiFailed && <Badge tone="b-red" text="تعذّر الآلي" />}
              </div>

              <div className="card-b" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                <div className="field" style={{ margin: 0 }}>
                  <label style={{ fontSize: 12, fontWeight: 700 }}>تصنيف الاستشارة</label>
                  <input
                    className="input"
                    value={aiClass}
                    onChange={(e) => setAiClass(e.target.value)}
                    placeholder="مثال: عقود تجارية / نزاع إيجاري"
                  />
                </div>

                <div className="field" style={{ margin: 0 }}>
                  <label style={{ fontSize: 12, fontWeight: 700 }}>الملخص والتكييف القانوني</label>
                  <textarea
                    className="input"
                    rows={4}
                    value={aiSummary}
                    onChange={(e) => setAiSummary(e.target.value)}
                    placeholder="وقائع الاستشارة وتكييفها الأولي..."
                    style={{ lineHeight: 1.7 }}
                  />
                </div>

                <div className="field" style={{ margin: 0 }}>
                  <label style={{ fontSize: 12, fontWeight: 700 }}>المستشار المقترح</label>
                  <select
                    value={lawyerId}
                    onChange={(e) => setLawyerId(Number(e.target.value))}
                    style={{ width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 13 }}
                  >
                    {lawyers.length === 0 && <option value="">— لا يوجد مستشارون —</option>}
                    {lawyers.length > 0 && lawyerId === '' && <option value="">— اختر المحامي المختص —</option>}
                    {lawyers.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.name}{l.dept !== '—' ? ` — ${l.dept}` : ''}{inSessionSuffix(inSession, l.id)}
                      </option>
                    ))}
                  </select>
                </div>

                {c.missing.length > 0 && (
                  <div className="action-hint" style={{ margin: 0 }}>
                    <Icon name="upload" /> مستندات ناقصة: {c.missing.join('، ')}
                  </div>
                )}

                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', paddingTop: 6, borderTop: '1px solid var(--line-soft)' }}>
                  <button className="btn soft sm" onClick={saveAI} disabled={busy || !lawyerName} type="button">
                    <Icon name="check" /> حفظ
                  </button>
                  <RevisionHistoryButton kind="consult_analysis" refKey={c.id} />
                  {showApprove && (
                    <button className="btn sm" onClick={approveAI} disabled={busy} type="button">
                      <Icon name="check" /> اعتماد التحليل
                    </button>
                  )}
                  {mayAnalyze && !analyzeBlocked && (
                    <button className="btn soft sm" onClick={rerun} disabled={busy} type="button">
                      <Icon name="sparkles" /> إعادة التحليل
                    </button>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* لوحة تحكم وتدخل الإدارة العليا */}
          {isAdmin && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="office" style={{ color: 'var(--primary)' }} />
                  <h3 style={{ margin: 0, fontSize: 14 }}>تدخل وصلاحيات الإدارة</h3>
                </div>
              </div>
              <div className="card-b" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 14 }}>
                <div className="field" style={{ margin: 0 }}>
                  <label style={{ fontSize: 12, fontWeight: 700 }}>تعديل الأولوية</label>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <select
                      value={priority}
                      onChange={(e) => setPriority(e.target.value)}
                      style={{ flex: 1, padding: '7px 10px', borderRadius: 8, fontSize: 13 }}
                    >
                      {CONSULT_PRIORITIES.map((p) => <option key={p}>{p}</option>)}
                    </select>
                    <button
                      className="btn soft sm"
                      onClick={savePriority}
                      disabled={busy || c.isClosed}
                      type="button"
                    >
                      تحديث
                    </button>
                  </div>
                </div>

                <div className="field" style={{ margin: 0 }}>
                  <label style={{ fontSize: 12, fontWeight: 700 }}>المحامي المختص</label>
                  <select
                    value={lawyerId}
                    onChange={(e) => setLawyerId(Number(e.target.value))}
                    style={{ width: '100%', padding: '7px 10px', borderRadius: 8, fontSize: 13, marginBottom: 8 }}
                  >
                    {lawyers.length === 0 && <option value="">— لا يوجد مستشارون —</option>}
                    {lawyers.length > 0 && lawyerId === '' && <option value="">— اختر المحامي المختص —</option>}
                    {lawyers.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.name}{l.dept !== '—' ? ` — ${l.dept}` : ''}{inSessionSuffix(inSession, l.id)}
                      </option>
                    ))}
                  </select>
                  <button
                    className="btn sm"
                    onClick={refer}
                    disabled={busy || !lawyerId || !!referBlocked}
                    type="button"
                    style={{ width: '100%', justifyContent: 'center' }}
                  >
                    <Icon name="scale" /> تعيين المحامي واعتماد الإحالة
                  </button>
                  {referBlocked && (
                    <span style={{ fontSize: 11.5, color: 'var(--muted)', display: 'block', marginTop: 4 }}>
                      {referBlocked}
                    </span>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* السجلات والملفات المرتبطة */}
          {(c.ticketNo || c.caseNo || c.invoiceNo) && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="link" style={{ color: 'var(--cyan)' }} />
                  <h3 style={{ margin: 0, fontSize: 14 }}>السجلات المرتبطة</h3>
                </div>
              </div>
              <div className="card-b" style={{ padding: '12px 16px', display: 'flex', flexDirection: 'column', gap: 8 }}>
                {c.caseNo && (
                  <Link href={`/cases/${c.caseNo}`} className="csd-linked-pill" style={{ justifyContent: 'space-between' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <Icon name="scale" /> ملف القضية
                    </span>
                    <b>#{c.caseNo}</b>
                  </Link>
                )}
                {c.ticketNo && (
                  <div className="csd-linked-pill static" style={{ justifyContent: 'space-between' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <Icon name="ticket" /> التذكرة الأساسية
                    </span>
                    <b>#{c.ticketNo}</b>
                  </div>
                )}
                {c.invoiceNo && (
                  <div className="csd-linked-pill static" style={{ justifyContent: 'space-between' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <Icon name="card" /> رقم الفاتورة
                    </span>
                    <b>#{c.invoiceNo}</b>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* سجل التدقيق الزمني (Audit Trail) */}
          <div className="card">
            <div className="card-h">
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="clock" style={{ color: 'var(--faint)' }} />
                <h3 style={{ margin: 0, fontSize: 14 }}>سجل التدقيق ({c.audit.length})</h3>
              </div>
            </div>
            <div className="card-b" style={{ padding: '14px 16px', maxHeight: 380, overflowY: 'auto' }}>
              {c.audit.length ? (
                <div className="cj-audit-timeline">
                  {c.audit.map((a, i) => (
                    <div key={i} className="cj-audit-entry">
                      <div className="cj-audit-dot" />
                      <b style={{ fontSize: 12.8, color: 'var(--deep)' }}>{a.field}</b>
                      <span style={{ fontSize: 12, color: 'var(--muted)', lineHeight: 1.4 }}>
                        {a.user}: {a.before} ← {a.after}
                      </span>
                      <span style={{ color: 'var(--faint)', fontSize: 10.5, marginTop: 2 }} dir="auto">
                        {fmtAuditTime(a)}
                      </span>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: '20px 10px', textAlign: 'center' }}>
                  <Icon name="info" />
                  <b style={{ display: 'block', fontSize: 12.5, marginTop: 4 }}>لا تعديلات مسجلة بعد</b>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};


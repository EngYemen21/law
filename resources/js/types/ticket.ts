import type { TrackGovernanceData } from '@/components/babylon/TicketTrackDecisionCard';

/*
 * **أنواع التذكرة — نوعٌ واحد لكلّ بانٍ في الخادم** (خطّة «إزالة التعارض» — المرحلة ٣).
 * كانت بطاقة الطاقم (`toEmployeeCard`) مُعرَّفةً تسع مرّاتٍ في ثماني صفحات بحقولٍ متباعدة، وبطاقة
 * العميل (`toCard`) مرّتين. الصفحة التي تُلحق بالبطاقة حقولاً تمدّ النوع (`extends`) ولا تنسخه.
 */

/** أعلام الأفعال من الكيان (`TicketEntity::actionsMatrix`). */
export interface TicketActions {
  can_request_consult: boolean;
  can_convert_case: boolean;
  can_convert_exec: boolean;
  can_close: boolean;
  can_request_docs: boolean;
}

/** بطاقة الطاقم — `Ticket::toEmployeeCard`. */
export interface EmployeeTicketCard {
  no: string;
  client: string;
  clientId: number;
  type: string;
  subject: string | null;
  priority: string;
  dept: string | null;
  lawyer: string;
  lawyerId: number | null;
  status: string;
  /** اسم حالة الـEnum (`TicketStatus::…->name`) — `AwaitingDocs` لا `awaiting_docs`. */
  statusCode: string;
  actions: TicketActions;
  tone: string;
  isFrozen: boolean;
  hasCase: boolean;
  caseNumber: string | null;
  hasExecution: boolean;
  executionNumber: string | null;
  closureReasonCode: string | null;
  closureNotes: string | null;
  canDecideOutcome: boolean;
  isTerminal: boolean;
  /** الموظّف المسؤول عن المحادثة الآن (`ConversationHandler`). */
  handler: string | null;
  trackGovernance: TrackGovernanceData;
}

/** بطاقة العميل — `Ticket::toCard` (الحالة بتسمية العميل). */
export interface ClientTicketCard {
  no: string;
  type: string;
  subject: string | null;
  priority: string;
  dept: string | null;
  status: string;
  statusCode: string;
  actions: TicketActions;
  /** تبويب العميل — من الحالة الداخليّة (`TicketJourney::CLIENT_PHASES`). */
  phase: 'analysis' | 'opinion' | null;
  tone: string;
  last: string | null;
  date: string;
  lawyer: string;
  step: number;
  needsDoc: boolean;
  needsBooking: boolean;
  hasCase: boolean;
  caseNumber: string | null;
  hasExecution: boolean;
  executionNumber: string | null;
  courtName: string | null;
  claimAmount: number | string | null;
  opponentName: string | null;
  documentsCount: number;
  messagesCount: number;
  createdAt: string | null;
  isFrozen: boolean;
  isTerminal: boolean;
  /** ما نُشر للعميل من قرار المآل وحده — `Ticket::publishedTrackDecision`. */
  trackGovernance: { approvedTrack: string | null; approvedTrackReason: string | null };
}

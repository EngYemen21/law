import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import CaseClosureModal from '@/components/babylon/CaseClosureModal';
import type { ClosureReasonOption } from '@/components/babylon/CaseClosureModal';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import ConversationHandlerCard from '@/components/babylon/ConversationHandlerCard';
import type { ConversationHistory } from '@/components/babylon/ConversationHandlerCard';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { AppealCard, type AppealData } from '@/lib/case-court';
import { CASE_LIFE, CONFIRM_ARCHIVE_CASE, caseStage, HearingsCard, CaseMsgRow } from '@/lib/case-ui';
import type { Hearing } from '@/lib/case-ui';
import type { Message } from '@/lib/chat';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';
import { inSessionSuffix, useInSession } from '@/lib/staff-presence';
import type { CaseDocumentCard, TicketDocumentCard } from '@/types';

/**
 * **تفاصيل القضيّة للإدارة العليا** (قرار المالك 2026-09-11).
 *
 * كانت الإدارة تحدّد الأتعاب وتُغلق وتؤرشف ولا ترى المحادثة ولا الجلسات ولا المستندات.
 * هنا اطّلاعٌ كامل — بما فيه الملاحظات الداخليّة والمحجوب بانتظار الاعتماد — وأزرارُها
 * الإداريّة. الجلسات للقراءة: تحريكُها عملُ المحامي المسنَد.
 */

interface CaseInfo {
  no: string; client: string; type: string; dept: string | null; lawyer: string; lawyerId: number | null;
  status: string; tone: string; next?: string | null; pleadingStatus: string; ruling?: string | null;
  fee?: number | null; feeStatus?: string | null; invoice?: string | null;
  aiClassification?: { type?: string; department?: string } | null;
  najiz?: { requestNo?: string | null; filedAt?: string | null; caseNo?: string | null; court?: string | null; circuit?: string | null; registeredAt?: string | null } | null;
  appeal?: AppealData | null;
  closureReason?: string | null; closureNotes?: string | null;
  canClose: boolean; canArchive: boolean; canExecute: boolean; canReassign: boolean; feePending: boolean;
  /** حكم انتقال `ReopenCase` (حالته المصدر + صلاحيّة الفاعل) — لا مقارنة بنصّ الحالة هنا. */
  canReopen: boolean;
}
/** مرفقٌ من التذكرة قبل التحويل (`CaseTicketDocuments`). */
/** `CaseTicketDocuments::for` — النوع المشترك (`@/types`). */
type TicketDoc = TicketDocumentCard;
/** `CaseDocument::toData` — النوع المشترك (`@/types`). */
type CaseDoc = CaseDocumentCard;
interface LawyerOpt { id: number; name: string }
interface Props {
  case: CaseInfo; channel: string; messages: Message[]; hearings: Hearing[]; documents: CaseDoc[]; ticketDocuments?: TicketDoc[];
  convertedExec?: boolean; lawyers: LawyerOpt[];
  /** أسباب الإغلاق من الكتالوج (`ClosureCaseReasonCode::options`) — كانت نسخةً مكتوبةً هنا */
  closureReasons: ClosureReasonOption[];
  /** من يتولّى المحادثة ومن تولّاها قبله — `ConversationHandler::history`. */
  conversation?: ConversationHistory | null;
}

const AdminCase: React.FC<Props> = ({ case: c, channel, messages, hearings, documents, ticketDocuments = [], convertedExec, lawyers, conversation, closureReasons }) => {
  const inSession = useInSession();
  const toast = useToast();
  const base = `/admin/cases/${encodeURIComponent(c.no)}`;
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: c.status, tone: c.tone });
  const [lawyerId, setLawyerId] = useState<string>(c.lawyerId ? String(c.lawyerId) : '');
  const [busy, setBusy] = useState(false);
  const [closeModalOpen, setCloseModalOpen] = useState(false);
  const [reopenModalOpen, setReopenModalOpen] = useState(false);
  const [reopenReason, setReopenReason] = useState('استئناف الحكم');
  const [reopenNotes, setReopenNotes] = useState('');
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const ch = echo.private(channel);
    const append = (e: { message: Message }) => {
      const m = e.message;

      if (m.id && seen.current.has(m.id)) {
        return;
      }

      if (m.id) {
        seen.current.add(m.id);
      }

      setMsgs((prev) => [...prev, m]);
    };
    ch.listen('.message', append);
    // الملاحظات الداخليّة تُبثّ على قناة الطاقم وحدها — لا على القناة التي يسمعها العميل
    echo.private(`${channel}.staff`).listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => setLive({ status: e.status, tone: e.tone }));

    return () => {
      echo.leave(channel);
      echo.leave(`${channel}.staff`);
    };
  }, [channel]);

  // كلّ فعلٍ يُسمع رفضُه — لا زرّ يسقط صامتاً
  const act = (path: string, data: Record<string, string | number>, ok: string) => {
    setBusy(true);
    router.post(`${base}/${path}`, data, {
      preserveScroll: true,
      onSuccess: () => toast(ok),
      onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر تنفيذ الإجراء')),
      onFinish: () => setBusy(false),
    });
  };

  // الأرشفة نهائيّةٌ بعد الإغلاق — تُؤكَّد بنصّ القائمة نفسه (`CONFIRM_ARCHIVE_CASE`)؛ كانت هنا بلا تأكيد
  const ask = useConfirm();
  const archive = async () => {
    if (await ask(CONFIRM_ARCHIVE_CASE)) {
      act('archive', {}, 'أُرشفت القضية');
    }
  };

  const reassign = () => {
    if (!lawyerId || Number(lawyerId) === c.lawyerId) {
      toast('اختر محامياً غير المسنَد حالياً');

      return;
    }

    act('lawyer', { lawyer_id: Number(lawyerId) }, 'أُعيد إسناد القضية');
  };

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/admin/cases" className="btn soft sm"><Icon name="reply" /> رجوع لإدارة القضايا</Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>مسار القضية {c.no}</h3><Badge text={live.status} tone={live.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={CASE_LIFE} cur={caseStage(live.status)} /></div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>محادثة القضية</h3><span className="sub">اطّلاع — تشمل الملاحظات الداخليّة والمحجوب بانتظار الاعتماد</span></div>
            <div className="thread">
              {msgs.length ? msgs.map((m, i) => <CaseMsgRow key={m.id ?? i} m={m} />) : (
                <div className="empty"><Icon name="chat" /><b>لا رسائل بعد</b></div>
              )}
            </div>
          </div>

          {c.ruling && (
            <div className="card" style={{ marginTop: 16 }}>
              <div className="card-h"><h3>منطوق الحكم الابتدائي</h3></div>
              <div className="card-b" style={{ padding: 16, whiteSpace: 'pre-line' }}>{c.ruling}</div>
            </div>
          )}

          {/* مسار الاستئناف والاعتراض للاطّلاع */}
          <AppealCard base={base} appeal={c.appeal} canAct={false} />
        </div>

        <aside className="tf-aside">
          <ConversationHandlerCard conversation={conversation} />

          {/* إجراءات الإدارة — الأزرار تتبع ما يقبله الخادم */}
          <div className="card">
            <div className="card-h"><h3>إجراءات الإدارة</h3></div>
            <div className="card-b" style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 10 }}>
              {c.feePending && (
                <Link href="/admin/casefees" className="btn sm"><Icon name="card" /> تحديد الأتعاب</Link>
              )}
              {c.canClose && (
                <button className="btn sm" type="button" disabled={busy} onClick={() => setCloseModalOpen(true)}>
                  <Icon name="check" /> إغلاق القضية (مسبّب)
                </button>
              )}
              {c.canReopen && (
                <button className="btn sm soft" type="button" disabled={busy} onClick={() => setReopenModalOpen(true)}>
                  <Icon name="reply" /> إعادة فتح القضية
                </button>
              )}
              {c.canExecute && (
                <button className="btn sm soft" type="button" disabled={busy} onClick={() => act('execute', {}, 'فُتح طلب تنفيذ الحكم')}>
                  <Icon name="exec" /> فتح طلب تنفيذ الحكم
                </button>
              )}
              {c.canArchive && (
                <button className="btn sm soft" type="button" disabled={busy} onClick={archive}>
                  <Icon name="folder" /> أرشفة القضية
                </button>
              )}
              {convertedExec && <Badge text="محوّل لتنفيذ" tone="b-cyan" />}

              {c.canReassign && (
                <div className="field" style={{ margin: 0 }}>
                  <label>المحامي المسنَد</label>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <select className="input" value={lawyerId} onChange={(e) => setLawyerId(e.target.value)} style={{ flex: 1 }}>
                      <option value="">— اختر محامياً —</option>
                      {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}{inSessionSuffix(inSession, l.id)}</option>)}
                    </select>
                    <button className="btn sm soft" type="button" disabled={busy || !lawyerId} onClick={reassign}>إعادة الإسناد</button>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* المقترح الذكيّ يُعرض ولا يُطبَّق — يُعتمد في صندوق المراجعة */}
          {c.aiClassification && (c.aiClassification.type || c.aiClassification.department) && (
            <div className="card">
              <div className="card-h"><h3>تصنيف مقترح</h3><Badge text="بانتظار المراجعة" tone="b-amber" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 8 }}>
                  اقترحه الذكاء الاصطناعي — اعتماده أو رفضه في صندوق مراجعة الذكاء.
                </div>
                {c.aiClassification.type && <div><b>النوع المقترح:</b> {c.aiClassification.type}</div>}
                {c.aiClassification.department && <div><b>القسم المقترح:</b> {c.aiClassification.department}</div>}
              </div>
            </div>
          )}

          {/* مستندات القضية */}
          <div className="card">
            <div className="card-h"><h3>مستندات القضية</h3><span className="sub">{documents.length}</span></div>
            <div className="card-b">
              {documents.length ? documents.map((d) => (
                <div key={d.id} className="item">
                  <div className="iico"><Icon name="doc" /></div>
                  <div className="imeta">
                    <b>{d.name}</b>
                    <span>{d.by} · {d.date}{d.docType ? ` · ${d.docType}` : ''}</span>
                    {d.hearingTitle && (
                      <div style={{ marginTop: 4 }}>
                        <Badge text={`جلسة: ${d.hearingTitle}`} tone="b-blue" />
                      </div>
                    )}
                    {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
                  </div>
                  {d.downloadUrl && (
                    <a className="btn soft sm" href={d.downloadUrl} title="تنزيل المستند"><Icon name="download" /> تنزيل</a>
                  )}
                </div>
              )) : (
                <div className="empty"><Icon name="doc" /><b>لا مستندات</b></div>
              )}
            </div>
          </div>

          {/* مرفقات الطلب قبل التحويل — من التذكرة نفسها (`CaseTicketDocuments`)، بلا المرفوض «غير مرتبط» */}
          {ticketDocuments.length > 0 && (
            <div className="card">
              <div className="card-h"><h3>مرفقات الطلب قبل التحويل</h3><span className="sub">{ticketDocuments.length} مستند</span></div>
              <div className="card-b">
                {ticketDocuments.map((d) => (
                  <div key={`t-${d.id}`} className="item">
                    <div className="iico"><Icon name="doc" /></div>
                    <div className="imeta">
                      <b>{d.name}</b>
                      <span>{d.by} · {d.date}{d.docType ? ` · ${d.docType}` : ''}</span>
                      {d.summary && <span style={{ display: 'block', marginTop: 3, fontSize: 11.5, color: 'var(--muted)' }}>{d.summary}</span>}
                    </div>
                    {d.downloadUrl && (
                      <a className="btn soft sm" href={d.downloadUrl} title="تنزيل المستند"><Icon name="download" /> تنزيل</a>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          <HearingsCard hearings={hearings} documents={documents} />

          <div className="card">
            <div className="tc-top"><div className="lbl">القضية</div><div className="num">{c.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{c.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{c.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{c.dept ?? '—'}</span></div>
              <div className="tc-row"><span className="k">المحامي</span><span className="v">{c.lawyer}</span></div>
              <div className="tc-row"><span className="k">الأتعاب</span><span className="v">{c.feeStatus === 'waived' ? 'بلا أتعاب' : (c.fee != null ? `${c.fee.toLocaleString()} ر.س` : '—')}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
              {c.najiz?.requestNo && <div className="tc-row"><span className="k">رقم طلب ناجز</span><span className="v">{c.najiz.requestNo}{c.najiz.filedAt ? ` · ${c.najiz.filedAt}` : ''}</span></div>}
              {c.najiz?.caseNo && <div className="tc-row"><span className="k">رقم القضية</span><span className="v">{c.najiz.caseNo}</span></div>}
              {c.najiz?.circuit && <div className="tc-row"><span className="k">المحكمة والدائرة</span><span className="v">{[c.najiz.court, c.najiz.circuit].filter(Boolean).join(' — ')}</span></div>}
            </div>
          </div>
        </aside>
      </div>

      {/* مودال إغلاق القضية مع التسبيب — المكوّن نفسه الذي تفتحه قائمة القضايا */}
      <CaseClosureModal
        open={closeModalOpen}
        caseNo={c.no}
        reasons={closureReasons}
        busy={busy}
        onClose={() => setCloseModalOpen(false)}
        onSubmit={(reason, notes) => {
          act('close', { closure_reason: reason, closure_notes: notes }, 'أُغلقت القضية بنجاح مع توثيق السبب');
          setCloseModalOpen(false);
        }}
      />

      {/* مودال إعادة فتح القضية */}
      <Modal open={reopenModalOpen} onClose={() => setReopenModalOpen(false)} title="إعادة فتح القضية المغلقة" maxWidth={480}>
        <form onSubmit={(e) => {
          e.preventDefault();
          const combinedReason = `${reopenReason} — ${reopenNotes.trim()}`;
          act('reopen', { reason: combinedReason, reopen_reason: reopenReason, reopen_notes: reopenNotes }, 'أُعيد فتح القضية بنجاح');
          setReopenModalOpen(false);
        }}>
          <div className="field">
            <label>سبب إعادة الفتح</label>
            <select className="input" value={reopenReason} onChange={(e) => setReopenReason(e.target.value)}>
              <option value="استئناف الحكم">استئناف الحكم الابتدائي</option>
              <option value="التماس إعادة النظر">التماس إعادة النظر</option>
              <option value="متابعة إجراءات قضائية">متابعة إجراءات قضائية جديدة</option>
              <option value="أخرى">أخرى</option>
            </select>
          </div>
          <div className="field">
            <label>ملاحظات ومسوغات إعادة الفتح (إلزامي للتوثيق)</label>
            <textarea rows={3} value={reopenNotes} onChange={(e) => setReopenNotes(e.target.value)} placeholder="اكتب تفاصيل ومسوغات إعادة فتح القضية..." required />
          </div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 14 }}>
            <button className="btn soft sm" type="button" onClick={() => setReopenModalOpen(false)}>إلغاء</button>
            <button className="btn sm" type="submit" disabled={busy || !reopenNotes.trim()}><Icon name="reply" /> تأكيد إعادة الفتح</button>
          </div>
        </form>
      </Modal>
    </div>
  );
};

export default AdminCase;

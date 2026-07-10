import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import MsgMeta from '@/components/babylon/MsgMeta';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';
import { type SummaryData } from '@/lib/lawyer-data';

// دراسة التذكرة لدى المستشار — محادثة العميل (سياق حيّ) + ملخص الملف + الاعتماد

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { ticket: EmpTicket; channel: string; messages: Message[]; summary: SummaryData | null; converted?: boolean; }

const MsgRow: React.FC<{ m: Message }> = ({ m }) => {
  const isClient = m.who === 'client' || m.who === 'me';
  const actor = isClient ? 'me' : 'ai';
  return (
    <div className={`msg ${actor}`}>
      <div className={`av ${actor}`}>{isClient ? 'ع' : <img src="/images/mono.jpg" alt="" />}</div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{isClient ? 'العميل' : m.name}</b>
          {m.role && <span className={`role ${actor}`}>{m.role}</span>}
          <time>{m.time}</time>
        </div>
        <div className="bubble" dangerouslySetInnerHTML={{ __html: m.text }} />
        <MsgMeta m={m} />
      </div>
    </div>
  );
};

const SUM_FIELDS: { key: keyof SummaryData; label: string }[] = [
  { key: 'caseSummary', label: 'تلخيص القضية' },
  { key: 'attachmentsSummary', label: 'تلخيص المرفقات' },
  { key: 'facts', label: 'الوقائع' },
  { key: 'keyPoints', label: 'النقاط المهمة' },
];

const LawyerTicketChat: React.FC<Props> = ({ ticket, channel, messages, summary, converted }) => {
  const toast = useToast();
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });
  const endRef = useRef<HTMLDivElement>(null);
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const ch = echo.private(channel);
    ch.listen('.message', (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    });
    ch.listen('.status', (e: { status: string; tone: string }) => setStatus(e));
    return () => { echo.leave(channel); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, [msgs]);

  const approve = () =>
    router.post(`/lawyer/summary/${encodeURIComponent(ticket.no)}/approve`, {}, {
      onSuccess: () => toast('تم اعتماد الملخص وإرساله لمحادثة العميل'),
    });

  const approveResult = () =>
    router.post(`/lawyer/tickets/${encodeURIComponent(ticket.no)}/result`, {}, {
      onSuccess: () => toast('تم اعتماد ملخص الجلسة ورفعه للإدارة'),
    });

  const convert = () =>
    router.post(`/lawyer/tickets/${encodeURIComponent(ticket.no)}/convert`, {}, {
      onSuccess: () => toast('تم تحويل التذكرة إلى قضية'),
    });

  const closeTicket = () =>
    router.post(`/lawyer/tickets/${encodeURIComponent(ticket.no)}/close`, {}, {
      onSuccess: () => toast('تم إغلاق الطلب دون تحويله إلى قضية'),
    });

  const requestDocs = () =>
    router.post(`/lawyer/tickets/${encodeURIComponent(ticket.no)}/request-docs`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم طلب مستندات إضافية من العميل'),
    });

  const cur = tktStage(status.status);
  const canConvert = status.status === 'مكتملة' && !converted;

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/lawyer/tickets" className="btn soft sm"><Icon name="reply" /> رجوع لتذاكري</Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>مسار المعالجة</h3><Badge text={status.status} tone={status.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={TKT_LIFE} cur={cur} /></div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>محادثة العميل {ticket.no}</h3></div>
            <div className="thread">
              {msgs.map((m, i) => <MsgRow key={m.id ?? i} m={m} />)}
              <div ref={endRef} />
            </div>
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="card-h">
              <h3>ملخص الملف</h3>
              {summary && <Badge text={summary.approved ? 'معتمد' : 'بانتظار اعتمادك'} tone={summary.approved ? 'b-green' : 'b-amber'} />}
            </div>
            <div className="card-b" style={{ padding: 14 }}>
              {summary ? (
                <>
                  {SUM_FIELDS.map((f) => (
                    <div key={f.key} style={{ marginBottom: 10 }}>
                      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 3 }}>{f.label}</div>
                      <div style={{ fontSize: 13, whiteSpace: 'pre-line' }}>{(summary[f.key] as string) || '—'}</div>
                    </div>
                  ))}
                  <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
                    <Link href={`/lawyer/summary/${encodeURIComponent(ticket.no)}`} className="btn soft sm">
                      <Icon name="doc" /> تعديل الملخص
                    </Link>
                    {!summary.approved && (
                      <button className="btn sm" onClick={approve} type="button">
                        <Icon name="check" /> اعتماد وإرسال للعميل
                      </button>
                    )}
                  </div>
                </>
              ) : (
                <div className="empty"><Icon name="doc" /><b>لا ملخص بعد</b></div>
              )}
            </div>
          </div>

          {summary?.resultStatus === 'pending_lawyer' && (
            <div className="card">
              <div className="card-h"><h3>نتيجة الجلسة</h3><Badge text="بانتظار اعتمادك" tone="b-amber" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                <div style={{ fontSize: 13, whiteSpace: 'pre-line', marginBottom: 12 }}>{summary.result || '—'}</div>
                <button className="btn sm" onClick={approveResult} type="button">
                  <Icon name="check" /> اعتماد ملخص الجلسة ورفعه للإدارة
                </button>
              </div>
            </div>
          )}

          {(canConvert || converted) && (
            <div className="card">
              <div className="card-h"><h3>قرار المستشار</h3>{converted && <Badge text="محوّلة لقضية" tone="b-cyan" />}</div>
              <div className="card-b" style={{ padding: 14 }}>
                {converted ? (
                  <div className="empty"><Icon name="scale" /><b>تم تحويل هذه التذكرة إلى قضية</b></div>
                ) : (
                  <>
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
                      اكتملت الاستشارة. اختر الإجراء المناسب:
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                      <button className="btn sm" onClick={convert} type="button">
                        <Icon name="scale" /> تحويل إلى قضية
                      </button>
                      <button className="btn soft sm" onClick={requestDocs} type="button">
                        <Icon name="upload" /> طلب مستندات إضافية
                      </button>
                      <button className="btn soft sm" onClick={closeTicket} type="button">
                        <Icon name="check" /> إغلاق دون تحويل
                      </button>
                    </div>
                  </>
                )}
              </div>
            </div>
          )}

          <div className="card">
            <div className="tc-top"><div className="lbl">التذكرة</div><div className="num">{ticket.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{ticket.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{ticket.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{ticket.dept}</span></div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default LawyerTicketChat;

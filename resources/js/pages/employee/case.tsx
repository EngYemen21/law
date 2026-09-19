import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { AppealCard, AttachDocModal, HearingUpdatesCard, NajizFilingCard, RulingCard, ScheduleHearingCard } from '@/lib/case-court';
import type { AppealData, Filing } from '@/lib/case-court';
import { CASE_LIFE, caseStage, type Hearing, HearingsCard, CaseMsgRow } from '@/lib/case-ui';
import { type Message } from '@/lib/chat';

interface CaseInfo {
  no: string;
  client: string;
  type: string;
  dept: string;
  court?: string;
  lawyer: string;
  status: string;
  tone: string;
  next?: string | null;
  // بيانات الرفع والقيد في ناجز (الخطّة ب) — للاطّلاع
  najiz?: { requestNo?: string | null; filedAt?: string | null; caseNo?: string | null; court?: string | null; circuit?: string | null; registeredAt?: string | null } | null;
  ruling?: string | null;
  appeal?: AppealData | null;
}

interface CaseDoc {
  id: number;
  name: string;
  by: string;
  status: string;
  docType?: string;
  summary?: string;
  date: string;
  /** `null` لمن لا تُجيزه `ConversationFiles` — الخادم يقرّر لا الشاشة. */
  downloadUrl?: string | null;
  hearingId?: number | null;
  hearingTitle?: string | null;
}

interface ClientStats {
  totalTickets: number;
  activeTickets: number;
  totalCases: number;
  memberSince: string;
}

interface Props {
  case: CaseInfo;
  channel: string;
  messages: Message[];
  hearings: Hearing[];
  documents: CaseDoc[];
  clientStats?: ClientStats | null;
  filing?: Filing;
  /** «إجراءات المحكمة والجلسات» — تمنحها الإدارة من تبويب الموظّفين. */
  canCourt?: boolean;
  /** «تسجيل الأحكام» فوقها — الحكم وتصحيحه وحكم الاستئناف (قرار المالك 2026-09-18). */
  canRule?: boolean;
}

const EmployeeCase: React.FC<Props> = ({
  case: c,
  channel,
  messages,
  hearings,
  documents,
  clientStats,
  filing = { canFile: false, canRegister: false, data: null },
  canCourt = false,
  canRule = false,
}) => {
  const toast = useToast();
  const base = `/employee/cases/${encodeURIComponent(c.no)}`;
  const [attachOpen, setAttachOpen] = useState(false);

  const [reply, setReply] = useState('');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: c.status, tone: c.tone });
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));
  const [propsFrom, setPropsFrom] = useState({ status: c.status, messages });

  // الحالة والمحادثة تتبعان الخادم بعد كلّ إجراء — لا البثّ وحده (الموظّف صار يسجّل القيد والجلسات والحكم)
  if (c.status !== propsFrom.status || messages !== propsFrom.messages) {
    setPropsFrom({ status: c.status, messages });
    setLive({ status: c.status, tone: c.tone });
    setMsgs(messages);
  }

  // معرّفات ما جاء من الخادم تُعلَّم مقروءة كي لا يكرّرها البثّ — في أثرٍ لا أثناء الرسم
  useEffect(() => {
    messages.forEach((m) => m.id && seen.current.add(m.id));
  }, [messages]);

  useEffect(() => {
    const ch = echo.private(channel);
    ch.listen('.message', (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    });
    ch.listen('.status', (e: { status: string; tone: string }) => setLive({ status: e.status, tone: e.tone }));
    return () => { echo.leave(channel); };
  }, [channel]);

  const send = (e: React.FormEvent) => {
    e.preventDefault();
    const v = reply.trim();
    if (!v) return;

    axios.post(`/employee/cases/${encodeURIComponent(c.no)}/reply`, { body: v })
      .then(() => setReply(''))
      .catch(() => toast('⚠️ تعذّر إرسال الرد'));
  };

  return (
    <div className="tflow">
      {/* شريط الرجوع */}
      <div style={{ marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Link href="/employee/cases" className="btn soft sm">
          <Icon name="reply" /> رجوع لكل القضايا
        </Link>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <span className="muted" style={{ fontSize: 13 }}>المستشار المترافع:</span>
          <b>{c.lawyer}</b>
        </div>
      </div>

      {/* مسار مراحل القضية */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="scale" />
            <h3>مسار القضية {c.no}</h3>
          </div>
          <Badge text={live.status} tone={live.tone} />
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={CASE_LIFE} cur={caseStage(live.status)} />
        </div>
      </div>

      <div className="tf-grid">
        {/* عمود المحادثة */}
        <div>
          <div className="card">
            <div className="card-h">
              <h3>محادثة ومتابعة القضية مع العميل</h3>
              <span className="sub">تنسيق وتحديثات ملف الدعوى</span>
            </div>

            <div className="thread">
              {msgs.map((m, i) => <CaseMsgRow key={m.id ?? i} m={m} />)}
            </div>

            <div className="composer">
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="reply" cls="ic sm" />
                <span>رد خدمة العملاء (يظهر للعميل مباشرة):</span>
              </div>
              <form onSubmit={send}>
                <textarea
                  value={reply}
                  onChange={(e) => setReply(e.target.value)}
                  placeholder="اكتب رسالة التحديث أو الإفادة للعميل…"
                />
                <div className="crow">
                  <button className="btn" type="submit">
                    <Icon name="send" /> إرسال الرد
                  </button>
                  {live.status !== 'مؤرشفة' && (
                    <button
                      className="btn soft"
                      type="button"
                      onClick={() => setAttachOpen(true)}
                      title="إرفاق مستند جديد إلى ملف القضية"
                    >
                      <Icon name="upload" /> إرفاق مستند
                    </button>
                  )}
                </div>
              </form>
            </div>
          </div>

          {/* جدولة الجلسات — لمن منحته الإدارة الصلاحيّة، والقضيّة منظورة */}
          {canCourt && live.status === 'منظورة' && <ScheduleHearingCard base={base} />}

          {/* تسجيل الحكم أو عرضه مع إتاحة التصحيح */}
          {(canCourt || c.ruling) && (live.status === 'منظورة' || c.ruling) && (
            <RulingCard
              base={base}
              ruling={c.ruling}
              canRecord={canRule}
              canCorrect={canRule && !['مؤرشفة'].includes(live.status)}
            />
          )}

          {/* مسار الاستئناف والاعتراض */}
          {(canCourt || c.appeal) && (
            <AppealCard
              base={base}
              appeal={c.appeal}
              canAct={canCourt && !['مؤرشفة'].includes(live.status)}
              canRule={canRule && !['مؤرشفة'].includes(live.status)}
              defaultCourt={c.court ?? ''}
            />
          )}
        </div>

        {/* الشريط الجانبي */}
        <aside className="tf-aside">
          {/* بطاقة معلومات القضية */}
          <div className="card">
            <div className="tc-top">
              <div className="lbl">ملف القضية</div>
              <div className="num">{c.no}</div>
            </div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{c.client}</span></div>
              <div className="tc-row"><span className="k">نوع الدعوى</span><span className="v">{c.type}</span></div>
              {c.dept && <div className="tc-row"><span className="k">القسم</span><span className="v">{c.dept}</span></div>}
              {c.court && <div className="tc-row"><span className="k">المحكمة</span><span className="v">{c.court}</span></div>}
              {c.najiz?.circuit && <div className="tc-row"><span className="k">الدائرة</span><span className="v">{c.najiz.circuit}</span></div>}
              {c.najiz?.caseNo && <div className="tc-row"><span className="k">رقم القضية في ناجز</span><span className="v">{c.najiz.caseNo}{c.najiz.registeredAt ? ` · قُيّدت ${c.najiz.registeredAt}` : ''}</span></div>}
              <div className="tc-row"><span className="k">المستشار المترافع</span><span className="v">{c.lawyer}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
            </div>
          </div>

          {/* رفع الدعوى في ناجز ثمّ قيدها — ما يجوز يحدّده الخادم */}
          <NajizFilingCard base={base} filing={filing} defaultCourt={c.court && c.court !== '—' ? c.court : ''} />

          {/* سياق العميل 360° */}
          {clientStats && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="user" />
                  <h3>سياق العميل</h3>
                </div>
              </div>
              <div className="card-b" style={{ padding: '12px 16px' }}>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>إجمالي القضايا:</span>
                  <b style={{ color: 'var(--primary)' }}>{clientStats.totalCases} قضية</b>
                </div>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>التذاكر النشطة:</span>
                  <b>{clientStats.activeTickets} تذكرة</b>
                </div>
                <div className="tc-row" style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: 13 }}>
                  <span style={{ color: 'var(--muted)' }}>عضو منذ:</span>
                  <span className="muted">{clientStats.memberSince}</span>
                </div>
              </div>
            </div>
          )}

          {/* تحديث الجلسات — المغلقة والمؤرشفة للقراءة */}
          {canCourt && hearings.length > 0 && !['مغلقة', 'مؤرشفة'].includes(live.status) && <HearingUpdatesCard base={base} hearings={hearings} />}

          {/* بطاقة الجلسات القضائية */}
          <HearingsCard hearings={hearings} documents={documents} />

          {/* سجل مستندات القضية (بيانات وصفية فقط دون روابط تحميل للموظف) */}
          <div className="card">
            <div className="card-h">
              <h3>سجل مستندات القضية</h3>
              <span className="sub">{documents.length} مستند</span>
            </div>
            <div className="card-b" style={{ padding: 12 }}>
              {documents.length ? (
                documents.map((d) => (
                  <div key={d.id} className="item" style={{ padding: '8px 0', borderBottom: '1px solid var(--line-soft)' }}>
                    <div className="iico"><Icon name="doc" /></div>
                    <div className="imeta">
                      <b style={{ fontSize: 13 }}>{d.name}</b>
                      <span style={{ fontSize: 11.5, color: 'var(--muted)', display: 'block' }}>
                        {d.by} · {d.date}{d.docType ? ` · ${d.docType}` : ''}
                      </span>
                      {d.hearingTitle && (
                        <div style={{ marginTop: 4 }}>
                          <Badge text={`جلسة: ${d.hearingTitle}`} tone="b-blue" />
                        </div>
                      )}
                    </div>
                    {d.downloadUrl && (
                      <a className="btn soft sm" href={d.downloadUrl} title="تنزيل المستند">
                        <Icon name="download" /> تنزيل
                      </a>
                    )}
                  </div>
                ))
              ) : (
                <div className="empty" style={{ padding: '12px 0' }}>
                  <Icon name="doc" />
                  <span style={{ fontSize: 12.5, color: 'var(--muted)' }}>لا توجد مستندات مسجلة</span>
                </div>
              )}

              {/* تنبيه خصوصية وسرية المستندات */}
              <div style={{ marginTop: 10, padding: '7px 10px', background: 'var(--paper-2)', borderRadius: 8, fontSize: 11, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="lock" cls="ic sm" />
                <span>المستندات القضائية سرية — تُنزَّل لأطراف الملف وحدهم</span>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <AttachDocModal
        open={attachOpen}
        onClose={() => setAttachOpen(false)}
        base={base}
        hearings={hearings}
      />
    </div>
  );
};

export default EmployeeCase;


import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
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
}

interface CaseDoc {
  id: number;
  name: string;
  by: string;
  status: string;
  docType?: string;
  summary?: string;
  date: string;
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
}

const EmployeeCase: React.FC<Props> = ({
  case: c,
  channel,
  messages,
  hearings,
  documents,
  clientStats,
}) => {
  const toast = useToast();
  const docRef = useRef<HTMLInputElement>(null);

  // إرفاق مستند من خدمة العملاء لملف القضية
  const onPickDoc = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;

    router.post(`/employee/cases/${encodeURIComponent(c.no)}/attach`, { file }, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => toast('تم إرفاق المستند بملف القضية بنجاح'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إرفاق المستند'}`),
    });
  };

  const [reply, setReply] = useState('');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: c.status, tone: c.tone });
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

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
                    <>
                      <button
                        className="btn soft"
                        type="button"
                        onClick={() => docRef.current?.click()}
                        title="إرفاق مستند جديد إلى ملف القضية"
                      >
                        <Icon name="upload" /> إرفاق مستند
                      </button>
                      <input
                        ref={docRef}
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        style={{ display: 'none' }}
                        onChange={onPickDoc}
                      />
                    </>
                  )}
                </div>
              </form>
            </div>
          </div>
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
              <div className="tc-row"><span className="k">المستشار المترافع</span><span className="v">{c.lawyer}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
            </div>
          </div>

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

          {/* بطاقة الجلسات القضائية */}
          <HearingsCard hearings={hearings} />

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
                    </div>
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
                <span>المستندات القضائية سرية ومخصصة للمستشار والإدارة</span>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default EmployeeCase;


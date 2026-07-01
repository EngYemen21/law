import { Link, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { CLIENT_NAME, OFFICE_IP, nowClock, todayDate } from '@/lib/chat';
import { type FullMeeting, FULL_MEETINGS, TASKS } from '@/lib/lawyer-data';

// يطابق meetingView (دور المحامي — غير الإدارة) في index (82).html

function defaultMinutes(m: FullMeeting): string {
  return `محضر اجتماع: ${m.title}\nالنوع: ${m.type}\nالتاريخ: ${m.when}\n\n` +
    `أبرز ما دار:\n- ${m.during.join('\n- ')}\n\n` +
    `القرارات والمهام:\n- ${m.after.join('\n- ')}`;
}

function defaultSummary(m: FullMeeting): string {
  return `ملخص اجتماع: ${m.title} — ${m.type}. أبرز ما دار: ${m.during.join(' ، ')}. ` +
    `الخلاصة والقرارات: ${m.after.join(' ، ')}.`;
}

const LawyerMeeting: React.FC = () => {
  const toast = useToast();
  const { url } = usePage() as unknown as { url: string };
  const id = new URLSearchParams(url.split('?')[1] || '').get('id');
  const m = FULL_MEETINGS.find((x) => x.id === id) || FULL_MEETINGS[0];
  const approved = m.approve === 'معتمد';

  const [summary, setSummary] = useState(m.summary || defaultSummary(m));
  const [minutes, setMinutes] = useState(m.minutes || defaultMinutes(m));

  // يطابق mDecisionsToTasks
  const decisionsToTasks = () => {
    const n = m.after.length;
    m.after.forEach((x) => {
      TASKS.unshift({ title: `${x} (من اجتماع ${m.id})`, ref: m.id, owner: 'الفريق القانوني', due: 'خلال 3 أيام', status: 'جديدة', tone: 'b-blue' });
    });
    toast(`تم تحويل ${n} قرار إلى مهام مع تحديد المسؤول وتاريخ التنفيذ`);
  };

  return (
    <div className="detail-wrap">
      <div style={{ marginBottom: 14 }}>
        <Link href="/lawyer/meetings" className="btn soft sm">
          <Icon name="reply" /> رجوع للاجتماعات
        </Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>{m.title}</h3>
          <Badge text={m.approve} tone={approved ? 'b-green' : 'b-amber'} />
        </div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span className="chip muted">{m.type}</span>
            <span className="chip muted">{m.client}</span>
            <span className="chip muted">{m.when}</span>
            <span className="chip muted">{m.dur}</span>
            {m.caseRef && <span className="chip muted">{m.caseRef}</span>}
          </div>
          {m.participants && (
            <div style={{ marginTop: 11, fontSize: '12.5px', color: 'var(--ink)' }}>
              <b>المشاركون:</b> <span style={{ color: 'var(--muted)' }}>{m.participants}</span>
            </div>
          )}
          {m.meetLink && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
              <span style={{ direction: 'ltr', color: 'var(--primary)', fontWeight: 700, fontSize: '12.5px' }}>🔗 {m.meetLink}</span>
              <button className="btn soft sm" onClick={() => toast('تم نسخ رابط الاجتماع')} type="button">
                <Icon name="link" /> نسخ الرابط
              </button>
              <button className="btn sm" onClick={() => toast('سيتم فتح رابط الاجتماع في موعده')} type="button">
                <Icon name="video" /> دخول الاجتماع
              </button>
            </div>
          )}
        </div>
      </div>

      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>مخرجات الفريق القانوني للاجتماع (قبل/أثناء/بعد)، مع إمكانية تعديل المحضر واعتماده.</p>
      </div>

      <div className="mpanel" style={{ marginBottom: 16 }}>
        <div className="mbox">
          <div className="h">قبل الاجتماع</div>
          <ul>{m.before.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
        <div className="mbox">
          <div className="h">أثناء الاجتماع</div>
          <ul>{m.during.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
        <div className="mbox">
          <div className="h">بعد الاجتماع</div>
          <ul>{m.after.map((x, i) => <li key={i}>{x}</li>)}</ul>
        </div>
      </div>

      <div className="doc-edit" style={{ marginBottom: 8 }}>
        <div className="doc-head">
          <span className="di"><Icon name="doc" /></span>
          <b>ملخص الاجتماع</b>
          <span className="tag">{m.sumApproved ? 'معتمد' : 'مسودة'}</span>
        </div>
        <textarea value={summary} onChange={(e) => setSummary(e.target.value)} />
      </div>
      <div style={{ display: 'flex', gap: 9, margin: '10px 0 18px', flexWrap: 'wrap' }}>
        <button className="btn soft" onClick={() => toast('تم حفظ الملخص')} type="button">حفظ الملخص</button>
        {m.sumApproved && <Badge text="الملخص معتمد ومُرسل للعميل" tone="b-green" />}
      </div>

      <div className="doc-edit">
        <div className="doc-head">
          <span className="di"><Icon name="doc" /></span>
          <b>محضر الاجتماع</b>
          <span className="tag">{m.id}</span>
        </div>
        <textarea value={minutes} onChange={(e) => setMinutes(e.target.value)} />
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-h">
          <h3>القرارات والمهام</h3>
          <button className="btn soft sm" onClick={decisionsToTasks} type="button">
            <Icon name="check" /> تحويل القرارات إلى مهام
          </button>
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <ul style={{ margin: 0, paddingInlineStart: 18, lineHeight: 2 }}>
            {m.after.map((x, i) => <li key={i}>{x}</li>)}
          </ul>
        </div>
      </div>

      <div className="prot-box">
        <div className="ph"><Icon name="lock" /> حماية الاجتماع</div>
        <div className="prot-list">
          <span className="chip">منع التحميل</span>
          <span className="chip">منع النسخ</span>
          <span className="chip">منع الطباعة</span>
          <span className="chip">منع المشاركة</span>
          <span className="chip">علامة مائية ديناميكية</span>
        </div>
        <div className="audit">Audit Log · {CLIENT_NAME} · IP {OFFICE_IP} · جهاز: Chrome/Win · {todayDate()} {nowClock()}</div>
      </div>

      <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
        <button className="btn soft" onClick={() => toast('تم حفظ المحضر')} type="button">حفظ المحضر</button>
      </div>
    </div>
  );
};

export default LawyerMeeting;

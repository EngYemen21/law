import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { FULL_MEETINGS } from '@/lib/lawyer-data';

// يطابق lwMeetings في index (82).html

const openMeeting = (id: string) =>
  router.visit(`/lawyer/meeting?id=${encodeURIComponent(id)}`);

const LawyerMeetings: React.FC = () => {
  const toast = useToast();

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>الفريق القانوني يجهّز الاجتماع قبله، يوثّقه أثناءه، ويستخرج المحضر والمهام والقرارات بعده.</p>
      </div>

      {FULL_MEETINGS.map((m) => (
        <div key={m.id} className="card">
          <div className="card-h">
            <h3>{m.title}</h3>
            <span className={`badge-s ${m.approve === 'معتمد' ? 'b-green' : 'b-amber'}`}>
              <span className="d" />{m.approve}
            </span>
          </div>
          <div className="card-b" style={{ padding: '14px 18px' }}>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 13 }}>
              <span className="chip muted">{m.type}</span>
              <span className="chip muted">{m.client}</span>
              <span className="chip muted">{m.when}</span>
            </div>
            <div className="mpanel">
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
            <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
              <button className="btn sm" onClick={() => toast('سيتم فتح رابط الاجتماع في موعده')} type="button">
                <Icon name="link" /> رابط الاجتماع
              </button>
              <button className="btn soft sm" onClick={() => openMeeting(m.id)} type="button">
                <Icon name="doc" /> فتح الصفحة
              </button>
              <button className="btn soft sm" onClick={() => toast('تم فتح الملخص')} type="button">
                <Icon name="out" /> الملخص
              </button>
            </div>
          </div>
        </div>
      ))}
    </>
  );
};

export default LawyerMeetings;

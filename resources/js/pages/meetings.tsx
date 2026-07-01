import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type Meeting } from '@/lib/data';

// يطابق viewMeetings في index (82).html (نسخة دور العميل)

const MeetItem: React.FC<{ m: Meeting }> = ({ m }) => {
  const toast = useToast();
  return (
    <div className="item">
      <div className="iico"><Icon name="video" /></div>
      <div className="imeta">
        <b>{m.title}</b>
        <span>{m.when}</span>
      </div>
      <div className="iact">
        {m.up && m.link && (
          <button className="btn sm" type="button" onClick={() => toast('سيتم فتح رابط الاجتماع في موعده')}>
            <Icon name="link" /> رابط الاجتماع
          </button>
        )}
        {m.minutes && (
          <button className="btn soft sm" type="button" onClick={() => toast('تم فتح محضر الاجتماع المعتمد')}>
            <Icon name="doc" /> المحضر
          </button>
        )}
        {m.summary && (
          <button className="btn soft sm" type="button" onClick={() => toast('تم فتح ملخص الاجتماع المعتمد')}>
            <Icon name="out" /> الملخص
          </button>
        )}
      </div>
    </div>
  );
};

const Meetings: React.FC<{ meetings: Meeting[] }> = ({ meetings }) => {
  const up = meetings.filter((m) => m.up);
  const past = meetings.filter((m) => !m.up);

  return (
    <>
      <div className="card">
        <div className="card-h"><h3>الاجتماعات القادمة</h3></div>
        <div className="card-b">
          {up.length ? (
            up.map((m) => <MeetItem key={m.title} m={m} />)
          ) : (
            <div className="empty"><Icon name="video" /><b>لا اجتماعات قادمة</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>الاجتماعات السابقة</h3>
          <span className="sub">المحاضر والملخصات المعتمدة</span>
        </div>
        <div className="card-b">
          {past.map((m) => <MeetItem key={m.title} m={m} />)}
        </div>
      </div>
    </>
  );
};

export default Meetings;

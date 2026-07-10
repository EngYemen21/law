import React, { useEffect, useState } from 'react';
import Icon from '@/lib/icons';
import Modal from '@/components/babylon/Modal';
import { type Meeting } from '@/lib/data';
import { openMeeting } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';

// يطابق viewMeetings في index (82).html (نسخة دور العميل) — روابط Zoom ومحاضر/ملخصات معتمدة حقيقية
// + تحديث لحظي: يصل المحضر/الملخص وحالة الاجتماع فور اعتماد الإدارة بلا إعادة تحميل.

const Meetings: React.FC<{ meetings: Meeting[] }> = ({ meetings }) => {
  const [doc, setDoc] = useState<{ title: string; body: string } | null>(null);
  const [items, setItems] = useState<Meeting[]>(meetings);

  useEffect(() => {
    setItems(meetings);
    meetings.forEach((m) => {
      if (!m.id) return;
      echo.private(`meeting.${m.id}`).listen('.status', (e: { status: string; summary: string | null; minutes: string | null }) => {
        setItems((prev) => prev.map((x) => x.id === m.id
          ? { ...x, summary: e.summary ?? x.summary, minutes: e.minutes ?? x.minutes, up: e.status === 'قادم' || e.status === 'جارٍ' }
          : x));
      });
    });
    return () => { meetings.forEach((m) => m.id && echo.leave(`meeting.${m.id}`)); };
  }, [meetings]);

  const up = items.filter((m) => m.up);
  const past = items.filter((m) => !m.up);

  const item = (m: Meeting) => (
    <div className="item" key={m.id ?? m.title}>
      <div className="iico"><Icon name="video" /></div>
      <div className="imeta">
        <b>{m.title}</b>
        <span>{m.when}</span>
      </div>
      <div className="iact">
        {m.up && m.link && (
          <button className="btn sm" type="button" onClick={() => openMeeting(m.link)}>
            <Icon name="link" /> انضم لجلسة Zoom
          </button>
        )}
        {m.minutes && (
          <button className="btn soft sm" type="button" onClick={() => setDoc({ title: `محضر الاجتماع — ${m.title}`, body: m.minutes! })}>
            <Icon name="doc" /> المحضر
          </button>
        )}
        {m.summary && (
          <button className="btn soft sm" type="button" onClick={() => setDoc({ title: `ملخص الاجتماع — ${m.title}`, body: m.summary! })}>
            <Icon name="out" /> الملخص
          </button>
        )}
      </div>
    </div>
  );

  return (
    <>
      <div className="card">
        <div className="card-h"><h3>الاجتماعات القادمة</h3></div>
        <div className="card-b">
          {up.length ? up.map(item) : (
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
          {past.length ? past.map(item) : (
            <div className="empty"><Icon name="video" /><b>لا اجتماعات سابقة</b></div>
          )}
        </div>
      </div>

      <Modal title={doc?.title ?? ''} open={!!doc} onClose={() => setDoc(null)}>
        <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13.5px' }}>{doc?.body}</div>
      </Modal>
    </>
  );
};

export default Meetings;

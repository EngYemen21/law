import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import type {Meeting} from '@/lib/data';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';

// يطابق viewMeetings في index (82).html (نسخة دور العميل) — روابط Zoom ومحاضر/ملخصات معتمدة حقيقية
// + تحديث لحظي: يصل المحضر/الملخص وحالة الاجتماع فور اعتماد الإدارة بلا إعادة تحميل.

const Meetings: React.FC<{ meetings: Meeting[] }> = ({ meetings }) => {
  const [doc, setDoc] = useState<{ title: string; body: string } | null>(null);
  const [items, setItems] = useState<Meeting[]>(meetings);

  useEffect(() => {
    setItems(meetings);
    meetings.forEach((m) => {
      if (!m.id) {
return;
}

      // الحالة الحيّة وزر الدخول يصلان من الخادم — كان canJoin لقطة جامدة وup يُعاد اشتقاقه بمنطق ساكن بالمتصفح
      echo.private(`meeting.${m.id}`).listen('.status', (e: { status: string; liveStatus?: string; tone?: string; up?: boolean; canJoin?: boolean; summary: string | null; minutes: string | null }) => {
        setItems((prev) => prev.map((x) => x.id === m.id
          ? {
              ...x,
              summary: e.summary ?? x.summary,
              minutes: e.minutes ?? x.minutes,
              up: e.up ?? (e.status === 'قادم' || e.status === 'جارٍ'),
              status: e.liveStatus ?? x.status,
              tone: e.tone ?? x.tone,
              canJoin: e.canJoin ?? x.canJoin,
            }
          : x));
      });
    });

    return () => {
 meetings.forEach((m) => m.id && echo.leave(`meeting.${m.id}`)); 
};
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
        {/* شارة الحالة الحيّة — كان العميل بلا أي شارة فلا يفرّق منتهياً عن ملغى عن «لم ينعقد» */}
        {m.status && <Badge text={m.status} tone={m.tone || 'b-grey'} />}
        {/* المحضر/الملخص لا يظهران إلا بعد اعتماد الإدارة — الشارة توضّح ذلك للعميل */}
        {m.approved && <Badge text="معتمد" tone="b-green" />}
        {m.up && (
          m.canJoin ? (
            <button className="btn sm" type="button" onClick={() => router.visit(`/meetingroom?ref=${encodeURIComponent(m.ref)}`)}>
              <Icon name="video" /> دخول الجلسة الآن
            </button>
          ) : (
            <button className="btn sm" type="button" disabled style={{ opacity: 0.65, cursor: 'not-allowed' }} title="يُفعَّل زر الدخول قبل الموعد بـ 5 دقائق">
              <Icon name="clock" /> الدخول (يُفعَّل قبل الموعد بـ 5 د)
            </button>
          )
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

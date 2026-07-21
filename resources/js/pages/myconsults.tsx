import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import {  lawyerFirst, SummaryModal } from '@/lib/consult-ui';
import type {ConsultCard} from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';

// يطابق myConsultsView في index (82).html — استشارات العميل مع حالة الجلسة لحظياً

interface Props { consults: ConsultCard[] }

const MyConsults: React.FC<Props> = ({ consults }) => {
  const toast = useToast();
  const [items, setItems] = useState<ConsultCard[]>(consults);
  const [summaryOf, setSummaryOf] = useState<ConsultCard | null>(null);

  // بثّ لحظي: «جارية الآن» والملخص يظهران فور بدء/إنهاء المكتب للجلسة
  useEffect(() => {
    consults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: { session: string; status: string; summary: string | null; duration: string | null; canJoin?: boolean }) => {
        setItems((prev) => prev.map((x) => x.id === c.id
          ? { ...x, session: e.session, status: e.status, summary: e.summary, duration: e.duration, canJoin: e.canJoin ?? x.canJoin }
          : x));
      });
    });

    return () => {
 consults.forEach((c) => echo.leave(`consult.${c.id}`)); 
};
  }, [consults]);

  // الانضمام يفتح غرفة الجلسة المضمّنة داخل المنصّة (Zoom Meeting SDK)
  const enterRoom = (c: ConsultCard) => router.visit(`/consults/room?ref=${encodeURIComponent(c.ref)}`);

  const copyLink = (c: ConsultCard) => {
    if (navigator.clipboard) {
void navigator.clipboard.writeText(c.slink);
}

    toast('تم نسخ رابط الجلسة');
  };

  return (
    <>
      <div className="card">
        <div className="card-h">
          <h3>استشاراتي</h3>
          <span className="sub">{items.length} استشارة</span>
        </div>
        <div className="card-b">
          {items.length ? items.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
              <div className="imeta">
                <b>{c.ref} — {c.subject}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  <Badge text={c.channel} tone={crChannelTone(c.channel)} /> · {lawyerFirst(c.lawyer)} · {c.when}
                </span>
                {c.channel === 'مرئية' && c.session !== 'منتهية' && c.canJoin && (
                  <span style={{ display: 'block', direction: 'ltr', textAlign: 'right', fontSize: 11, color: 'var(--primary)', fontWeight: 700 }}>
                    🔗 {c.slink}
                  </span>
                )}
                {c.channel === 'حضورية' && (
                  <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                    <Icon name="pin" /> {c.branch}
                  </span>
                )}
              </div>
              <div className="iact">
                {/* الملغاة أولاً: كانت تسقط إلى «بانتظار الجلسة» ومعها زرّ انضمام لجلسة لن تنعقد */}
                {c.status === 'ملغاة' ? (
                  <Badge text="ملغاة" tone="b-red" />
                ) : c.session === 'جلسة جارية' ? (
                  c.channel === 'مرئية' ? (
                    <>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: '#FDEAE7', color: '#C0392B', fontWeight: 800, fontSize: 12, padding: '5px 12px', borderRadius: 99 }}>
                        <span style={{ width: 7, height: 7, borderRadius: '50%', background: '#C0392B', animation: 'vrp 1.2s infinite' }} />
                        جارية الآن
                      </span>
                      <button className="btn sm" onClick={() => enterRoom(c)} type="button" disabled={!c.canJoin}>
                        <Icon name="video" /> انضم لجلسة Zoom الآن
                      </button>
                    </>
                  ) : (
                    <Badge text="جلسة جارية" tone="b-amber" />
                  )
                ) : c.session === 'منتهية' ? (
                  c.summary ? (
                    <>
                      <Badge text="منتهية" tone="b-green" />
                      <button className="btn soft sm" onClick={() => setSummaryOf(c)} type="button">
                        <Icon name="doc" /> عرض الملخص
                      </button>
                    </>
                  ) : (
                    <Badge text="انتهت الجلسة — يُعدّ الملخص" tone="b-green" />
                  )
                ) : c.channel === 'مرئية' ? (
                  <>
                    <Badge text="بانتظار الجلسة" tone="b-grey" />
                    {c.canJoin ? (
                      <>
                        <button className="btn sm" onClick={() => enterRoom(c)} type="button">
                          <Icon name="video" /> دخول جلسة Zoom
                        </button>
                        <button className="btn soft sm" onClick={() => copyLink(c)} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      </>
                    ) : (
                      <button className="btn sm" type="button" disabled title="يُفعَّل قبل الموعد بـ5 دقائق">
                        <Icon name="clock" /> الدخول (يُفعَّل قبل الموعد بـ5 د)
                      </button>
                    )}
                  </>
                ) : c.channel === 'هاتفية' ? (
                  <Badge text="سيتصل بك المكتب في الموعد" tone="b-amber" />
                ) : (
                  <Badge text="بانتظار الجلسة" tone="b-grey" />
                )}
              </div>
            </div>
          )) : (
            <div className="empty">
              <Icon name="folder" />
              <b>لا استشارات حالية</b>
              <span>احجز استشارتك من داخل محادثة التذكرة عند وصولها لمرحلة «الرأي القانوني».</span>
            </div>
          )}
        </div>
      </div>

      <SummaryModal consult={summaryOf} onClose={() => setSummaryOf(null)} />
    </>
  );
};

export default MyConsults;

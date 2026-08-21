import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { CLIENT_CORR_FLOW, type ClientCorrCard } from '@/lib/corr-ui';
import Icon from '@/lib/icons';

interface Props { corrs: ClientCorrCard[] }

const clientTone = (s: number) => (s >= 4 ? 'b-green' : s >= 1 ? 'b-amber' : 'b-grey');

const MyCorr: React.FC<Props> = ({ corrs }) => {
  const toast = useToast();
  const [items, setItems] = useState<ClientCorrCard[]>(corrs);

  // بثّ لحظيّ: تقدّم الرحلة وصدور الإفادة
  useEffect(() => {
    corrs.forEach((c) => {
      echo.private(c.channelName).listen('.status', (e: { clientStage: number; briefed: boolean }) => {
        setItems((prev) => prev.map((x) => x.channelName === c.channelName ? { ...x, clientStage: e.clientStage, briefed: e.briefed } : x));
      });
    });
    return () => { corrs.forEach((c) => echo.leave(c.channelName)); };
  }, [corrs]);

  const requestBrief = (c: ClientCorrCard) => {
    router.post(`/correspondences/${encodeURIComponent(c.id)}/request-brief`, {}, {
      preserveScroll: true, onSuccess: () => toast('تم إرسال طلب الإفادة للمكتب'),
    });
  };

  return (
    <div className="card">
      <div className="card-h"><h3>مخاطباتي</h3><span className="sub">{items.length} مخاطبة</span></div>
      <div className="card-b">
        {items.length ? items.map((c) => (
          <div key={c.id} className="item" style={{ alignItems: 'flex-start' }}>
            <div className="iico"><Icon name="office" /></div>
            <div className="imeta" style={{ flex: 1 }}>
              <b>{c.id} — {c.subject}</b>
              <span style={{ display: 'block', margin: '3px 0' }}>
                <Badge text={c.dir} tone={c.dir === 'صادرة' ? 'b-blue' : 'b-grey'} /> · {c.entity} · {c.date}
              </span>
              <span style={{ display: 'block' }}><Badge text={CLIENT_CORR_FLOW[c.clientStage]} tone={clientTone(c.clientStage)} /></span>
              {c.briefed && (
                <div className="gov-note" style={{ marginTop: 8 }}>
                  <Icon name="info" /> <b>إفادة المكتب:</b> {c.briefNote}
                  {c.reply && <div style={{ marginTop: 6 }}><b>ردّ الجهة:</b> {c.reply}</div>}
                </div>
              )}
            </div>
            <div className="iact">
              {c.briefed ? (
                <a className="btn soft sm" href={`/correspondences/${encodeURIComponent(c.id)}/brief.pdf`} target="_blank" rel="noopener"><Icon name="download" /> طباعة الإفادة (PDF)</a>
              ) : c.briefReq ? (
                <Badge text="طُلبت الإفادة" tone="b-amber" />
              ) : (
                <button className="btn soft sm" type="button" onClick={() => requestBrief(c)}><Icon name="doc" /> طلب إفادة رسميّة</button>
              )}
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="office" /><b>لا مخاطبات</b><span>ستظهر هنا المخاطبات الرسميّة المتعلّقة بملفّاتك وإفاداتها.</span></div>
        )}
      </div>
    </div>
  );
};

export default MyCorr;

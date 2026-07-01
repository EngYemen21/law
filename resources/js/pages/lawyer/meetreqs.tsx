import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { type MeetRequest, MEET_REQUESTS, MR_FLOW, maskClient } from '@/lib/employee-data';

// يطابق meetReqsView (دور المحامي) + mrAct في index (82).html
// المحامي: عند المرحلة 1 ينفّذ الجلسة؛ غير ذلك تظهر مرحلة المسار.

const LawyerMeetReqs: React.FC = () => {
  const toast = useToast();
  const [list, setList] = useState<MeetRequest[]>(() => MEET_REQUESTS.map((r) => ({ ...r })));

  // يطابق mrAct (دور المحامي — تنفيذ الجلسة)
  const act = (id: string) => {
    setList((p) => p.map((r) => {
      if (r.id !== id || r.stage >= 3) return r;
      return { ...r, stage: r.stage + 1 };
    }));
    toast('تمت الجلسة');
  };

  const enterRoom = (r: MeetRequest) => {
    if (r.type && r.type.indexOf('مرئية') >= 0) {
      router.visit(`/lawyer/videoroom?kind=req&id=${encodeURIComponent(r.id)}`);
    } else {
      toast('سيتم فتح رابط الاجتماع في موعده');
    }
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>
          تظهر هنا الاجتماعات التي أكّد العميل حضورها لتنفيذها. <b>المسار:</b> إرسال الدعوة للعميل ← تأكيد حضور العميل ← تنفيذ الجلسة ← اعتماد الإدارة.
        </p>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>طلبات الاجتماعات</h3>
          <span className="sub">{list.length} دعوة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((r) => {
            let actNode: React.ReactNode;
            if (r.stage >= 3) {
              actNode = <Badge text="معتمد" tone="b-green" />;
            } else if (r.stage === 1) {
              actNode = (
                <button className="btn sm" onClick={() => act(r.id)} type="button">
                  <Icon name="video" /> تنفيذ الجلسة
                </button>
              );
            } else {
              actNode = <span className="chip muted">{MR_FLOW[r.stage]}</span>;
            }

            return (
              <div key={r.id} className="item">
                <div className="iico"><Icon name="video" /></div>
                <div className="imeta">
                  <b>{r.id} — {maskClient(r.client)}</b>
                  <span style={{ display: 'block', margin: '3px 0' }}>
                    {r.type} · {r.service} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}
                  </span>
                  {r.stage >= 1 && r.meetId && (
                    <span style={{ display: 'block', margin: '4px 0', fontSize: '11.5px', color: 'var(--primary)', fontWeight: 700, direction: 'ltr', textAlign: 'right' }}>
                      🔗 {r.meetLink}
                    </span>
                  )}
                  <span><FlowLine steps={MR_FLOW} cur={r.stage} /></span>
                </div>
                <div className="iact">
                  {actNode}
                  {r.stage >= 1 && r.meetId && (
                    <>
                      <button className="btn soft sm" onClick={() => toast('تم نسخ رابط الاجتماع')} type="button">
                        <Icon name="link" /> نسخ الرابط
                      </button>
                      <button className="btn sm" onClick={() => enterRoom(r)} type="button">
                        <Icon name="video" /> {r.type && r.type.indexOf('مرئية') >= 0 ? 'دخول الغرفة' : 'دخول'}
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="video" /><b>لا دعوات اجتماعات حالياً</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default LawyerMeetReqs;

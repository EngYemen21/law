import { router } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { openMeeting } from '@/lib/consult-ui';
import { MR_FLOW } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import type {MeetReqCard} from '@/lib/meeting-ui';

// دعوات الاجتماعات (دور العميل) — يستقبل دعوة المكتب ويؤكّد حضوره فتُنشأ جلسة Zoom

const MeetReqs: React.FC<{ requests: MeetReqCard[] }> = ({ requests }) => {
  const toast = useToast();

  const confirm = (r: MeetReqCard) =>
    router.post(`/meetreqs/${r.dbId}/confirm`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تأكيد حضورك — رابط الجلسة متاح الآن وفي صفحة الاجتماعات'),
    });

  const copyLink = (r: MeetReqCard) => {
    if (navigator.clipboard && r.meetLink) {
void navigator.clipboard.writeText(r.meetLink);
}

    toast('تم نسخ رابط الجلسة');
  };

  return (
    <div className="card">
      <div className="card-h">
        <h3>دعوات الاجتماعات</h3>
        <span className="sub">{requests.length} دعوة</span>
      </div>
      <div className="card-b">
        {requests.length ? requests.map((r) => (
          <div key={r.id} className="item">
            <div className="iico"><Icon name="video" /></div>
            <div className="imeta">
              <b>{r.id} — {r.service}</b>
              <span style={{ display: 'block', margin: '3px 0' }}>
                {r.type} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}
              </span>
              {r.stage >= 1 && r.meetLink && (
                <span style={{ display: 'block', margin: '4px 0', fontSize: '11.5px', color: 'var(--primary)', fontWeight: 700, direction: 'ltr', textAlign: 'right' }}>
                  🔗 {r.meetLink}
                </span>
              )}
              <span><FlowLine steps={MR_FLOW} cur={r.stage} /></span>
            </div>
            <div className="iact">
              {r.stage === 0 ? (
                <button className="btn sm" onClick={() => confirm(r)} type="button">
                  <Icon name="check" /> تأكيد الحضور
                </button>
              ) : r.stage >= 3 ? (
                <Badge text="معتمد" tone="b-green" />
              ) : (
                <Badge text={MR_FLOW[r.stage]} tone="b-blue" />
              )}
              {r.stage >= 1 && r.stage < 3 && r.meetLink && (
                <>
                  <button className="btn soft sm" onClick={() => copyLink(r)} type="button">
                    <Icon name="link" /> نسخ الرابط
                  </button>
                  {r.type.indexOf('مرئية') >= 0 && (
                    <button
                      className="btn sm"
                      onClick={() => (r.meetingRef
                        ? router.visit(`/meetingroom?ref=${encodeURIComponent(r.meetingRef)}`)
                        : openMeeting(r.meetLink || ''))}
                      type="button"
                    >
                      <Icon name="video" /> انضم لجلسة Zoom
                    </button>
                  )}
                </>
              )}
            </div>
          </div>
        )) : (
          <div className="empty">
            <Icon name="video" />
            <b>لا دعوات اجتماعات جديدة</b>
          </div>
        )}
      </div>
    </div>
  );
};

export default MeetReqs;

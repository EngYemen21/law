import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type FullMeetingCard } from '@/lib/meeting-ui';

// يطابق adMeetings + mApprove في index (82).html — الاعتماد حقيقي (يصل المحضر والملخص للعميل)

const AdminMeetings: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => {
  const toast = useToast();

  const approve = (m: FullMeetingCard) =>
    router.post(`/admin/meetings/${m.dbId}/approve`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم اعتماد الاجتماع ومحضره'),
    });

  const pending = meetings.filter((m) => m.approve !== 'معتمد').length;

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>استعرض مخرجات الفريق القانوني (قبل/أثناء/بعد) واعتمد المحضر — الاعتماد يُتيح المحضر والملخص للعميل.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>اعتماد الاجتماعات</h3><span className="sub">{pending} بانتظار الاعتماد</span></div>
        <div className="card-b">
          {meetings.length ? meetings.map((m) => (
            <div key={m.id} className="item">
              <div className="iico"><Icon name="video" /></div>
              <div className="imeta"><b>{m.title}</b><span>{m.type} · {m.when}</span></div>
              <div className="iact">
                <button className="btn soft sm" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} type="button">
                  <Icon name="out" /> فتح الصفحة
                </button>
                {m.approve === 'معتمد' ? (
                  <Badge text="معتمد" tone="b-green" />
                ) : (
                  <button className="btn sm" onClick={() => approve(m)} type="button"><Icon name="check" /> اعتماد</button>
                )}
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="video" /><b>لا اجتماعات بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminMeetings;

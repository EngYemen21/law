import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type FullMeeting, FULL_MEETINGS } from '@/lib/admin-data';

// يطابق adMeetings + mApprove في index (82).html

const AdminMeetings: React.FC = () => {
  const toast = useToast();
  const [meetings, setMeetings] = useState<FullMeeting[]>(() => FULL_MEETINGS.map((m) => ({ ...m })));

  const approve = (id: string) => {
    setMeetings((prev) => prev.map((m) => (m.id === id ? { ...m, approve: 'معتمد' } : m)));
    toast('تم اعتماد الاجتماع ومحضره');
  };

  const pending = meetings.filter((m) => m.approve !== 'معتمد').length;

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>أمثلة حيّة للاجتماعات — استعرض مخرجات الفريق القانوني (قبل/أثناء/بعد) واعتمد المحضر.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>اعتماد الاجتماعات</h3><span className="sub">{pending} بانتظار الاعتماد</span></div>
        <div className="card-b">
          {meetings.map((m) => (
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
                  <button className="btn sm" onClick={() => approve(m.id)} type="button"><Icon name="check" /> اعتماد</button>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

export default AdminMeetings;

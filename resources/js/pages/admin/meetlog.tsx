import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { type FullMeetingCard } from '@/lib/meeting-ui';

// يطابق meetLogView في index (82).html — الأرشيف حقيقي من الخادم

const PROT = ['تسجيل مرئي', 'تسجيل صوتي', 'النص الكامل', 'المحضر', 'القرارات', 'سجل الحضور', 'Audit Log'];

const AdminMeetLog: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => {
  const ended = meetings.filter((m) => m.status === 'منتهٍ');
  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>أرشيف كامل للاجتماعات المنتهية: التسجيل المرئي/الصوتي (على Zoom)، النص الكامل، المحضر، القرارات، المهام، سجل الحضور، وسجل العمليات (Audit Log).</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>سجل الاجتماعات</h3><span className="sub">{ended.length} اجتماع منتهٍ</span></div>
        <div className="card-b">
          {ended.length ? ended.map((m) => (
            <div key={m.id} className="item">
              <div className="iico"><Icon name="folder" /></div>
              <div className="imeta">
                <b>{m.title}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>{m.type} · {m.when} · الحضور {m.attend || 0}%</span>
                <div className="prot-list" style={{ marginTop: 6 }}>
                  {PROT.map((p) => <span key={p} className="chip">{p}</span>)}
                </div>
              </div>
              <div className="iact">
                <button className="btn soft sm" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} type="button">
                  <Icon name="out" /> فتح السجل
                </button>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="folder" /><b>لا اجتماعات منتهية في الأرشيف</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminMeetLog;

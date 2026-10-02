import { router } from '@inertiajs/react';
import React from 'react';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { attendanceLabel, fmtActualDuration, MeetingApprovalBadge } from '@/lib/meeting-ui';
import type {FullMeetingCard} from '@/lib/meeting-ui';
import { firstError } from '@/lib/server-message';

// يطابق adMeetings + mApprove في index (82).html — الاعتماد حقيقي (يصل المحضر والملخص للعميل)

const AdminMeetings: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => {
  const toast = useToast();

  const approve = (m: FullMeetingCard) =>
    router.post(`/admin/meetings/${m.dbId}/approve`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم اعتماد الاجتماع ومحضره'),
      // الحارس الخادمي يرفض غير المكتمل/بلا مخرجات بـ422 — بلا onError كان الفشل صامتاً تماماً
      onError: (e) => toast(firstError(e, 'الاعتماد متاح بعد انتهاء الاجتماع وتوفر الملخص أو المحضر')),
    });

  // حكم حارس الاعتماد نفسه (`canApprove`) الذي يشرط الزرّ — كان «منتهٍ غير معتمد» يعدّ ما لا مخرجات له
  const pending = meetings.filter((m) => m.canApprove).length;

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
              <div className="imeta">
                <b>{m.title}</b>
                <span>{m.type} · {m.when} · {m.client} · {m.lawyer !== '—' ? m.lawyer : 'بلا محامٍ'}</span>
                <div className="prot-list" style={{ marginTop: 5 }}>
                  <span className="chip" style={{ opacity: m.summary ? 1 : 0.5 }}>{m.summary ? '✓ ملخّص' : 'بلا ملخّص'}</span>
                  <span className="chip" style={{ opacity: m.minutes ? 1 : 0.5 }}>{m.minutes ? '✓ محضر' : 'بلا محضر'}</span>
                  <span className="chip" style={{ opacity: m.decisions.length ? 1 : 0.5 }}>{m.decisions.length ? `✓ قرارات (${m.decisions.length})` : 'بلا قرارات'}</span>
                  {m.statusKey === 'ended' && (attendanceLabel(m) || fmtActualDuration(m.durationSec)) && (
                    <span className="chip">
                      {[attendanceLabel(m), fmtActualDuration(m.durationSec)].filter(Boolean).join(' · ')}
                    </span>
                  )}
                </div>
              </div>
              <div className="iact">
                <button className="btn soft sm" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} type="button">
                  <Icon name="out" /> فتح الصفحة
                </button>
                {/* حكم حارس الاعتماد نفسه (`Meeting::approvalBlocker`) — القالبيّ ليس مخرجاً فلا زرّ يُردّ بـ٤٢٢؛
                    وما عداه شارة الخادم (`approvalState`): معتمد، أو بانتظار المحضر — ولا شيء لقادمٍ أو ملغى */}
                {m.canApprove
                  ? <button className="btn sm" onClick={() => approve(m)} type="button"><Icon name="check" /> اعتماد</button>
                  : <MeetingApprovalBadge approval={m.approval} />}
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

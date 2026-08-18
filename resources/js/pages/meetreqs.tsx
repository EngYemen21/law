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
// يطابق meetReqsView (دور العميل) — رأس greet + شريط إحصائي + بطاقات agd-c ملوّنة حسب المرحلة
// (نفس نمط execflow.tsx/correspondences.tsx المستخدَم فعلياً بالمشروع لقوائم رحلة المراحل)

// stage 4 (منتهية الصلاحية) كانت تصطبغ خضراء «معتمدة» — تناقض لوني داخل البطاقة الواحدة
const stageColor = (stage: number) => (stage >= 4 ? '#C0392B' : stage === 3 ? '#1E9D6B' : stage === 0 ? '#C0832B' : '#0E5C9C');

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

  const s0 = requests.filter((r) => r.stage === 0).length;
  const s1 = requests.filter((r) => r.stage >= 1 && r.stage < 3).length;
  // كانت stage>=3 تبتلع المنتهية الصلاحية (4) فتُعرض للعميل إنجازاً «معتمداً»
  const s3 = requests.filter((r) => r.stage === 3).length;
  const s4 = requests.filter((r) => r.stage === 4).length;

  return (
    <>
      <div className="greet">
        <h2>دعوات الاجتماعات</h2>
        <p>تصلك هنا دعوات الاجتماعات من المكتب، ويمكنك تأكيد حضورك.</p>
      </div>

      <div className="stat-strip">
        <span className="stat-pill"><span className="pd" style={{ background: '#C0832B' }} /><b>{s0}</b> بانتظار التأكيد</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#0E5C9C' }} /><b>{s1}</b> قيد التنفيذ</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#1E9D6B' }} /><b>{s3}</b> معتمدة</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#C0392B' }} /><b>{s4}</b> منتهية الصلاحية</span>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>دعوات الاجتماعات</h3>
          <span className="sub">{requests.length} دعوة</span>
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          {requests.length ? requests.map((r) => (
            <div key={r.id} className="agd-c" style={{ borderRightColor: stageColor(r.stage), marginBottom: 10 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                  <div className="mtg-t">{r.id} — {r.service}</div>
                  <div className="mtg-m">{r.type} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}</div>
                </div>
                {r.stage === 5 ? (
                  /* أُلغيت من المكتب — سجلّ تاريخي يبقى بدل الاختفاء الصامت */
                  <Badge text="أُلغيت" tone="b-red" />
                ) : r.stage === 4 ? (
                  <Badge text="منتهية الصلاحية" tone="b-red" />
                ) : r.stage === 3 ? (
                  <Badge text="معتمد" tone="b-green" />
                ) : r.stage > 0 ? (
                  <Badge text={MR_FLOW[r.stage] ?? 'قيد المعالجة'} tone="b-blue" />
                ) : (
                  <Badge text="بانتظار التأكيد" tone="b-amber" />
                )}
              </div>

              {r.stage < 4 && (
                <div style={{ margin: '10px 0' }}><FlowLine steps={MR_FLOW} cur={r.stage} /></div>
              )}
              {r.stage === 4 && (
                <div style={{ margin: '8px 0', fontSize: '12px', color: 'var(--red)' }}>
                  ⚠️ تجاوزت هذه الدعوة تاريخ موعدها دون تأكيد. يمكنك طلب موعد جديد من المكتب.
                </div>
              )}
              {r.stage === 5 && (
                <div style={{ margin: '8px 0', fontSize: '12px', color: 'var(--muted)' }}>
                  أُلغي هذا الاجتماع من المكتب. للاستفسار أو طلب موعد بديل تواصل معنا.
                </div>
              )}
              <div className="mtg-a">
                {r.stage === 0 && (
                  <button className="btn sm" onClick={() => confirm(r)} type="button">
                    <Icon name="check" /> تأكيد الحضور
                  </button>
                )}
                {/* كان stage===1 حصراً: بدء المكتب للجلسة يرفعها لـ2 فيختفي زر الدخول لحظة انعقادها */}
                {r.stage >= 1 && r.stage < 3 && (
                  r.canJoin ? (
                    <>
                      {r.meetLink && (
                        <button className="btn soft sm" onClick={() => copyLink(r)} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      )}
                      {r.type.indexOf('مرئية') >= 0 && (
                        <button
                          className="btn sm"
                          onClick={() => router.visit(`/meetingroom?ref=${encodeURIComponent(r.meetingRef || r.id)}`)}
                          type="button"
                        >
                          <Icon name="video" /> دخول الجلسة الآن
                        </button>
                      )}
                    </>
                  ) : (
                    <button className="btn sm" type="button" disabled style={{ opacity: 0.65, cursor: 'not-allowed' }} title="يُفعَّل زر الدخول قبل الموعد بـ 5 دقائق">
                      <Icon name="clock" /> الدخول (يُفعَّل قبل الموعد بـ 5 د)
                    </button>
                  )
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
    </>
  );
};

export default MeetReqs;

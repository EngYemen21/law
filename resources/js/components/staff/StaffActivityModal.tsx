import axios from 'axios';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import LawyerFileModal, { CAPACITY } from '@/components/babylon/LawyerFileModal';
import type { LawyerLoad } from '@/components/babylon/LawyerFileModal';
import Modal from '@/components/babylon/Modal';
import { sar } from '@/components/earnings/EarningsView';
import Icon from '@/lib/icons';

/** صفّ الموظّف كما يصل من `User::staffCard()` — ما تحتاجه الترويسة وحدها. */
export interface ActivityStaff {
  id: number;
  name: string;
  role: string;
  roleKey?: string;
  status: string;
  active?: boolean;
  email: string;
  mobile: string;
}

interface AuditRow {
  id: string;
  action: string;
  description: string;
  ref: string | null;
  by: string;
  severity: string;
  at: string | null;
}

/** من `App\Support\Staff\StaffActivity::for` — الحِمل والمستحقّات `null` للإدارة. */
interface Activity {
  workload: LawyerLoad | null;
  earnings: { monthLabel: string; monthEarned: number; monthPaid: number; balance: number } | null;
  actions: AuditRow[];
  account: AuditRow[];
}

/** عامّة على صفّ الصفحة — فتعود الإجراءات بالصفّ نفسه بلا تحويل نوع. */
interface Props<T extends ActivityStaff> {
  staff: T | null;
  onClose: () => void;
  onEdit: (s: T) => void;
  onToggle: (s: T) => void;
  onPayouts: (s: T) => void;
}

const Tile: React.FC<{ label: string; value: React.ReactNode; tone?: string }> = ({ label, value, tone }) => (
  <div className="lw-group">
    <span className="sub" style={{ display: 'block', fontSize: 11.5 }}>{label}</span>
    <b style={{ fontSize: 16, color: tone }}>{value}</b>
  </div>
);

const History: React.FC<{ title: string; rows: AuditRow[]; empty: string; showActor?: boolean }> = ({ title, rows, empty, showActor }) => (
  <div className="lw-group">
    <div className="lw-group-h">
      <b>{title}</b>
      <Badge text={String(rows.length)} tone={rows.length > 0 ? 'b-blue' : 'b-grey'} />
    </div>
    {rows.length > 0 ? (
      <ul className="lw-items">
        {rows.map((r) => (
          <li key={r.id}>
            <b>{r.action}</b>
            <span className="sub" dir="ltr" style={{ gridColumn: 'auto', textAlign: 'end' }}>{r.at ?? '—'}</span>
            <span className="sub">
              {showActor && <>بواسطة {r.by} · </>}
              {r.ref && <>{r.ref} · </>}
              {r.description}
              {r.severity !== 'info' && <> <Badge text="حسّاس" tone="b-amber" /></>}
            </span>
          </li>
        ))}
      </ul>
    ) : (
      <div className="sub">{empty}</div>
    )}
  </div>
);

/** المحتوى المحمَّل — حالته تبدأ فارغةً مع كلّ تركيب. */
const ActivityBody: React.FC<{ staffId: number; isLawyer: boolean; onOpenFiles: () => void }> = ({ staffId, isLawyer, onOpenFiles }) => {
  const [data, setData] = useState<Activity | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    axios.get<Activity>(`/admin/staff/${staffId}/activity`)
      .then((r) => alive && setData(r.data))
      .catch(() => alive && setError('تعذّر تحميل ملفّ النشاط'));

    return () => {
      alive = false;
    };
  }, [staffId]);

  const load = data?.workload;

  return (
    <>
      {error && <div className="empty"><Icon name="alert" /><b>{error}</b></div>}
      {!error && !data && <div className="sub">جارٍ التحميل…</div>}

      {data && (
        <>
          {load && (
            <section>
              <div className="lw-group-h">
                <b>الحِمل الحاليّ</b>
                {isLawyer && (
                  <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
                    <Badge text={CAPACITY[load.capacity].label} tone={CAPACITY[load.capacity].tone} />
                    <button className="btn soft sm" type="button" onClick={onOpenFiles}>
                      <Icon name="folder" /> ملفّاته المفتوحة
                    </button>
                  </span>
                )}
              </div>
              {/* ستّ خاناتٍ للمحامي في صفٍّ واحد على الحاسوب */}
              <div className="staff-metrics-grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(105px, 1fr))' }}>
                {isLawyer && (
                  <>
                    <Tile label="تذاكر مفتوحة" value={load.tickets} />
                    <Tile label="قضايا نشطة" value={load.cases} />
                    <Tile label="ملفّات تنفيذ" value={load.executions} />
                    <Tile label="استشارات" value={load.consults} />
                  </>
                )}
                <Tile label="مهامّ متأخّرة" value={load.overdueTasks} tone={load.overdueTasks > 0 ? 'var(--danger, #C0392B)' : undefined} />
                <Tile label="اجتماعات قادمة" value={load.upcomingMeetings} />
              </div>
            </section>
          )}

          {data.earnings && (
            <section>
              <div className="lw-group-h"><b>المستحقّات</b></div>
              <div className="staff-metrics-grid">
                <Tile label={`مستحقّ ${data.earnings.monthLabel}`} value={sar(data.earnings.monthEarned)} />
                <Tile label="المصروف عن الشهر" value={sar(data.earnings.monthPaid)} />
                <Tile label="الرصيد المتبقّي" value={sar(data.earnings.balance)} />
              </div>
            </section>
          )}

          <div className="staff-info-grid">
            <History title="آخر عمليّاته" rows={data.actions} empty="لا عمليّات مسجّلة بعد" />
            <History title="سجلّ الحساب" rows={data.account} empty="لا تغييرات مسجّلة على الحساب" showActor />
          </div>
        </>
      )}
    </>
  );
};

/**
 * **ملفّ نشاط الموظّف** (قرار المالك 2026-10-01) — حلّ محلّ «الملف الوظيفي» الذي كان يكرّر ما في
 * الجدول ونموذج التعديل (القسم، والأجر، والهويّة، ومصفوفة الصلاحيّات). يعرض ما لا يُرى في غيره:
 * الحِمل الحاليّ، ومستحقّات الشهر، وآخر عمليّاته، وما أُجري على حسابه. البيانات من
 * `GET /admin/staff/{id}/activity` تُحمَّل عند الفتح.
 */
function StaffActivityModal<T extends ActivityStaff>({ staff, onClose, onEdit, onToggle, onPayouts }: Props<T>): React.ReactElement {
  const [openFiles, setOpenFiles] = useState(false);
  const staffId = staff?.id;

  const isLawyer = staff?.roleKey === 'lawyer';
  const isAdmin = staff?.roleKey === 'admin';

  return (
    <>
      <Modal
        title={staff ? `ملفّ النشاط — ${staff.name}` : ''}
        subtitle="الحِمل الحاليّ، ومستحقّات الشهر، وآخر العمليّات وما أُجري على الحساب"
        open={!!staff}
        onClose={onClose}
        maxWidth={820}
      >
        {staff && (
          <div className="staff-dossier">
            <div className="staff-dossier-hero">
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                  <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800 }}>{staff.name}</h3>
                  <Badge text={staff.status} tone={staff.active ? 'b-green' : 'b-grey'} />
                </div>
                <div className="sub" style={{ marginTop: 4 }}>
                  {staff.role} · <span dir="ltr">{staff.mobile}</span> · <span dir="ltr">{staff.email}</span>
                </div>
              </div>
              <div className="staff-hero-actions">
                <button className="btn sm" type="button" onClick={() => onEdit(staff)}>
                  <Icon name="doc" /> تعديل البيانات
                </button>
                {!isAdmin && (
                  <>
                    <button className="btn sm soft" type="button" onClick={() => onPayouts(staff)}>
                      <Icon name="card" /> المستحقّات والصرف
                    </button>
                    <button
                      className="btn sm soft"
                      type="button"
                      onClick={() => onToggle(staff)}
                      style={{ color: staff.active ? 'var(--red, #ef4444)' : 'var(--green, #10b981)' }}
                    >
                      {staff.active ? <><Icon name="lock" /> إيقاف الحساب</> : <><Icon name="check" /> تفعيل الحساب</>}
                    </button>
                  </>
                )}
              </div>
            </div>

            {/* يُركَّب من جديد لكلّ موظّف ولكلّ إيقاف/تفعيل — فيُعاد التحميل بعدهما بلا تصفيرٍ يدويّ */}
            <ActivityBody key={`${staff.id}-${String(staff.active)}`} staffId={staff.id} isLawyer={isLawyer} onOpenFiles={() => setOpenFiles(true)} />
          </div>
        )}
      </Modal>
      {openFiles && staffId !== undefined && <LawyerFileModal key={staffId} lawyerId={staffId} onClose={() => setOpenFiles(false)} />}
    </>
  );
}

export default StaffActivityModal;

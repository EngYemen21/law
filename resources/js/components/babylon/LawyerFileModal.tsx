import { Link } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

/** حِمل المحامي — من `LawyerWorkload::forMany`. */
export interface LawyerLoad {
  tickets: number;
  cases: number;
  executions: number;
  consults: number;
  total: number;
  capacity: 'available' | 'moderate' | 'busy';
  overdueTasks: number;
  upcomingMeetings: number;
}

interface FileItem { ref: string; title: string | null; status: string; href: string }
interface FileGroup { key: string; label: string; items: FileItem[] }
interface LawyerFile {
  name: string;
  groups: FileGroup[];
  links: { staff: string; earnings: string; activity: string };
}

export const CAPACITY: Record<LawyerLoad['capacity'], { label: string; tone: string }> = {
  available: { label: 'متاح', tone: 'b-green' },
  moderate: { label: 'متوسّط الحِمل', tone: 'b-amber' },
  busy: { label: 'مشغول', tone: 'b-red' },
};

/**
 * **ملفّ المحامي المفتوح** (تطوير صفحة «المحامون» 2026-09-29) — أعماله المفتوحة بأنواعها مع رابط كلٍّ منها،
 * واجتماعاته القادمة ومهامه المتأخّرة، وروابط ما له صفحته: التعديل والأقسام («فريق العمل»)، والمستحقّات،
 * وسجلّ نشاطه في سجلّ الرحلة. البيانات من `Admin\LawyerController::show`، وتُعرض ما دامت مركّبة.
 */
const LawyerFileModal: React.FC<{ lawyerId: number; onClose: () => void }> = ({ lawyerId, onClose }) => {
  const [file, setFile] = useState<LawyerFile | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // تُركَّب النافذة من جديد لكلّ محامٍ (`key` في الصفحة)، فحالتها تبدأ فارغةً بلا تصفير هنا
    let alive = true;
    axios.get(`/admin/lawyers/${lawyerId}`)
      .then((r) => alive && setFile(r.data))
      .catch(() => alive && setError('تعذّر تحميل ملفّ المحامي'));

    return () => {
      alive = false;
    };
  }, [lawyerId]);

  return (
    <Modal title={file ? `ملفّ المحامي — ${file.name}` : 'ملفّ المحامي'} open onClose={onClose} maxWidth={860}>
      {error && <div className="empty"><Icon name="alert" /><b>{error}</b></div>}
      {!error && !file && <div className="sub">جارٍ التحميل…</div>}
      {file && (
        <>
          <div className="lw-links">
            <Link href={file.links.staff} className="btn soft sm"><Icon name="user" /> تعديل البيانات والأقسام</Link>
            <Link href={file.links.earnings} className="btn soft sm"><Icon name="card" /> المستحقّات</Link>
            <Link href={file.links.activity} className="btn soft sm"><Icon name="clock" /> سجلّ نشاطه</Link>
          </div>
          <div className="lw-groups">
            {file.groups.map((g) => (
              <div key={g.key} className="lw-group">
                <div className="lw-group-h">
                  <b>{g.label}</b>
                  <Badge text={String(g.items.length)} tone={g.items.length > 0 ? (g.key === 'overdueTasks' ? 'b-red' : 'b-blue') : 'b-grey'} />
                </div>
                {g.items.length > 0 ? (
                  <ul className="lw-items">
                    {g.items.map((it) => (
                      <li key={`${g.key}-${it.ref}-${it.title}`}>
                        <Link href={it.href}><b>{it.ref}</b></Link>
                        <span className="lw-title">{it.title || '—'}</span>
                        <span className="sub">{it.status}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <div className="sub">لا شيء</div>
                )}
              </div>
            ))}
          </div>
        </>
      )}
    </Modal>
  );
};

export default LawyerFileModal;

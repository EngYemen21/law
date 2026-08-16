import { Link } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { nowClock, todayDate } from '@/lib/chat';

// يطابق renderDetail/tflow/tf-grid/tf-aside في index (82).html

interface DetailShellProps {
  backHref: string;
  backLabel: string;
  title: string;
  no: string;
  status: string;
  tone: string;
  info: [string, string][];
  topExtra?: React.ReactNode;
  // ملصق بطاقة الجانب (tc-top) — التذكرة تمرّر «التذكرة» لأن العنوان «محادثة التذكرة…» يجعل الاشتقاق خاطئاً
  noLabel?: string;
  children: React.ReactNode; // الثريد + المُحرِّر
}

const DetailShell: React.FC<DetailShellProps> = ({
  backHref, backLabel, title, no, status, tone, info, topExtra, noLabel, children,
}) => (
  <div className="tflow">
    <div style={{ marginBottom: 14 }}>
      <Link href={backHref} className="btn soft sm">
        <Icon name="reply" /> {backLabel}
      </Link>
    </div>

    {topExtra}

    <div className="tf-grid">
      <div>
        <div className="card">
          <div className="card-h">
            <h3>{title}</h3>
            <Badge text={status} tone={tone} />
          </div>
          {children}
        </div>
      </div>

      <aside className="tf-aside">
        <div className="card">
          <div className="tc-top">
            <div className="lbl">{noLabel ?? title.split(' ')[0]}</div>
            <div className="num">{no}</div>
          </div>
          <div className="tc-body">
            {info.map(([k, v]) => (
              <div key={k} className="tc-row">
                <span className="k">{k}</span>
                <span className="v">{v}</span>
              </div>
            ))}
            {/* حُذف صفّ «عنوان IP» — كان ثابتاً وهمياً */}
            <div className="tc-row">
              <span className="k">التاريخ</span>
              <span className="v">{todayDate()}</span>
            </div>
            <div className="tc-row">
              <span className="k">وقت العميل</span>
              <span className="v">{nowClock()}</span>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </div>
);

export default DetailShell;

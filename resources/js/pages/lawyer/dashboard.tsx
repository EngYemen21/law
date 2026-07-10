import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';

// لوحة المحامي — التذاكر المحالة وملخصاتها (بيانات حقيقية من الخادم)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: EmpTicket[]; pendingSummaries: number; todayMeetings: number; openTasks: number; }

const studyTicket = (no: string) => router.visit(`/lawyer/summary/${encodeURIComponent(no)}`);

const LawyerDashboard: React.FC<Props> = ({ tickets, pendingSummaries, todayMeetings, openTasks }) => {
  const stats: StatItem[] = [
    ['t-blue', 'folder', tickets.length, 'تذاكر محالة إليّ'],
    ['t-cyan', 'video', todayMeetings, 'اجتماعات قادمة'],
    ['t-amber', 'doc', pendingSummaries, 'ملخصات بانتظار اعتمادي'],
    ['t-green', 'exec', openTasks, 'مهام مفتوحة'],
  ];

  return (
    <>
      <div className="greet">
        <h2>لوحة المحامي</h2>
        <p>تذاكرك المحالة، اجتماعاتك، المساعد القانوني الذكي، والملخصات والمهام.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h"><h3>التذاكر المحالة إليّ</h3></div>
        <div className="card-b t-wrap">
          {tickets.length ? (
            <table className="tbl">
              <thead>
                <tr>
                  <th>التذكرة</th>
                  <th>العميل</th>
                  <th>القسم</th>
                  <th>الحالة</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((t) => (
                  <tr key={t.no} className="click" onClick={() => studyTicket(t.no)}>
                    <td className="mono">{t.no}</td>
                    <td>{t.client}</td>
                    <td className="muted">{t.dept}</td>
                    <td><Badge text={t.status} tone={t.tone} /></td>
                    <td>
                      <button className="btn soft sm" onClick={(e) => { e.stopPropagation(); studyTicket(t.no); }} type="button">
                        <Icon name="scale" /> دراسة
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="folder" /><b>لا تذاكر محالة إليك بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default LawyerDashboard;

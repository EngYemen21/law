import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';

// لوحة المحامي — التذاكر المحالة وملخصاتها (بيانات حقيقية من الخادم)

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }
interface Props { tickets: EmpTicket[]; pendingSummaries: number; openMeetings: number; openTasks: number; overdueTasks?: number; }

const studyTicket = (no: string) => router.visit(`/lawyer/summary/${encodeURIComponent(no)}`);

const LawyerDashboard: React.FC<Props> = ({ tickets, pendingSummaries, openMeetings, openTasks, overdueTasks = 0 }) => {
  const stats: StatItem[] = [
    ['t-blue', 'folder', tickets.length, 'تذاكر محالة إليّ'],
    ['t-cyan', 'video', openMeetings, 'اجتماعات قادمة'],
    ['t-amber', 'doc', pendingSummaries, 'ملخصات بانتظار اعتمادي'],
    ['t-green', 'exec', openTasks, 'مهام مفتوحة'],
    ['t-red', 'clock', overdueTasks, 'مهام متأخرة'],
  ];

  return (
    <>
      <div className="hero">
        <h2>لوحة المحامي والمستشار القانوني ⚖️</h2>
        <p>تذاكرك المحالة، اجتماعاتك، المساعد القانوني الذكي، والملخصات والمهام الموكلة إليك.</p>
        <div className="hero-cta">
          <button className="hero-b" onClick={() => router.visit('/lawyer/assistant')} type="button">
            <Icon name="sparkles" /> المساعد الذكي
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/lawyer/meetings')} type="button">
            <Icon name="video" /> الاجتماعات
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/lawyer/summaries')} type="button">
            <Icon name="doc" /> الملخصات
          </button>
        </div>
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

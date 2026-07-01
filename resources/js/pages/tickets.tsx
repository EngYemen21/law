import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { VIEW_ROUTE } from '@/lib/data';

// يطابق viewTickets في index (82).html — البيانات من قاعدة البيانات

interface TicketCard { no: string; type: string; status: string; tone: string; last: string; date: string; }

const Tickets: React.FC<{ tickets: TicketCard[] }> = ({ tickets }) => (
  <div className="card">
    <div className="card-h">
      <h3>جميع التذاكر</h3>
      <button
        className="btn sm"
        onClick={() => router.visit(VIEW_ROUTE.newticket)}
        type="button"
      >
        <Icon name="plus" /> تذكرة جديدة
      </button>
    </div>
    <div className="card-b t-wrap">
      <table className="tbl">
        <thead>
          <tr>
            <th>رقم التذكرة</th>
            <th>النوع</th>
            <th>الحالة</th>
            <th>آخر رد</th>
            <th>التحديث</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {tickets.map((t) => (
            <tr
              key={t.no}
              className="click"
              onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)}
            >
              <td className="mono">{t.no}</td>
              <td>{t.type}</td>
              <td><Badge text={t.status} tone={t.tone} /></td>
              <td className="last muted">{t.last}</td>
              <td className="muted">{t.date}</td>
              <td>
                <button className="btn soft sm" type="button">
                  <Icon name="reply" /> فتح المحادثة
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  </div>
);

export default Tickets;

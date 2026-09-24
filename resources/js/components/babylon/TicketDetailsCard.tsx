import React from 'react';

// بطاقة «تفاصيل الطلب» — نقل حرفي من tkDetailsCard المرجعي (babel-system.html:10161-10175):
// موضوع التذكرة / القسم / الخدمة / رقم الجوال / الأهمية، بحدّ جانبي أزرق مميّز.
// تظهر لطاقم المكتب (موظف/محامٍ/إدارة) فوق عمود المحادثة، ولا تُعرض إن غابت كل القيم.

interface Props {
  subject?: string | null;
  dept?: string | null;
  service?: string | null; // نوع الخدمة — يُخفى إن ساوى القسم (شرط المرجع)
  mobile?: string | null;
  priority?: string | null;
}

const rowStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'flex-start',
  gap: '12px',
  padding: '7px 0',
  borderBottom: '1px solid #eef1f4',
};
const keyStyle: React.CSSProperties = { color: '#667', fontSize: 13, flexShrink: 0, whiteSpace: 'nowrap' };
const valStyle: React.CSSProperties = {
  fontWeight: 600,
  fontSize: 13,
  textAlign: 'left',
  wordBreak: 'break-word',
  overflowWrap: 'break-word',
  minWidth: 0,
};

const TicketDetailsCard: React.FC<Props> = ({ subject, dept, service, mobile, priority }) => {
  const rows: [string, string][] = [];
  if (subject) rows.push(['موضوع التذكرة', subject]);
  if (dept) rows.push(['القسم', dept]);
  if (service && service !== dept) rows.push(['الخدمة', service]);
  if (mobile) rows.push(['رقم الجوال', mobile]);
  if (priority) rows.push(['الأهمية', priority]);

  if (!rows.length) return null;

  return (
    <div className="card" style={{ marginBottom: 12, borderInlineStart: '3px solid #0E5C9C' }}>
      <div className="card-h"><h3>تفاصيل الطلب</h3></div>
      <div className="card-b" style={{ padding: '12px 16px' }}>
        {rows.map(([k, v], i) => (
          <div key={k} className="tc-row" style={i === rows.length - 1 ? { ...rowStyle, borderBottom: 'none' } : rowStyle}>
            <span style={keyStyle}>{k}</span>
            <span style={k === 'رقم الجوال' ? { ...valStyle, direction: 'ltr' } : valStyle}>{v}</span>
          </div>
        ))}
      </div>
    </div>
  );
};

export default TicketDetailsCard;

import React from 'react';
import Icon from '@/lib/icons';

// أرشيف الاستشارات المنتهية — بيانات حقيقية (تسجيلات Zoom وملخصات)

interface Row { ref: string; ctype: string; client: string; date: string; dur: string; recording: string | null; hasSummary: boolean; }
interface Props { rows: Row[]; }

const AdminArchive: React.FC<Props> = ({ rows }) => (
  <>
    <div className="ai-banner">
      <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
      <p>أرشيف تسجيلات وملخصات الاستشارات المنتهية — <b>متاح للإدارة العليا فقط</b>.</p>
    </div>
    <div className="card">
      <div className="card-h">
        <h3>أرشيف الاستشارات</h3>
        <span className="sub">{rows.length}</span>
      </div>
      <div className="card-b t-wrap">
        {rows.length ? (
          <table className="tbl">
            <thead>
              <tr><th>المرجع</th><th>النوع</th><th>العميل</th><th>التاريخ</th><th>المدة</th><th></th></tr>
            </thead>
            <tbody>
              {rows.map((a) => (
                <tr key={a.ref}>
                  <td className="mono">{a.ref}</td>
                  <td>{a.ctype}</td>
                  <td>{a.client}</td>
                  <td className="muted">{a.date}</td>
                  <td className="mono">{a.dur}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      {a.recording
                        ? <a className="btn soft sm" href={a.recording} target="_blank" rel="noopener noreferrer"><Icon name="video" /> التسجيل</a>
                        : <span className="chip muted">لا تسجيل</span>}
                      {a.hasSummary && <span className="chip">ملخص متاح</span>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty"><Icon name="video" /><b>لا استشارات منتهية في الأرشيف بعد</b></div>
        )}
      </div>
    </div>
  </>
);

export default AdminArchive;

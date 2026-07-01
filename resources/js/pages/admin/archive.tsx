import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { ARCHIVE } from '@/lib/admin-data';

// يطابق adArchive في index (82).html

const AdminArchive: React.FC = () => {
  const toast = useToast();
  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>أرشيف تسجيلات وملخصات الاستشارات — <b>متاح للإدارة العليا فقط</b> مع حفظها داخل التذاكر.</p>
      </div>
      <div className="card">
        <div className="card-h">
          <h3>أرشيف التسجيلات والاستشارات</h3>
          <span className="lock-badge"><Icon name="lock" /> الإدارة العليا</span>
        </div>
        <div className="card-b t-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>المرجع</th>
                <th>نوع الاستشارة</th>
                <th>العميل</th>
                <th>التاريخ</th>
                <th>المدة</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {ARCHIVE.map((a) => (
                <tr key={a.ref}>
                  <td className="mono">{a.ref}</td>
                  <td>{a.ctype}</td>
                  <td>{a.client}</td>
                  <td className="muted">{a.date}</td>
                  <td className="mono">{a.dur}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6 }}>
                      <button className="btn soft sm" onClick={() => toast('جارٍ تشغيل التسجيل')} type="button">
                        <Icon name="video" /> التسجيل
                      </button>
                      <button className="btn soft sm" onClick={() => toast('فتح ملخص الاستشارة')} type="button">
                        <Icon name="doc" /> قراءة/تعديل الملخص
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
};

export default AdminArchive;

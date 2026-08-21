import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';

// لوحة الإدارة — نظرة شاملة حقيقية من الخادم

const fmt = (n: number) => n.toLocaleString('en-US');

interface Props {
  stats: { clients: number; openTickets: number; revenue: number; pendingMeetings: number };
  activity: { ico: string; title: string; sub: string }[];
}

const AdminDashboard: React.FC<Props> = ({ stats, activity }) => {
  const toast = useToast();
  const [resetOpen, setResetOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const items: StatItem[] = [
    ['t-blue', 'user', stats.clients, 'العملاء'],
    ['t-cyan', 'folder', stats.openTickets, 'تذاكر مفتوحة'],
    ['t-green', 'card', fmt(stats.revenue), 'الإيراد المحصّل (ر.س)'],
    ['t-amber', 'video', stats.pendingMeetings, 'اجتماعات بانتظار الاعتماد'],
  ];

  const handleResetDatabase = () => {
    setBusy(true);
    router.post(
      '/admin/reset-database',
      { confirm: 'RESET' },
      {
        onSuccess: () => {
          setResetOpen(false);
          toast('✅ تم تصفير جميع بيانات الاختبار بنجاح مع الاحتفاظ بالمستخدمين');
        },
        onError: () => {
          toast('⚠️ تعذّر تصفير قاعدة البيانات، يرجى المحاولة لاحقاً');
        },
        onFinish: () => setBusy(false),
      }
    );
  };

  return (
    <>
      <div className="hero">
        <h2>لوحة الإدارة العليا والتحكم العام 🏛️</h2>
        <p>نظرة شاملة ومباشرة على العملاء، التذاكر، الإيرادات المالية، والاعتمادات الإدارية.</p>
        <div className="hero-cta" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button className="hero-b" onClick={() => router.visit('/admin/distribute')} type="button">
            <Icon name="reply" /> توزيع المهام
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/admin/accounting')} type="button">
            <Icon name="card" /> التقارير المالية
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/admin/staff')} type="button">
            <Icon name="user" /> إدارة الطاقم
          </button>
          {/* زر تصفير بيانات الاختبار — مؤقت لبيئة الاختبار */}
          <button
            className="hero-b"
            onClick={() => setResetOpen(true)}
            type="button"
            style={{
              backgroundColor: '#b91c1c',
              borderColor: '#991b1b',
              color: '#ffffff',
            }}
          >
            <Icon name="trash" /> تصفير بيانات الاختبار
          </button>
        </div>
      </div>

      <StatRow items={items} />

      {/* قسم أدوات الاختبار الميداني */}
      <div
        className="card"
        style={{
          marginBottom: 16,
          border: '1px dashed #ef4444',
          backgroundColor: '#fef2f2',
        }}
      >
        <div
          className="card-h"
          style={{
            borderBottomColor: '#fecaca',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#991b1b' }}>
            <Icon name="alert" />
            <h3 style={{ color: '#991b1b', margin: 0 }}>أداة تصفير بيانات الاختبار (مؤقتة)</h3>
          </div>
          <span style={{ fontSize: 12, color: '#b91c1c', fontWeight: 600 }}>إشراف الإدارة</span>
        </div>
        <div
          className="card-b"
          style={{
            padding: '14px 18px',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: 12,
          }}
        >
          <p style={{ margin: 0, fontSize: 13, color: '#7f1d1d', maxWidth: 650 }}>
            يتيح هذا الخيار حذف كافة البيانات التجريبية (التذاكر، القضايا، التنفيذ، الاستشارات، الفواتير، والاجتماعات)
            لإعادة الاختبار من نقطة الصفر، مع <strong>الاحتفاظ الكامل بحسابات المستخدمين وصلاحياتهم</strong>.
          </p>
          <button
            className="btn sm"
            type="button"
            onClick={() => setResetOpen(true)}
            style={{
              backgroundColor: '#dc2626',
              borderColor: '#b91c1c',
              color: '#fff',
            }}
          >
            <Icon name="trash" /> تصفير البيانات الآن
          </button>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>أحدث النشاط</h3></div>
        <div className="card-b">
          {activity.length ? activity.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name={a.ico} /></div>
              <div className="imeta"><b>{a.title}</b><span>{a.sub}</span></div>
            </div>
          )) : (
            <div className="empty"><Icon name="folder" /><b>لا نشاط بعد</b></div>
          )}
        </div>
      </div>

      {/* مودال تأكيد تصفير البيانات */}
      <Modal
        title="تأكيد تصفير بيانات الاختبار"
        subtitle="إجراء إداري لحذف البيانات التشغيلية"
        open={resetOpen}
        onClose={() => !busy && setResetOpen(false)}
        maxWidth={520}
      >
        <div style={{ padding: '8px 4px' }}>
          <div
            style={{
              display: 'flex',
              alignItems: 'flex-start',
              gap: 12,
              padding: '12px 14px',
              borderRadius: 8,
              backgroundColor: '#fef2f2',
              border: '1px solid #fee2e2',
              color: '#991b1b',
              marginBottom: 16,
            }}
          >
            <div style={{ flexShrink: 0, marginTop: 2 }}>
              <Icon name="alert" />
            </div>
            <div style={{ fontSize: 13, lineHeight: 1.6 }}>
              <strong>تحذير:</strong> هذا الإجراء سيقوم بحذف وإفراغ جميع الجداول التالية نهائياً:
              <ul style={{ margin: '6px 0 0', paddingRight: 20 }}>
                <li>التذاكر ومحادثاتها ومستنداتها والملخصات.</li>
                <li>القضايا والجلسات ومذكراتها وفواتيرها.</li>
                <li>ملفات التنفيذ وإجراءاتها والمخاطبات.</li>
                <li>الاستشارات ومواعيدها والاجتماعات والمهام.</li>
              </ul>
              <div style={{ marginTop: 8, color: '#15803d', fontWeight: 700 }}>
                ✓ سيتم الإبقاء على جدول المستخدمين (Users)، والأدوار والصلاحيات.
              </div>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
            <button
              className="btn soft"
              type="button"
              disabled={busy}
              onClick={() => setResetOpen(false)}
            >
              إلغاء
            </button>
            <button
              className="btn"
              type="button"
              disabled={busy}
              onClick={handleResetDatabase}
              style={{
                backgroundColor: '#dc2626',
                borderColor: '#b91c1c',
                color: '#fff',
              }}
            >
              <Icon name="trash" /> {busy ? 'جاري التصفير…' : 'تأكيد الحذف وتصفير البيانات'}
            </button>
          </div>
        </div>
      </Modal>
    </>
  );
};

export default AdminDashboard;


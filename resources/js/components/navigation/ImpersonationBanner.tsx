import { router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';

// ⚠️ غير مستخدم — أُلغيت ميزة «معاينة اللوحة» (الإمبرسنيشن) بقرار المستخدم 2026-08-28:
// الإدارة العليا مقصورة على لوحتها. المكوّن لم يعد يُستورد في AppLayout، والخادم لم يعد
// يشارك prop باسم impersonating. يبقى الملف توثيقًا (الكود الميت يُعلَّق لا يُحذف).
// لافتة معاينة لوحة الموظف — كانت تظهر للإدارة أثناء معاينة صلاحيات موظف

interface Shared { impersonating?: { name: string } | null }

const ImpersonationBanner: React.FC = () => {
  const { props } = usePage() as unknown as { props: Shared };
  const imp = props.impersonating;

  if (!imp) {
return null;
}

  return (
    <div
      style={{
        display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap',
        background: '#FBF1E0', borderBottom: '1px solid #F0DDB0', color: '#8a6d2f',
        padding: '9px 16px', fontSize: 13, fontWeight: 700,
      }}
    >
      <Icon name="user" />
      <span>معاينة لوحة: {imp.name} — تُعرض بصلاحيات هذا الموظف</span>
      <button
        className="btn sm"
        type="button"
        style={{ marginInlineStart: 'auto' }}
        onClick={() => router.post('/impersonate/leave')}
      >
        <Icon name="reply" /> إنهاء المعاينة
      </button>
    </div>
  );
};

export default ImpersonationBanner;

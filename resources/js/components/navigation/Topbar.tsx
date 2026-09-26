import { router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { ROLE_TITLES, panelBase, panelRole } from '@/lib/data';

import NotificationDropdown from '@/components/navigation/NotificationDropdown';

interface TopbarProps {
  onMenuToggle: () => void;
}

const Topbar: React.FC<TopbarProps> = ({ onMenuToggle }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const path = (url as string).split('?')[0];
  // الصفحات المشتركة تُنسب للوحة دور المستخدم الفعليّ (اتّساقاً مع الشريط الجانبيّ).
  const role = panelRole(path, user?.role);
  const titles = ROLE_TITLES[role] ?? ROLE_TITLES.client;
  // مطابقة مباشرة، مع احتياط للمسارات الديناميكية
  let [title, crumb] = titles[path] ?? ['الرئيسية', 'منصة العميل'];
  // هل وُجد للمسار عنوانٌ (مباشرةً أو بنمطٍ أدناه)؟ — علمٌ لا مقارنةٌ بنصّ العنوان
  let resolved = Boolean(titles[path]);
  // محادثة التذكرة في كل اللوحات: /tickets/{no} و/(employee|lawyer|admin)/tickets/{no}
  // كان النمط مثبّتاً ببداية «/tickets» فتظهر لوحات الموظف والمستشار والإدارة بعنوان «الرئيسية / منصة العميل»
  if (!titles[path] && /^(?:\/employee|\/lawyer|\/admin)?\/tickets\/[^/]+$/.test(path) && path !== '/tickets/new') {
    resolved = true;

    [title, crumb] = ['محادثة التذكرة', titles[`${path.split('/tickets')[0]}/tickets`]?.[1] ?? 'طلباتي'];
  }
  // ملخص الملف الديناميكي /(lawyer|admin)/summary/{no}
  if (!titles[path] && /^(?:\/lawyer|\/admin)\/summary\/[^/]+$/.test(path)) {
    resolved = true;

    [title, crumb] = ['ملخص الملف', titles[`${path.split('/summary')[0]}/summaries`]?.[1] ?? crumb];
  }
  // تفاصيل القضية الديناميكية /(lawyer|employee)?/cases/{no}
  if (!titles[path] && /\/cases\/[^/]+$/.test(path)) {
    resolved = true;

    [title, crumb] = ['متابعة القضية', titles[`${path.split('/cases')[0]}/cases`]?.[1] ?? crumb];
  }
  // تفاصيل طلب التنفيذ الديناميكية /(lawyer|employee)?/execs/{no}
  if (!titles[path] && /\/execs\/[^/]+$/.test(path)) {
    resolved = true;

    [title, crumb] = ['متابعة طلب التنفيذ', titles[`${path.split('/execs')[0]}/execs`]?.[1] ?? crumb];
  }
  // الصفحات المشتركة: عنوان ثابت لكل الأدوار
  if (path === '/profile') {
    resolved = true;

    [title, crumb] = ['الملف الشخصي', 'الحساب'];
  }

  // **احتياطٌ عامّ للمسارات الفرعيّة** (`/admin/editor/create`، `/admin/clients/{id}`…): أطولُ مسارٍ
  // معرَّف يبدأ به المسار الحاليّ. كانت كلّ صفحةٍ فرعيّة بلا نمطٍ مكتوبٍ لها تظهر بعنوان «الرئيسية /
  // منصة العميل» حتى في لوحة الإدارة. فإن لم يُطابق شيء، فالعنوانُ رئيسيّةُ لوحة الدور لا لوحة العميل.
  if (!resolved) {
    const parent = Object.keys(titles)
      .filter((key) => key !== '/' && path.startsWith(`${key}/`))
      .sort((a, b) => b.length - a.length)[0];
    const home = titles[`${panelBase(path)}/dashboard`] ?? titles['/dashboard'];

    [title, crumb] = parent ? titles[parent] : (home ?? [title, crumb]);
  }

  return (
    <header className="topbar">
      <button className="icon-btn menu-btn" onClick={onMenuToggle} type="button">
        <Icon name="menu" />
      </button>

      <div>
        <h1 id="pageTitle">{title}</h1>
        <div className="crumb" id="pageCrumb">{crumb}</div>
      </div>

      <div className="top-actions">
        <NotificationDropdown />

        <div
          className="avatar"
          style={{ cursor: 'pointer' }}
          onClick={() => router.visit('/profile')}
        >
          {user?.avatar ?? 'ع م'}
        </div>
      </div>
    </header>
  );
};

export default Topbar;

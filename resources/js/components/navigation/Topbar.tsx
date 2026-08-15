import { router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { ROLE_TITLES, panelRole } from '@/lib/data';

interface TopbarProps {
  onMenuToggle: () => void;
}

const Topbar: React.FC<TopbarProps> = ({ onMenuToggle }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const unread = (props?.unreadNotifications as number) ?? 0; // عدّ حقيقي من الخادم
  const path = (url as string).split('?')[0];
  // الصفحات المشتركة تُنسب للوحة دور المستخدم الفعليّ (اتّساقاً مع الشريط الجانبيّ).
  const role = panelRole(path, user?.role);
  const titles = ROLE_TITLES[role] ?? ROLE_TITLES.client;
  // مطابقة مباشرة، مع احتياط للمسارات الديناميكية
  let [title, crumb] = titles[path] ?? ['الرئيسية', 'منصة العميل'];
  // محادثة التذكرة في كل اللوحات: /tickets/{no} و/(employee|lawyer|admin)/tickets/{no}
  // كان النمط مثبّتاً ببداية «/tickets» فتظهر لوحات الموظف والمستشار والإدارة بعنوان «الرئيسية / منصة العميل»
  if (!titles[path] && /^(?:\/employee|\/lawyer|\/admin)?\/tickets\/[^/]+$/.test(path) && path !== '/tickets/new') {
    [title, crumb] = ['محادثة التذكرة', titles[`${path.split('/tickets')[0]}/tickets`]?.[1] ?? 'طلباتي'];
  }
  // ملخص الملف الديناميكي /(lawyer|admin)/summary/{no}
  if (!titles[path] && /^(?:\/lawyer|\/admin)\/summary\/[^/]+$/.test(path)) {
    [title, crumb] = ['ملخص الملف', titles[`${path.split('/summary')[0]}/summaries`]?.[1] ?? crumb];
  }
  // تفاصيل القضية الديناميكية /(lawyer|employee)?/cases/{no}
  if (!titles[path] && /\/cases\/[^/]+$/.test(path)) {
    [title, crumb] = ['متابعة القضية', titles[`${path.split('/cases')[0]}/cases`]?.[1] ?? crumb];
  }
  // تفاصيل طلب التنفيذ الديناميكية /(lawyer|employee)?/execs/{no}
  if (!titles[path] && /\/execs\/[^/]+$/.test(path)) {
    [title, crumb] = ['متابعة طلب التنفيذ', titles[`${path.split('/execs')[0]}/execs`]?.[1] ?? crumb];
  }
  // الصفحات المشتركة: عنوان ثابت لكل الأدوار
  if (path === '/notifications') {
    [title, crumb] = ['الإشعارات', 'الحساب'];
  } else if (path === '/profile') {
    [title, crumb] = ['الملف الشخصي', 'الحساب'];
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
        <button
          className="icon-btn"
          onClick={() => router.visit('/notifications')}
          type="button"
        >
          <Icon name="bell" />
          {unread > 0 && <span className="ndot" />}
        </button>

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

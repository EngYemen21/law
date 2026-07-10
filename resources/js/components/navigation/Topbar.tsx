import { router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { ROLE_TITLES, roleOfPath, unread } from '@/lib/data';

interface TopbarProps {
  onMenuToggle: () => void;
}

const Topbar: React.FC<TopbarProps> = ({ onMenuToggle }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const path = (url as string).split('?')[0];
  const role = roleOfPath(path);
  const titles = ROLE_TITLES[role] ?? ROLE_TITLES.client;
  // مطابقة مباشرة، مع احتياط لمسار محادثة التذكرة الديناميكي /tickets/{no}
  let [title, crumb] = titles[path] ?? ['الرئيسية', 'منصة العميل'];
  if (!titles[path] && /^\/tickets\/[^/]+$/.test(path) && path !== '/tickets/new') {
    [title, crumb] = ['محادثة التذكرة', 'طلباتي'];
  }
  // تفاصيل القضية الديناميكية /(lawyer|employee)?/cases/{no}
  if (!titles[path] && /\/cases\/[^/]+$/.test(path)) {
    [title, crumb] = ['متابعة القضية', titles[`${path.split('/cases')[0]}/cases`]?.[1] ?? crumb];
  }
  // تفاصيل طلب التنفيذ الديناميكية /(lawyer|employee)?/execs/{no}
  if (!titles[path] && /\/execs\/[^/]+$/.test(path)) {
    [title, crumb] = ['متابعة طلب التنفيذ', titles[`${path.split('/execs')[0]}/execs`]?.[1] ?? crumb];
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
        <div className="search">
          <Icon name="search" />
          <input placeholder="بحث…" />
        </div>

        <button className="icon-btn" title="دليل النظام" type="button">
          <Icon name="info" />
        </button>

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

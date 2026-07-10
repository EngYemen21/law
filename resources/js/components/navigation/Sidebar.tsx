import { Link, router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { ROLES, ROLE_NAV, roleOfPath } from '@/lib/data';
import { canViewRoute, type PermCatalog } from '@/lib/permissions';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ isOpen, onClose }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const path = (url as string).split('?')[0];
  const role = roleOfPath(path);
  const roleMeta = ROLES.find((r) => r.key === role) ?? ROLES[0];
  const rawNav = ROLE_NAV[role] ?? ROLE_NAV.client;
  const isAdmin = user?.role === 'admin';

  // تصفية القائمة حسب صلاحيات المستخدم (isSuper يرى الكل)؛ خريطة الصلاحيات من كتالوج الخادم
  const perms: string[] = user?.permissions ?? [];
  const isSuper: boolean = user?.isSuper ?? false;
  const viewMap = (props?.permCatalog as PermCatalog | undefined)?.viewMap ?? {};
  const nav = rawNav
    .map((grp) => ({ ...grp, items: grp.items.filter((it) => canViewRoute(it.route, perms, isSuper, viewMap)) }))
    .filter((grp) => grp.items.length > 0);

  const isActive = (route: string) =>
    route === roleMeta.home ? path === route : path.startsWith(route);

  return (
    <aside className={`sidebar ${isOpen ? 'open' : ''}`} id="sidebar">
      {/* الشعار */}
      <div className="sb-logo">
        <img src="/images/logo.jpg" alt="سلاسل بابل لتقنية المعلومات" />
      </div>

      {/* تبديل اللوحة — للإدارة فقط (إشراف على بقية الأدوار) */}
      {isAdmin && (
        <div className="role-switch">
          <div className="gl">عرض اللوحات (إشراف)</div>
          <div className="role-grid" id="roleGrid">
            {ROLES.map((r) => (
              <button
                key={r.key}
                className={`role-btn ${r.key === role ? 'on' : ''}`}
                type="button"
                onClick={() => { onClose(); router.visit(r.home); }}
              >
                <Icon name={r.icon} />
                {r.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* التنقل — حسب الدور الحالي */}
      <nav className="sb-nav" id="nav">
        {nav.map((grp) => (
          <div key={grp.g} className="nav-group">
            <div className="gl">{grp.g}</div>
            {grp.items.map((it) => (
              <Link
                key={it.route}
                href={it.route}
                className={`nav-item ${it.alert ? 'alert' : ''} ${isActive(it.route) ? 'active' : ''}`}
                onClick={onClose}
              >
                <Icon name={it.icon} />
                <span>{it.label}</span>
                {it.badge ? <span className="badge">{it.badge}</span> : null}
              </Link>
            ))}
          </div>
        ))}
      </nav>

      {/* المستخدم المسجّل + تسجيل الخروج */}
      <div className="sb-user">
        <div className="avatar" id="userAv">{user?.avatar ?? roleMeta.av}</div>
        <div className="info">
          <b id="userName">{user?.name ?? roleMeta.name}</b>
          <span id="userRole">{user?.roleLabel ?? roleMeta.label}</span>
        </div>
        <button
          className="icon-btn"
          title="تسجيل الخروج"
          type="button"
          style={{ marginInlineStart: 'auto', width: 34, height: 34 }}
          onClick={() => router.post('/logout')}
        >
          <Icon name="reply" />
        </button>
      </div>
    </aside>
  );
};

export default Sidebar;

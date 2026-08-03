import { Link, router, usePage } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { ROLES, ROLE_NAV, panelRole } from '@/lib/data';
import { canViewRoute, type PermCatalog } from '@/lib/permissions';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ isOpen, onClose }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const unreadNotifications = (props?.unreadNotifications as number) ?? 0; // عدّ حقيقي من الخادم
  const path = (url as string).split('?')[0];
  // الصفحات المشتركة (الإشعارات/الملف الشخصي) تُعرض في لوحة دور المستخدم الفعليّ لا لوحة العميل.
  const role = panelRole(path, user?.role);
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
                {(() => {
                  // عنصر الإشعارات يأخذ العدّ الحقيقي من الخادم؛ غيره يبقى على شارته الثابتة إن وُجدت
                  const badge = it.route === '/notifications' ? unreadNotifications : it.badge;
                  return badge ? <span className="badge">{badge}</span> : null;
                })()}
              </Link>
            ))}
          </div>
        ))}
      </nav>

      {/* تبديل الحساب — لمن لديه أكثر من حساب بنفس الهُويّة (أدوار مختلفة) */}
      {Array.isArray(user?.accounts) && user.accounts.filter((a: any) => !a.current).length > 0 && (
        <div className="role-switch">
          <div className="gl">تبديل الحساب</div>
          <div className="role-grid">
            {user.accounts
              .filter((a: any) => !a.current)
              .map((a: any) => (
                <button
                  key={a.id}
                  type="button"
                  className="role-btn"
                  onClick={() => {
                    onClose();
                    router.post('/auth/switch-account', { account_id: a.id });
                  }}
                >
                  <Icon name="user" />
                  {a.roleLabel}
                </button>
              ))}
          </div>
        </div>
      )}

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

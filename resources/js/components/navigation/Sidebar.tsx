import { Link, router, usePage } from '@inertiajs/react';
import React from 'react';
import { ROLES, ROLE_NAV, panelRole } from '@/lib/data';
import Icon from '@/lib/icons';
import { canViewRoute  } from '@/lib/permissions';
import { useSettings } from '@/lib/settings';
import type {PermCatalog} from '@/lib/permissions';

interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ isOpen, onClose }) => {
  const { url, props } = usePage() as any;
  const user = props?.auth?.user;
  const officeName = useSettings().office_name;
  const unreadNotifications = (props?.unreadNotifications as number) ?? 0; // عدّ حقيقي من الخادم
  const navBadges = (props?.navBadges as Record<string, number>) ?? {}; // شارات العميل الحقيقية
  const path = (url as string).split('?')[0];
  // الصفحات المشتركة (الإشعارات/الملف الشخصي) تُعرض في لوحة دور المستخدم الفعليّ لا لوحة العميل.
  const role = panelRole(path, user?.role);
  const roleMeta = ROLES.find((r) => r.key === role) ?? ROLES[0];
  const rawNav = ROLE_NAV[role] ?? ROLE_NAV.client;
  // const isAdmin = user?.role === 'admin'; // كان لمبدّل اللوحات الملغى 2026-08-28

  // تصفية القائمة حسب صلاحيات المستخدم (isSuper يرى الكل)؛ خريطة الصلاحيات من كتالوج الخادم
  const perms: string[] = user?.permissions ?? [];
  const isSuper: boolean = user?.isSuper ?? false;
  const viewMap = (props?.permCatalog as PermCatalog | undefined)?.viewMap ?? {};
  const nav = rawNav
    .map((grp) => ({ ...grp, items: grp.items.filter((it) => canViewRoute(it.route, perms, isSuper, viewMap)) }))
    .filter((grp) => grp.items.length > 0);

  // الخانة النشطة واحدة: أدقّ مسارٍ ينطبق على الصفحة بجزءٍ كامل — `/tickets/new` لـ«فتح تذكرة» وحدها
  // لا لـ«متابعة التذاكر» معها، و`/tickets/SB-…` لـ«متابعة التذاكر». الرئيسيّة بمطابقة تامّة.
  const matches = (route: string) =>
    route === roleMeta.home ? path === route : path === route || path.startsWith(`${route}/`);
  const activeRoute = nav
    .flatMap((grp) => grp.items.map((it) => it.route))
    .filter(matches)
    .sort((a, b) => b.length - a.length)[0];
  const isActive = (route: string) => route === activeRoute;

  return (
    <aside className={`sidebar ${isOpen ? 'open' : ''}`} id="sidebar">
      {/* الشعار */}
      <div className="sb-logo">
        {/* النصّ البديل اسم المكتب من إعداده (`office_name`) — لا نسخةً منقوشة */}
        <img src="/images/021.png" alt={officeName} />
      </div>

      {/* أُلغي مبدّل «عرض اللوحات (إشراف)» بقرار المستخدم 2026-08-28 — الإدارة العليا
          مقصورة على لوحتها ولا تتنقل للوحات الأدوار الأخرى (EnsureRole يحظرها خادميًا أيضًا).
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
      )} */}

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
                  // كل الشارات من الخادم عبر navBadges
                  const badge = navBadges[it.route];

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

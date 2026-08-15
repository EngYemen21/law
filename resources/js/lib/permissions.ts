import { usePage } from '@inertiajs/react';

// ============================================================
// كتالوج الصلاحيات — مصدره الوحيد الخادم (App\Support\Permissions::catalog)
// يصل عبر Inertia shared props؛ لا تكرار يدوياً في الواجهة.
// ============================================================

export interface PermGroup { g: string; items: string[] }

export interface PermCatalog {
  permissions: string[];                    // القائمة المسطّحة (23)
  groups: PermGroup[];                       // المجموعات الخمس
  presets: Record<string, string[]>;         // القوالب/الأدوار بصلاحياتها
  viewMap: Record<string, string>;           // المسار → الصلاحية اللازمة
  rolePermissions: Record<string, string[]>; // الدور (employee/lawyer/admin) → صلاحياته المتاحة
}

const EMPTY: PermCatalog = { permissions: [], groups: [], presets: {}, viewMap: {}, rolePermissions: {} };

// يقرأ الكتالوج المشترك من props (فارغ قبل المصادقة)
export function usePermCatalog(): PermCatalog {
  const { props } = usePage() as unknown as { props: { permCatalog?: PermCatalog | null } };
  return props.permCatalog ?? EMPTY;
}

// هل يملك المستخدم صلاحية تفصيلية بعينها؟ — لإخفاء الأزرار داخل الصفحات (لا الروابط فقط)
// الإدارة (isSuper) تتجاوز الكل، مطابقةً لـ Gate::before في AppServiceProvider.
export function useCan(): (permission: string) => boolean {
  const { props } = usePage() as unknown as {
    props: { auth?: { user?: { isSuper?: boolean; permissions?: string[] } | null } };
  };
  const user = props.auth?.user;
  if (user?.isSuper) return () => true;
  const owned = user?.permissions ?? [];

  return (permission: string) => owned.includes(permission);
}

// هل يملك المستخدم صلاحية رؤية مسار؟ (segment-aware؛ isSuper يرى الكل) — viewMap يأتي من الكتالوج
export function canViewRoute(
  route: string,
  permissions: string[],
  isSuper: boolean,
  viewMap: Record<string, string>,
): boolean {
  if (isSuper) return true;
  let needed: string | null = null;
  let bestLen = -1;
  for (const [prefix, perm] of Object.entries(viewMap)) {
    if ((route === prefix || route.startsWith(prefix + '/')) && prefix.length > bestLen) {
      needed = perm;
      bestLen = prefix.length;
    }
  }
  return needed === null || permissions.includes(needed);
}

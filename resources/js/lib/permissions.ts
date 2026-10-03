import { usePage } from '@inertiajs/react';
import { maskClient } from '@/lib/employee-data';

// ============================================================
// كتالوج الصلاحيات — مصدره الوحيد الخادم (App\Support\Permissions::catalog)
// يصل عبر Inertia shared props؛ لا تكرار يدوياً في الواجهة.
// ============================================================

export interface PermGroup { g: string; items: string[] }

export interface PermCatalog {
  permissions: string[];                    // القائمة المسطّحة (27 — `Permissions::all()`)
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
  if (needed === null) return true;
  const options = needed.split(',').map((p) => p.trim());
  return options.some((opt) => permissions.includes(opt));
}

/**
 * **الإدارة العليا ترى الأسماء كما هي.**
 *
 * كان `maskClient` في `employee-data` يُقنّع بلا شرطٍ («ع••••ه (مشفّر)»)، والمكوّنات
 * المشتركة (رحلة الاستشارة · دعوات الاجتماعات) تستوردها — فالمديرُ الذي يملك الملفّ
 * كلَّه يقرأ اسماً مقنَّعاً. (قرار المالك 2026-09-08: لا تقنيع للإدارة إطلاقاً.)
 *
 * تُعيد دالّةَ تقنيعٍ تحترم الدور: هُويّةً للإدارة، و`maskClient` لمن سواها — وقد صارت
 * تمريراً كذلك (قرار المالك 2026-09-11: لا تقنيع على المحامي والموظّف).
 */
export function useMasker(): (name: string) => string {
  const { props } = usePage() as unknown as {
    props: { auth?: { user?: { isSuper?: boolean } | null } };
  };

  return props.auth?.user?.isSuper ? (n: string) => n || '—' : maskClient;
}

/**
 * **هل يُفتح هذا الرابط لهذا المستخدم؟** — خريطة الخادم نفسها (`Permissions::viewMap`، مشتقّة من وسائط المسارات)
 * التي يحرس بها المسار ويصفّي بها الشريط الجانبيّ. لكلّ زرٍّ ينتقل إلى صفحة: يُخفى حيث يُعاد صاحبه برسالة
 * «لا تملك صلاحية الوصول» — كانت أزرار اللوحات تظهر لكلّ موظّف ومحامٍ أيّاً كانت صلاحيّاته (جرد الأزرار
 * 2026-10-03، المرحلة ٢ — ثبت في المتصفّح). لا اسم صلاحيّةٍ منسوخ في الواجهة: الرابط وحده يكفي.
 */
export function useCanVisit(): (href: string) => boolean {
  const { props } = usePage() as unknown as {
    props: { auth?: { user?: { isSuper?: boolean; permissions?: string[] } | null }; permCatalog?: PermCatalog | null };
  };
  const user = props.auth?.user;
  const viewMap = props.permCatalog?.viewMap ?? {};

  return (href: string) => canViewRoute(href.split(/[?#]/)[0], user?.permissions ?? [], Boolean(user?.isSuper), viewMap);
}

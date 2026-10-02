import { usePage } from '@inertiajs/react';
import React from 'react';

/** بيئة التشغيل من الخادم (`HandleInertiaRequests` ← `AppEnvironment`). */
export interface AppEnvShared {
  sandbox: boolean;
  name: string;
}

/**
 * **شارة «بيئة تجربة»** (فصل البيئات 2026-09-29) — خارج الإنتاج، كي لا يُخلط بين نسخة التجربة ونسخة العملاء.
 *
 * **في لوحة التحكّم وحدها** (ملاحظة المالك 2026-10-02): كانت في جذر التطبيق فتظهر على الصفحة الرئيسيّة العامّة
 * وصفحة الدخول، وفوق أدوات Zoom في الغرفة. صارت داخل `AppLayout`، وتُخفى في الغرفة بالأنماط
 * (`body:has(.mroom-head) .env-badge` — الغرفة تُرسم في `body` بـ`createPortal`). لا تُعرض في الإنتاج، ولا تلتقط النقر.
 */
const EnvironmentBadge: React.FC = () => {
  const env = (usePage().props as { appEnv?: AppEnvShared }).appEnv;

  if (!env?.sandbox) {
    return null;
  }

  return (
    <div className="env-badge" role="status" title="بيانات ومفاتيح تجريبيّة — ليست نسخة العملاء">
      بيئة تجربة · {env.name}
    </div>
  );
};

export default EnvironmentBadge;

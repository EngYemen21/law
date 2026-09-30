import React from 'react';

/** بيئة التشغيل من الخادم (`HandleInertiaRequests` ← `AppEnvironment`) — ثابتةٌ طوال عمر الصفحة. */
export interface AppEnvShared {
  sandbox: boolean;
  name: string;
}

/**
 * **شارة «بيئة تجربة»** (فصل البيئات 2026-09-29) — ثابتةٌ في زاوية كلّ صفحة (الدخول واللوحات) خارج الإنتاج،
 * كي لا يُخلط بين نسخة التجربة ونسخة العملاء. لا تُعرض في الإنتاج، ولا تلتقط النقر.
 */
const EnvironmentBadge: React.FC<{ initialPage: { props: unknown } }> = ({ initialPage }) => {
  const env = (initialPage.props as { appEnv?: AppEnvShared }).appEnv;

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

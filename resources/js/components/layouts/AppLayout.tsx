import { router, usePage } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import EnvironmentBadge from '@/components/babylon/EnvironmentBadge';
import { useToast } from '@/components/babylon/Toast';
import Sidebar from '@/components/navigation/Sidebar';
import Topbar from '@/components/navigation/Topbar';
import { echo } from '@/lib/echo';

interface AppLayoutProps {
  children: React.ReactNode;
}

const AppLayout: React.FC<AppLayoutProps> = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const toast = useToast();
  const props = usePage().props as {
    auth?: { user?: { id?: number; role?: string } };
  };
  const userId = props.auth?.user?.id;
  const isStaff = !!userId && props.auth?.user?.role !== 'client';

  // إشعارات لحظية: قناة المستخدم الخاصّة — تنبيه فوريّ + تحديث نقطة الجرس وقائمة الإشعارات بلا إعادة تحميل
  useEffect(() => {
    if (!userId) {
return;
}

    echo.private(`notifications.${userId}`).listen('.notify', (e: { text: string }) => {
      toast(e.text);
      // `recentNotifications` هو ما تقرؤه القائمة — كان المُعاد `notifications` (لا وجود له) فتبقى القائمة بائتة
      router.reload({ only: ['unreadNotifications', 'recentNotifications'] });
    });

    return () => {
 echo.leave(`notifications.${userId}`); 
};
  }, [userId]);

  // **حالة الطاقم لحظيّاً** (في جلسة Zoom الآن / متاح): الخادم يبثّ الخريطة كاملةً عند كلّ دخولٍ أو خروج
  // (`StaffPresenceChanged`)، فتُستبدل الخاصّيّة `inSession` في مكانها بلا طلب — وكلّ الشارات والقوائم
  // تقرؤها (`lib/staff-presence`).
  useEffect(() => {
    if (!isStaff) {
      return;
    }

    echo.private('staff.presence').listen('.presence', (e: { inSession: Record<number, string> }) => {
      router.replaceProp('inSession', e.inSession);
    });

    return () => {
      echo.leave('staff.presence');
    };
  }, [isStaff]);

  // رسائل الخادم (flash.error / flash.success) انتقلت إلى `ServerFeedback.tsx`: كانت هنا فلا تصل
  // صفحةَ الدخول (مستقلّة بلا تخطيط)، وتُطلق بتغيّر النصّ فيضيع الرفض نفسه في المرّة الثانية.

  return (
    <div className="app">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div
        className={`scrim ${sidebarOpen ? 'show' : ''}`}
        id="scrim"
        onClick={() => setSidebarOpen(false)}
      />

      <div className="main">
        <Topbar onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
        <div className="content" id="content">
          <div className="view">{children}</div>
        </div>
      </div>

      {/* شارة «بيئة تجربة» خارج الإنتاج — في لوحة التحكّم وحدها (لا الرئيسيّة ولا الدخول ولا الغرفة) */}
      <EnvironmentBadge />
    </div>
  );
};

export default AppLayout;

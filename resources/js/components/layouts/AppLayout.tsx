import { router, usePage } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
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
    auth?: { user?: { id?: number } };
  };
  const userId = props.auth?.user?.id;

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
    </div>
  );
};

export default AppLayout;

import { router, usePage } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import { useToast } from '@/components/babylon/Toast';
import Sidebar from '@/components/navigation/Sidebar';
import Topbar from '@/components/navigation/Topbar';
// import ImpersonationBanner from '@/components/navigation/ImpersonationBanner'; // أُلغيت المعاينة 2026-08-28
import { echo } from '@/lib/echo';

interface AppLayoutProps {
  children: React.ReactNode;
}

const AppLayout: React.FC<AppLayoutProps> = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const toast = useToast();
  const props = usePage().props as {
    auth?: { user?: { id?: number } };
    flash?: { error?: string | null; success?: string | null };
  };
  const userId = props.auth?.user?.id;
  const flashError = props.flash?.error;
  const flashSuccess = props.flash?.success;

  // إشعارات لحظية: قناة المستخدم الخاصّة — تنبيه فوريّ + تحديث نقطة الجرس وقائمة الإشعارات بلا إعادة تحميل
  useEffect(() => {
    if (!userId) {
return;
}

    echo.private(`notifications.${userId}`).listen('.notify', (e: { text: string }) => {
      toast(e.text);
      router.reload({ only: ['unreadNotifications', 'notifications'] });
    });

    return () => {
 echo.leave(`notifications.${userId}`); 
};
  }, [userId]);

  // رسائل الخادم (flash.error / flash.success) → toast — استهلاك مشاركة موجودة أصلاً في HandleInertiaRequests
  useEffect(() => {
    if (flashError) {
      toast(flashError, 'error');
    }
  }, [flashError, toast]);

  useEffect(() => {
    if (flashSuccess) {
      toast(flashSuccess, 'success');
    }
  }, [flashSuccess, toast]);

  return (
    <div className="app">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div
        className={`scrim ${sidebarOpen ? 'show' : ''}`}
        id="scrim"
        onClick={() => setSidebarOpen(false)}
      />

      <div className="main">
        {/* <ImpersonationBanner /> — أُلغيت معاينة اللوحات 2026-08-28 */}
        <Topbar onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
        <div className="content" id="content">
          <div className="view">{children}</div>
        </div>
      </div>
    </div>
  );
};

export default AppLayout;

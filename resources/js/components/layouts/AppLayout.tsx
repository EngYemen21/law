import React, { useState } from 'react';
import Sidebar from '@/components/navigation/Sidebar';
import Topbar from '@/components/navigation/Topbar';
import ImpersonationBanner from '@/components/navigation/ImpersonationBanner';

interface AppLayoutProps {
  children: React.ReactNode;
}

const AppLayout: React.FC<AppLayoutProps> = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="app">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div
        className={`scrim ${sidebarOpen ? 'show' : ''}`}
        id="scrim"
        onClick={() => setSidebarOpen(false)}
      />

      <div className="main">
        <ImpersonationBanner />
        <Topbar onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
        <div className="content" id="content">
          <div className="view">{children}</div>
        </div>
      </div>
    </div>
  );
};

export default AppLayout;

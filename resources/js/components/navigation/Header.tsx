// ⚠️ غير مستعمل — صفر مستورد في resources/js (تدقيق 2026-08-21): AppLayout يستورد Sidebar وحده.
// وفيه بيانات إشعارات وهمية مثبّتة أدناه، فلا تُعِده للخدمة قبل ربطها بـnavBadges/الإشعارات الحقيقية.
import { Link } from '@inertiajs/react';
import clsx from 'clsx';
import { Bell, Menu, Search, LogOut, User, Settings } from 'lucide-react';
import React, { useState } from 'react';

interface HeaderProps {
  sidebarOpen: boolean;
  onSidebarToggle: (open: boolean) => void;
  user?: {
    id: number;
    name: string;
    email: string;
    role?: string;
    avatar?: string;
  };
}

const Header: React.FC<HeaderProps> = ({ sidebarOpen, onSidebarToggle, user }) => {
  const [showUserMenu, setShowUserMenu] = useState(false);
  const [showNotifications, setShowNotifications] = useState(false);

  const notifications = [
    {
      id: 1,
      type: 'ticket',
      message: 'تم تحديث حالة التذكرة SB-2026-1042',
      time: 'قبل ساعتين',
      unread: true,
    },
    {
      id: 2,
      type: 'appointment',
      message: 'موعد جديد في الاثنين 29 يونيو',
      time: 'أمس',
      unread: true,
    },
    {
      id: 3,
      type: 'invoice',
      message: 'فاتورة جديدة مستحقة السداد',
      time: 'قبل 3 أيام',
      unread: false,
    },
  ];

  return (
    <header
      className="border-b sticky top-0 z-40 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8"
      style={{
        backgroundColor: '#FFFFFF',
        borderBottomColor: '#E1E8EE',
      }}
    >
      {/* Left Section */}
      <div className="flex items-center gap-4">
        <button
          onClick={() => onSidebarToggle(!sidebarOpen)}
          className="p-2 rounded-lg hover:bg-neutral-paper2 transition-colors"
        >
          <Menu size={20} color="#13314F" />
        </button>

        {/* Search - Hidden on mobile */}
        <div className="hidden md:block flex-1 max-w-sm">
          <div className="relative">
            <Search
              className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4"
              color="#90A2B2"
            />
            <input
              type="text"
              placeholder="بحث..."
              className="w-full pr-10 pl-4 py-2 border rounded-lg bg-neutral-paper2 text-secondary placeholder-neutral-muted focus:outline-none focus:ring-2"
              style={{
                borderColor: '#E1E8EE',
                backgroundColor: '#F6F8FA',
                color: '#13314F',
              }}
              onFocus={(e) => {
                e.currentTarget.style.borderColor = '#0E5C9C';
                e.currentTarget.style.boxShadow = 'inset 0 0 0 3px rgba(14, 92, 156, 0.12)';
              }}
              onBlur={(e) => {
                e.currentTarget.style.borderColor = '#E1E8EE';
                e.currentTarget.style.boxShadow = 'none';
              }}
            />
          </div>
        </div>
      </div>

      {/* Right Section */}
      <div className="flex items-center gap-2 sm:gap-4">
        {/* Notifications */}
        <div className="relative">
          <button
            onClick={() => setShowNotifications(!showNotifications)}
            className="p-2 rounded-lg hover:bg-neutral-paper2 transition-colors relative"
          >
            <Bell size={20} color="#13314F" />
            {notifications.some((n) => n.unread) && (
              <span
                className="absolute top-1 right-1 w-2 h-2 rounded-full"
                style={{ backgroundColor: '#C0392B' }}
              />
            )}
          </button>

          {/* Notifications Dropdown */}
          {showNotifications && (
            <div
              className="absolute left-0 mt-2 w-80 rounded-lg shadow-lg border z-50"
              style={{
                backgroundColor: '#FFFFFF',
                borderColor: '#E1E8EE',
                boxShadow: '0 24px 60px -24px rgba(10,42,85,.35)',
              }}
            >
              <div
                className="p-4 border-b"
                style={{ borderBottomColor: '#E1E8EE' }}
              >
                <h3
                  className="font-semibold"
                  style={{ color: '#13314F' }}
                >
                  الإشعارات
                </h3>
              </div>
              <div className="max-h-80 overflow-y-auto">
                {notifications.map((notification) => (
                  <div
                    key={notification.id}
                    className={clsx(
                      'px-4 py-3 border-b hover:bg-neutral-paper2 cursor-pointer transition-colors',
                    )}
                    style={{
                      borderBottomColor: '#E1E8EE',
                      backgroundColor: notification.unread ? '#F6F8FA' : 'transparent',
                    }}
                  >
                    <p
                      className="text-sm font-medium"
                      style={{ color: '#13314F' }}
                    >
                      {notification.message}
                    </p>
                    <p
                      className="text-xs mt-1"
                      style={{ color: '#90A2B2' }}
                    >
                      {notification.time}
                    </p>
                  </div>
                ))}
              </div>
              <div
                className="p-3 border-t text-center"
                style={{ borderTopColor: '#E1E8EE' }}
              >
                <button
                  className="text-sm font-medium"
                  style={{ color: '#0E5C9C' }}
                >
                  عرض الكل
                </button>
              </div>
            </div>
          )}
        </div>

        {/* User Menu */}
        <div className="relative">
          <button
            onClick={() => setShowUserMenu(!showUserMenu)}
            className="flex items-center gap-2 p-2 rounded-lg hover:bg-neutral-paper2 transition-colors"
          >
            {user?.avatar ? (
              <img
                src={user.avatar}
                alt={user.name}
                className="w-8 h-8 rounded-full"
              />
            ) : (
              <div
                className="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold"
                style={{
                  backgroundImage: 'linear-gradient(135deg,#0A2A55 0%,#0E5C9C 52%,#13A2C9 100%)',
                }}
              >
                {user?.name?.charAt(0)}
              </div>
            )}
            <div className="hidden sm:block text-right">
              <p
                className="text-sm font-medium"
                style={{ color: '#13314F' }}
              >
                {user?.name}
              </p>
              <p
                className="text-xs"
                style={{ color: '#90A2B2' }}
              >
                {user?.role}
              </p>
            </div>
          </button>

          {/* User Dropdown */}
          {showUserMenu && (
            <div
              className="absolute left-0 mt-2 w-48 rounded-lg shadow-lg border z-50"
              style={{
                backgroundColor: '#FFFFFF',
                borderColor: '#E1E8EE',
                boxShadow: '0 24px 60px -24px rgba(10,42,85,.35)',
              }}
            >
              <div
                className="p-3 border-b"
                style={{ borderBottomColor: '#E1E8EE' }}
              >
                <p
                  className="text-sm font-medium"
                  style={{ color: '#13314F' }}
                >
                  {user?.name}
                </p>
                <p
                  className="text-xs"
                  style={{ color: '#90A2B2' }}
                >
                  {user?.email}
                </p>
              </div>
              <div className="py-2">
                <Link
                  href="/profile"
                  className="flex items-center gap-2 px-4 py-2 text-sm hover:bg-neutral-paper2 transition-colors"
                  style={{ color: '#607689' }}
                >
                  <User size={16} />
                  الملف الشخصي
                </Link>
                <Link
                  href="/profile"
                  className="flex items-center gap-2 px-4 py-2 text-sm hover:bg-neutral-paper2 transition-colors"
                  style={{ color: '#607689' }}
                >
                  <Settings size={16} />
                  الإعدادات
                </Link>
              </div>
              <div
                className="border-t p-2"
                style={{ borderTopColor: '#E1E8EE' }}
              >
                <Link
                  href="/logout"
                  method="post"
                  className="flex items-center gap-2 px-4 py-2 text-sm hover:bg-red-bg transition-colors rounded"
                  style={{ color: '#C0392B' }}
                >
                  <LogOut size={16} />
                  تسجيل الخروج
                </Link>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
};

export default Header;

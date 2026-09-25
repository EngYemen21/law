import { Link, router, usePage } from '@inertiajs/react';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useEscapeLayer } from '@/components/babylon/Modal';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

export interface DropdownNotificationItem {
    id?: number;
    ic: string;
    tone: string;
    text: string;
    time: string;
    unread: boolean;
    link?: string | null;
}

const NotificationDropdown: React.FC = () => {
    const { props } = usePage() as any;
    const toast = useToast();
    const dropdownRef = useRef<HTMLDivElement>(null);

    const [isOpen, setIsOpen] = useState(false);
    const [filterMode, setFilterMode] = useState<'all' | 'unread'>('all');

    const unreadCount = (props?.unreadNotifications as number) ?? 0;
    const rawNotifications: DropdownNotificationItem[] = useMemo(() => {
        return (props?.recentNotifications as DropdownNotificationItem[]) ?? [];
    }, [props?.recentNotifications]);

    // Escape يغلق القائمة — طبقةٌ في المكدّس المشترك، فلا يُغلق معها درجٌ مفتوح تحتها
    useEscapeLayer(isOpen, () => setIsOpen(false));

    // إغلاق القائمة عند النقر خارجها
    useEffect(() => {
        const handleOutsideClick = (e: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
                setIsOpen(false);
            }
        };

        if (isOpen) {
            document.addEventListener('mousedown', handleOutsideClick);
        }

        return () => {
            document.removeEventListener('mousedown', handleOutsideClick);
        };
    }, [isOpen]);

    // تصفية الإشعارات المعروضة
    const displayedNotifications = useMemo(() => {
        if (filterMode === 'unread') {
            return rawNotifications.filter((n) => n.unread);
        }
        return rawNotifications;
    }, [rawNotifications, filterMode]);

    // تعليم كل الإشعارات كمقروءة
    const markAllAsRead = (e: React.MouseEvent) => {
        e.stopPropagation();
        router.post(
            '/notifications/read-all',
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast('تم تعليم جميع الإشعارات كمقروءة');
                },
            }
        );
    };

    // النقر على إشعار محدد
    const handleNotificationClick = (item: DropdownNotificationItem) => {
        setIsOpen(false);

        // إذا كان الإشعار غير مقروء وله معرف، نقوم بتعليمه كمقروء
        if (item.unread && item.id) {
            router.post(`/notifications/${item.id}/read`, {}, { preserveScroll: true });
        }

        // الانتقال للشاشة المرتبطة إن وجدت
        if (item.link) {
            router.visit(item.link);
        }
    };

    return (
        <div ref={dropdownRef} style={{ position: 'relative', display: 'inline-block' }}>
            {/* ── زر الجرس التفاعلي ── */}
            <button
                className={`icon-btn ${isOpen ? 'active' : ''}`}
                onClick={() => setIsOpen(!isOpen)}
                type="button"
                title="الإشعارات والتنبيهات"
                style={{
                    position: 'relative',
                    background: isOpen ? 'rgba(14, 92, 156, 0.12)' : undefined,
                    color: isOpen ? 'var(--primary)' : undefined,
                    transition: 'all .15s ease',
                }}
                aria-expanded={isOpen}
                aria-haspopup="true"
            >
                <Icon name="bell" />
                {unreadCount > 0 && (
                    <span
                        className="ndot"
                        style={{
                            position: 'absolute',
                            top: 5,
                            right: 5,
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            background: '#ef4444',
                            boxShadow: '0 0 0 2px #fff',
                        }}
                    />
                )}
            </button>

            {/* ── خلفية التعتيم على الجوال (Mobile Scrim) ── */}
            {isOpen && (
                <div
                    className="notif-dropdown-scrim"
                    onClick={() => setIsOpen(false)}
                />
            )}

            {/* ── القائمة المنسدلة للإشعارات (Dropdown Popover) ── */}
            {isOpen && (
                <div className="notif-dropdown-menu">
                    <style>{`
            @keyframes dropdownFadeIn {
              from { opacity: 0; transform: translateY(-6px); }
              to { opacity: 1; transform: translateY(0); }
            }
            .notif-dropdown-scrim {
              display: none;
            }
            .notif-dropdown-menu {
              position: absolute;
              top: calc(100% + 10px);
              left: 0;
              width: 380px;
              max-width: calc(100vw - 32px);
              background: #ffffff;
              border-radius: 14px;
              border: 1px solid var(--line-soft, #e2e8f0);
              box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.04);
              z-index: 100;
              overflow: hidden;
              animation: dropdownFadeIn .18s ease-out;
              text-align: right;
            }
            .notif-scroll-body {
              max-height: 380px;
              overflow-y: auto;
              overscroll-behavior: contain;
              -webkit-overflow-scrolling: touch;
            }
            .notif-item {
              display: flex;
              align-items: flex-start;
              gap: 12px;
              padding: 12px 16px;
              border-bottom: 1px solid var(--line-soft, #f1f5f9);
              transition: background .12s ease;
              text-decoration: none;
              color: inherit;
              cursor: pointer;
            }
            .notif-item:hover {
              background: #f8fafc;
            }
            .notif-item.unread {
              background: rgba(14, 92, 156, 0.03);
            }
            .notif-item.unread:hover {
              background: rgba(14, 92, 156, 0.06);
            }
            @media (max-width: 600px) {
              .notif-dropdown-scrim {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(10, 42, 85, 0.35);
                backdrop-filter: blur(2px);
                z-index: 99;
              }
              .notif-dropdown-menu {
                position: fixed !important;
                top: 56px !important;
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                max-width: none !important;
                max-height: 84vh !important;
                border-radius: 16px !important;
                box-shadow: 0 20px 48px rgba(0, 0, 0, 0.3) !important;
                z-index: 100 !important;
                display: flex !important;
                flex-direction: column !important;
              }
              .notif-scroll-body {
                max-height: calc(84vh - 130px) !important;
                flex: 1 1 auto !important;
              }
              .notif-item {
                padding: 11px 13px !important;
                gap: 10px !important;
              }
            }
          `}</style>

                    {/* رأس القائمة */}
                    <div
                        style={{
                            padding: '14px 16px 10px',
                            borderBottom: '1px solid var(--line-soft, #e2e8f0)',
                            background: '#ffffff',
                        }}
                    >
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span style={{ fontSize: 16 }}>🔔</span>
                                <b style={{ fontSize: 14.5, color: 'var(--deep)' }}>الإشعارات</b>
                                {unreadCount > 0 && (
                                    <span
                                        style={{
                                            fontSize: 11,
                                            background: 'rgba(239, 68, 68, 0.1)',
                                            color: '#b91c1c',
                                            padding: '2px 7px',
                                            borderRadius: 10,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {unreadCount} جديدة
                                    </span>
                                )}
                            </div>

                            {unreadCount > 0 && (
                                <button
                                    type="button"
                                    onClick={markAllAsRead}
                                    style={{
                                        background: 'none',
                                        border: 'none',
                                        fontSize: 11.5,
                                        color: 'var(--primary)',
                                        fontWeight: 700,
                                        cursor: 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 4,
                                        padding: '3px 6px',
                                        borderRadius: 6,
                                    }}
                                    title="تعليم جميع الإشعارات كمقروءة"
                                >
                                    <Icon name="check" /> تعليم الكل كمقروء
                                </button>
                            )}
                        </div>

                        {/* أزرار التصفية السريعة */}
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button
                                type="button"
                                onClick={() => setFilterMode('all')}
                                style={{
                                    border: 'none',
                                    background: filterMode === 'all' ? 'var(--paper-2, #f1f5f9)' : 'transparent',
                                    color: filterMode === 'all' ? 'var(--deep)' : 'var(--muted)',
                                    fontSize: 12,
                                    fontWeight: filterMode === 'all' ? 700 : 500,
                                    padding: '4px 10px',
                                    borderRadius: 6,
                                    cursor: 'pointer',
                                }}
                            >
                                الكل ({rawNotifications.length})
                            </button>
                            <button
                                type="button"
                                onClick={() => setFilterMode('unread')}
                                style={{
                                    border: 'none',
                                    background: filterMode === 'unread' ? 'var(--paper-2, #f1f5f9)' : 'transparent',
                                    color: filterMode === 'unread' ? '#b91c1c' : 'var(--muted)',
                                    fontSize: 12,
                                    fontWeight: filterMode === 'unread' ? 700 : 500,
                                    padding: '4px 10px',
                                    borderRadius: 6,
                                    cursor: 'pointer',
                                }}
                            >
                                غير المقروءة ({unreadCount})
                            </button>
                        </div>
                    </div>

                    {/* قائمة الإشعارات القابلة للتمرير */}
                    <div className="notif-scroll-body">
                        {displayedNotifications.length === 0 ? (
                            <div style={{ padding: '36px 18px', textAlign: 'center', color: 'var(--muted)' }}>
                                <div
                                    style={{
                                        width: 44,
                                        height: 44,
                                        borderRadius: '50%',
                                        background: 'var(--paper-2, #f1f5f9)',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        margin: '0 auto 10px',
                                        fontSize: 20,
                                        color: 'var(--faint)',
                                    }}
                                >
                                    <Icon name="bell" />
                                </div>
                                <b style={{ fontSize: 13, color: 'var(--deep)', display: 'block', marginBottom: 4 }}>
                                    {filterMode === 'unread' ? 'لا توجد إشعارات غير مقروءة' : 'لا توجد إشعارات حالياً'}
                                </b>
                                <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                                    ستصلك التنبيهات هنا فور حدوث أي مستجدات على تذاكرك وقضاياك
                                </span>
                            </div>
                        ) : (
                            displayedNotifications.map((n, i) => (
                                <div
                                    key={n.id ?? i}
                                    className={`notif-item ${n.unread ? 'unread' : ''}`}
                                    onClick={() => handleNotificationClick(n)}
                                    role="button"
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') handleNotificationClick(n);
                                    }}
                                >
                                    {/* أيقونة الإشعار ذات اللون المناسب */}
                                    <div
                                        className={`nico stat ${n.tone || 't-blue'}`}
                                        style={{
                                            width: 32,
                                            height: 32,
                                            minWidth: 32,
                                            borderRadius: 10,
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            fontSize: 14,
                                            marginTop: 2,
                                        }}
                                    >
                                        <Icon name={n.ic || 'bell'} />
                                    </div>

                                    {/* نص وتاريخ الإشعار */}
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div
                                            style={{
                                                fontSize: 12.5,
                                                fontWeight: n.unread ? 700 : 500,
                                                color: n.unread ? 'var(--deep)' : '#334155',
                                                lineHeight: 1.5,
                                                marginBottom: 4,
                                                wordBreak: 'break-word',
                                            }}
                                        >
                                            {n.text}
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 11, color: 'var(--muted)' }}>
                                            <span>{n.time}</span>
                                            {n.link && (
                                                <span style={{ color: 'var(--primary)', fontWeight: 600, display: 'flex', alignItems: 'center', gap: 2 }}>
                                                    عرض التفاصيل ↗
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* نقطة التنبيه غير المقروء */}
                                    {n.unread && (
                                        <span
                                            style={{
                                                width: 7,
                                                height: 7,
                                                borderRadius: '50%',
                                                background: '#0284c7',
                                                marginTop: 7,
                                                flexShrink: 0,
                                            }}
                                            title="إشعار غير مقروء"
                                        />
                                    )}
                                </div>
                            ))
                        )}
                    </div>

                    {/* أسفل القائمة: رابط السجل الكامل */}
                    <div
                        style={{
                            padding: '10px 16px',
                            background: 'var(--paper-2, #f8fafc)',
                            borderTop: '1px solid var(--line-soft, #e2e8f0)',
                            textAlign: 'center',
                        }}
                    >
                        <Link
                            href="/notifications"
                            onClick={() => setIsOpen(false)}
                            style={{
                                fontSize: 12,
                                fontWeight: 700,
                                color: 'var(--primary)',
                                textDecoration: 'none',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 5,
                            }}
                        >
                            <span>عرض سجل الإشعارات الكامل</span>
                            <span>←</span>
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
};

export default NotificationDropdown;

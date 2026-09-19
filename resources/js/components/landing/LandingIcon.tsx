import { ICON_PATHS } from '@/lib/icons';

// مسارات تخصّ الصفحة الترويجية وحدها — تُعرَّف هنا لا في خريطة التطبيق كي لا يُمسّ ملفّه
const EXTRA_PATHS: Record<string, string> = {
    shield: '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c2.2.6 3.5 2.4 3.5 5.2"/>',
    chat: '<path d="M21 12a8 8 0 01-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1121 12z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/>',
    list: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
    // «إلى الأمام» في واجهة من اليمين لليسار يشير إلى اليسار
    forward: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    // صفحة الدخول
    idcard: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.4 1.7-2 3-2s2.4.6 3 2M14 10h4M14 13h3"/>',
    userplus: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 3-5.5 6.5-5.5s6.5 1.9 6.5 5.5M19 8v6M16 11h6"/>',
    chart: '<path d="M4 20h16M7 16v-5M12 16V6M17 16v-8"/>',
    archive: '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4"/>',
};

type Props = {
    name: string;
    className?: string;
};

// أيقونة زخرفيّة: مخفيّة عن قارئ الشاشة لأنّ النصّ المجاور يحمل المعنى
export default function LandingIcon({ name, className = 'size-5' }: Props) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.85}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            dangerouslySetInnerHTML={{ __html: EXTRA_PATHS[name] ?? ICON_PATHS[name] ?? '' }}
        />
    );
}

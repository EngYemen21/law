import React from 'react';

// ============================================================
// نظام الأيقونات — مستخرج حرفياً من index (82).html (خريطة I)
// الاستخدام: <Icon name="folder" />  أو  <Icon name="card" cls="ic big" />
// ============================================================

export const ICON_PATHS: Record<string, string> = {
  home: '<path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/>',
  ticket: '<path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4z"/><path d="M14 6v12"/>',
  folder: '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
  scale: '<path d="M12 3v18M7 21h10M5 7h14M5 7l-2 6a3 3 0 006 0L9 7M19 7l-2 6a3 3 0 006 0l-2-6M12 3l-3 4M12 3l3 4"/>',
  exec: '<path d="M9 11l3 3 8-8M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
  calplus: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/>',
  cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  video: '<rect x="2" y="6" width="13" height="12" rx="2"/><path d="M22 8l-5 4 5 4z"/>',
  doc: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
  card: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
  calgrid: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
  bell: '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
  mic: '<path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3zM19 10v2a7 7 0 01-14 0v-2M12 19v4M8 23h8"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  pin: '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  download: '<path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
  upload: '<path d="M12 21V9m0 0L8 13m4-4l4 4M4 7V5a2 2 0 012-2h12a2 2 0 012 2v2"/>',
  link: '<path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/>',
  reply: '<path d="M9 17l-5-5 5-5M4 12h11a5 5 0 010 10h-1"/>',
  phone: '<path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3 19.5 19.5 0 01-6-6A19.8 19.8 0 012 4.2 2 2 0 014 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.7a2 2 0 01-.5 2.1L8 9.6a16 16 0 006 6l1.1-1.1a2 2 0 012.1-.5c.9.3 1.8.6 2.7.7A2 2 0 0122 16.9z"/>',
  office: '<path d="M3 21h18M5 21V5a1 1 0 011-1h8a1 1 0 011 1v16M15 21V9h4a1 1 0 011 1v11M8 8h2M8 12h2M8 16h2"/>',
  check: '<path d="M5 12l5 5L20 7"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 6l10 7L22 6"/>',
  file: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
  out: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M9 14l2 2 4-4"/>',
  send: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 11h1v5h1"/>',
  compass: '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
  // أيقونات الـ topbar
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
};

interface IconProps {
  name: string;
  cls?: string;
}

// يطابق: const svg=(k,cls='ic')=>'<svg class="'+cls+'" viewBox="0 0 24 24">'+(I[k]||'')+'</svg>';
export const Icon: React.FC<IconProps> = ({ name, cls = 'ic' }) => (
  <svg
    className={cls}
    viewBox="0 0 24 24"
    dangerouslySetInnerHTML={{ __html: ICON_PATHS[name] || '' }}
  />
);

export default Icon;

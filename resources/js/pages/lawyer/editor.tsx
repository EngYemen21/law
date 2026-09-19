import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import React, { useState, useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { TextStyle } from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import ImageExt from '@tiptap/extension-image';
import LinkExt from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { LEGAL_TEMPLATES, LegalTemplate } from '@/lib/editor-templates';

// ============================================================================
// محرر الصياغة القانونية — WYSIWYG بمستوى Word والذكاء الاصطناعي
// ============================================================================

interface DocData {
  id: number;
  title: string;
  type: string;
  typeLabel: string;
  status: string;
  statusLabel: string;
  contentHtml: string;
  contentJson: object | null;
  metadata: Record<string, unknown> | null;
  headerConfig: HeaderConfig;
  approved: boolean;
}

interface HeaderConfig {
  showHeader: boolean;
  officeName: string;
  officeNameEn: string;
  logoUrl: string;
  address: string;
  phone: string;
  email: string;
  licenseNo: string;
}

interface ImportableItem {
  id: string;
  sourceType: 'case_pleading' | 'ticket_summary' | 'session_summary';
  sourceId: number;
  typeLabel: string;
  badgeTone: string;
  ref: string;
  title: string;
  client: string;
  lawyer: string;
  docType: string;
  date: string;
  preview: string;
  contentHtml: string;
  caseId?: number | null;
  caseNo?: string | null;
  ticketId?: number | null;
  ticketNo?: string | null;
  metadata: Record<string, unknown>;
}

interface Props {
  document: DocData | null;
  types: Record<string, string>;
  ticket: { id: number; no: string } | null;
  case?: { id: number; no: string } | null;
  incomingDraft: string;
  incomingTitle?: string;
  incomingType?: string;
  incomingMeta?: Record<string, unknown> | null;
  incomingTemplate?: string;
  defaultHeader: HeaderConfig;
}

// ── ألوان سريعة للتلوين ──
const COLORS = [
  '#000000', '#1a1a2e', '#16213e', '#0f3460', '#533483',
  '#e94560', '#d63031', '#e17055', '#00b894', '#00cec9',
  '#0984e3', '#6c5ce7', '#fdcb6e', '#e84393', '#636e72',
];

const LawyerEditor: React.FC<Props> = ({
  document: doc,
  types,
  ticket,
  case: initialCase,
  incomingDraft,
  incomingTitle,
  incomingType,
  incomingMeta,
  incomingTemplate,
  defaultHeader,
}) => {
  const toast = useToast();
  const { url } = usePage();
  const base = (url as string).startsWith('/admin') ? '/admin' : '/lawyer';
  const isNew = !doc;

  const matchedTemplate = incomingTemplate
    ? LEGAL_TEMPLATES.find((t) => t.id === incomingTemplate)
    : null;

  const [title, setTitle] = useState(doc?.title || incomingTitle || matchedTemplate?.defaultTitle || '');
  const [type, setType] = useState(doc?.type || incomingType || matchedTemplate?.category || 'free');
  const [meta, setMeta] = useState<Record<string, unknown> | null>(doc?.metadata || incomingMeta || null);
  const [linkedTicket, setLinkedTicket] = useState<{ id: number; no: string } | null>(ticket || null);
  const [linkedCase, setLinkedCase] = useState<{ id: number; no: string } | null>(initialCase || null);
  const [isSaving, setIsSaving] = useState(false);
  const [lastSaved, setLastSaved] = useState<string | null>(doc ? 'الآن' : null);
  const [headerConfig, setHeaderConfig] = useState<HeaderConfig>(doc?.headerConfig || defaultHeader);
  const [showHeaderSettings, setShowHeaderSettings] = useState(false);

  // ── القوالب الجاهزة ──
  const [showTemplateModal, setShowTemplateModal] = useState(false);
  const [templateFilter, setTemplateFilter] = useState<string>('all');
  const [templateSearch, setTemplateSearch] = useState('');

  // ── استيراد المسودات غير المعتمدة ──
  const [showImportModal, setShowImportModal] = useState(false);
  const [importFilter, setImportFilter] = useState<string>('all');
  const [importSearch, setImportSearch] = useState('');
  const [importablesList, setImportablesList] = useState<ImportableItem[]>([]);
  const [loadingImportables, setLoadingImportables] = useState(false);

  // ── المساعد الذكي المدمج ──
  const [showAiDrawer, setShowAiDrawer] = useState(false);
  const [aiAction, setAiAction] = useState<'rephrase' | 'basis' | 'complete' | 'proofread' | 'custom'>('rephrase');
  const [aiCustomPrompt, setAiCustomPrompt] = useState('');
  const [aiLoading, setAiLoading] = useState(false);
  const [aiOutput, setAiOutput] = useState<string | null>(null);

  const autoSaveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ── TipTap editor ──
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3, 4] },
      }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      Underline,
      TextStyle,
      Color,
      Highlight.configure({ multicolor: true }),
      Table.configure({ resizable: true }),
      TableRow,
      TableCell,
      TableHeader,
      ImageExt.configure({ allowBase64: true }),
      LinkExt.configure({ openOnClick: false }),
      Placeholder.configure({
        placeholder: 'ابدأ الكتابة هنا... أو اختر قالباً من القائمة الجاهزة',
      }),
    ],
    content: doc?.contentJson
      ? doc.contentJson
      : matchedTemplate
        ? matchedTemplate.contentHtml
        : incomingDraft
          ? (incomingDraft.trim().startsWith('<') ? incomingDraft : `<p dir="rtl">${incomingDraft.replace(/\n/g, '</p><p dir="rtl">')}</p>`)
          : '<p dir="rtl"></p>',
    editorProps: {
      attributes: {
        class: 'legal-editor-content',
        dir: 'rtl',
      },
    },
    onUpdate: () => {
      // حفظ تلقائي بعد 3 ثوانٍ من آخر تعديل للمستندات المحفوظة
      if (!isNew && doc) {
        if (autoSaveTimer.current) clearTimeout(autoSaveTimer.current);
        autoSaveTimer.current = setTimeout(() => autoSave(), 3000);
      }
    },
  });

  // ── حفظ تلقائي ──
  const autoSave = useCallback(async () => {
    if (!editor || !doc) return;
    try {
      const { data } = await axios.put(`${base}/editor/${doc.id}`, {
        title: title || 'بدون عنوان',
        type,
        content_html: editor.getHTML(),
        content_json: editor.getJSON(),
        header_config: headerConfig,
        ticket_id: linkedTicket?.id || null,
        case_id: linkedCase?.id || null,
        metadata: meta || null,
      });
      setLastSaved(data.updatedAt || 'الآن');
    } catch {
      // صامت — الحفظ اليدوي متاح
    }
  }, [editor, doc, title, type, headerConfig, linkedTicket, linkedCase, meta, base]);

  // ── حفظ يدوي ──
  const save = () => {
    if (!editor) return;
    setIsSaving(true);
    const payload = {
      title: title || 'بدون عنوان',
      type,
      content_html: editor.getHTML(),
      content_json: editor.getJSON(),
      header_config: headerConfig,
      ticket_id: linkedTicket?.id || null,
      case_id: linkedCase?.id || null,
      metadata: meta || null,
    };

    if (isNew) {
      router.post(`${base}/editor`, payload as any, {
        onSuccess: () => toast('✅ تم إنشاء المستند بنجاح'),
        onError: () => toast('تعذر حفظ المستند'),
        onFinish: () => setIsSaving(false),
      });
    } else {
      router.put(`${base}/editor/${doc!.id}`, payload as any, {
        preserveScroll: true,
        onSuccess: () => {
          toast('✅ تم حفظ المستند');
          setLastSaved('الآن');
        },
        onError: () => toast('تعذر حفظ المستند'),
        onFinish: () => setIsSaving(false),
      });
    }
  };

  // ── دوال استيراد المسودات غير المعتمدة ──
  const openImportModal = async () => {
    setShowImportModal(true);
    setLoadingImportables(true);
    try {
      const res = await axios.get(`${base}/editor/importables`);
      setImportablesList(res.data.items || []);
    } catch {
      toast('تعذر جلب المسودات غير المعتمدة');
    } finally {
      setLoadingImportables(false);
    }
  };

  const handleImportDraft = (item: ImportableItem) => {
    if (!editor) return;

    if (editor.getText().trim().length > 10) {
      if (!window.confirm('هل تريد استبدال محتوى المحرر الحالي بهذه المسودة المستوردة؟')) {
        return;
      }
    }

    setTitle(item.title);
    setType(item.docType || 'lawsuit');
    setMeta(item.metadata);

    if (item.ticketId && item.ticketNo) {
      setLinkedTicket({ id: item.ticketId, no: item.ticketNo });
    }
    if (item.caseId && item.caseNo) {
      setLinkedCase({ id: item.caseId, no: item.caseNo });
    }

    editor.commands.setContent(item.contentHtml);
    setShowImportModal(false);
    toast(`⚖️ تم استيراد (${item.typeLabel}) بنجاح وجاهزة للتنسيق`);
  };

  const filteredImportables = importablesList.filter((item) => {
    const matchesFilter = importFilter === 'all' || item.sourceType === importFilter;
    const query = importSearch.trim().toLowerCase();
    const matchesSearch = !query ||
      item.title.toLowerCase().includes(query) ||
      item.ref.toLowerCase().includes(query) ||
      item.client.toLowerCase().includes(query) ||
      item.lawyer.toLowerCase().includes(query) ||
      item.preview.toLowerCase().includes(query);
    return matchesFilter && matchesSearch;
  });

  // ── اعتماد المستند ──
  const approve = () => {
    if (!doc) return;
    router.post(`${base}/editor/${doc.id}/approve`, {}, {
      onSuccess: () => toast('✅ تم اعتماد المستند رسمياً'),
      onError: () => toast('تعذر اعتماد المستند'),
    });
  };

  // ── طباعة ──
  const printDocument = () => {
    if (doc) {
      window.open(`${base}/editor/${doc.id}/print`, '_blank');
    } else {
      window.print();
    }
  };

  // ── تصدير ملف Word (.doc) ──
  const exportToWord = () => {
    if (!editor) return;
    const headerHtml = headerConfig.showHeader
      ? `
        <div style="text-align: center; border-bottom: 2.5px solid #0e5c9c; padding-bottom: 12px; margin-bottom: 24px;">
          <h2 style="color: #0a2a55; margin: 0; font-size: 18pt;">${headerConfig.officeName || 'مكتب المحاماة'}</h2>
          ${headerConfig.officeNameEn ? `<div style="color: #607689; font-size: 11pt;">${headerConfig.officeNameEn}</div>` : ''}
          ${headerConfig.licenseNo ? `<div style="color: #607689; font-size: 10pt;">ترخيص رقم: ${headerConfig.licenseNo}</div>` : ''}
        </div>
      `
      : '';

    const fullHtml = `
      <!DOCTYPE html>
      <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
      <head>
        <meta charset='utf-8'>
        <title>${title || 'مستند قانوني'}</title>
        <style>
          body {
            font-family: 'Tajawal', 'Traditional Arabic', Arial, sans-serif;
            direction: rtl;
            text-align: right;
            font-size: 14pt;
            line-height: 1.85;
            color: #13314f;
          }
          h1, h2, h3, h4 { color: #0a2a55; margin-top: 14pt; margin-bottom: 6pt; }
          table { width: 100%; border-collapse: collapse; margin: 12pt 0; }
          th, td { border: 1px solid #999; padding: 6pt 10pt; text-align: right; }
          th { background: #f0f4f8; font-weight: bold; }
          blockquote { border-right: 4pt solid #0e5c9c; padding: 6pt 12pt; background: #f4f8fb; margin: 10pt 0; }
        </style>
      </head>
      <body>
        ${headerHtml}
        <h1 style="text-align: center; color: #0a2a55;">${title || 'مستند قانوني'}</h1>
        ${editor.getHTML()}
      </body>
      </html>
    `;

    const blob = new Blob(['\ufeff' + fullHtml], { type: 'application/msword' });
    const fileUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = fileUrl;
    a.download = `${title || 'مستند_قانوني'}.doc`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(fileUrl);
    toast('✅ تم تصدير المستند كملف Word بنجاح');
  };

  // ── نسخ المحتوى لناجز ──
  const copyContent = () => {
    if (!editor) return;
    navigator.clipboard?.writeText(editor.getText());
    toast('✅ تم نسخ نص المستند للحافظة (جاهز للّصق في ناجز)');
  };

  // ── تطبيق قالب قانوني جاهز ──
  const applyTemplate = (tmpl: LegalTemplate) => {
    if (!editor) return;
    if (editor.getText().trim().length > 30) {
      if (!window.confirm('سيتم استبدال المحتوى الحالي بمحتوى القالب المحدد. هل ترغب بالمتابعة؟')) {
        return;
      }
    }
    setTitle(tmpl.defaultTitle);
    setType(tmpl.category);
    editor.commands.setContent(tmpl.contentHtml);
    setShowTemplateModal(false);
    toast(`✅ تم تطبيق قالب "${tmpl.name}" بنجاح`);
  };

  // ── تشغيل المساعد الذكي ──
  const runAiAssist = async () => {
    if (!editor) return;
    setAiLoading(true);
    setAiOutput(null);

    const { from, to } = editor.state.selection;
    const selectedText = editor.state.doc.textBetween(from, to, ' ');
    const textToSend = selectedText || editor.getText().slice(-1200) || title;

    try {
      const { data } = await axios.post(`${base}/editor/ai-assist`, {
        action: aiAction,
        text: textToSend,
        prompt: aiCustomPrompt,
      });
      setAiOutput(data.text);
      toast('✨ تم إعداد المقترح الذكي');
    } catch {
      toast('تعذر تشغيل المساعد الذكي حالياً');
    } finally {
      setAiLoading(false);
    }
  };

  // ── إدراج مخرج الذكاء في المحرر ──
  const insertAiOutput = () => {
    if (!editor || !aiOutput) return;
    const formatted = `<p dir="rtl">${aiOutput.replace(/\n\n/g, '</p><p dir="rtl">').replace(/\n/g, '<br/>')}</p>`;
    editor.chain().focus().insertContent(formatted).run();
    toast('✅ تم إدراج النص في المحرر');
    setShowAiDrawer(false);
  };

  // ── فلترة القوالب ──
  const filteredTemplates = LEGAL_TEMPLATES.filter((t) => {
    if (templateFilter !== 'all' && t.category !== templateFilter) return false;
    if (templateSearch && !t.name.includes(templateSearch) && !t.description.includes(templateSearch)) return false;
    return true;
  });

  // ── عدد الكلمات ──
  const wordCount = editor
    ? editor.getText().trim().split(/\s+/).filter(Boolean).length
    : 0;

  const isInsideTable = editor ? editor.can().deleteTable() : false;

  if (!editor) return null;

  return (
    <div className="legal-editor-page">
      {/* ── 1. شريط التنقل والإجراءات العلوي ── */}
      <div className="legal-editor-topbar">
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--muted)' }}>
          <Link href={`${base}/editor`} style={{ color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 4 }}>
            <Icon name="reply" /> المستندات
          </Link>
          <span>/</span>
          <span style={{ color: 'var(--primary)', fontWeight: 700 }}>
            {isNew ? 'مستند جديد' : title || 'بدون عنوان'}
          </span>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          {lastSaved && (
            <span style={{ fontSize: 11, color: 'var(--muted)', display: 'flex', alignItems: 'center', gap: 4 }}>
              <Icon name="check" /> آخر حفظ: {lastSaved}
            </span>
          )}

          {/* زر المساعد الذكي */}
          <button
            type="button"
            className="btn primary sm"
            onClick={() => setShowAiDrawer(true)}
            style={{
              height: 32,
              fontSize: 12,
              gap: 6,
              background: 'linear-gradient(135deg, #0a2a55 0%, #0e5c9c 50%, #11a0c8 100%)',
            }}
          >
            <Icon name="sparkles" /> المساعد الذكي
          </button>

          {/* زر استيراد مسودة غير معتمدة */}
          <button
            type="button"
            className="btn soft sm"
            onClick={openImportModal}
            style={{
              height: 32,
              fontSize: 12,
              gap: 6,
              background: 'rgba(14, 92, 156, 0.08)',
              borderColor: 'rgba(14, 92, 156, 0.3)',
              color: '#0e5c9c',
              fontWeight: 700,
            }}
            title="استيراد مسودة غير معتمدة (لائحة دعوى، ملخص تذكرة، محضر جلسة) لتنسيقها في المحرر"
          >
            <Icon name="download" />
            <span>استيراد مسودة 📥</span>
          </button>

          {/* زر القوالب الجاهزة */}
          <button
            type="button"
            className="btn soft sm"
            onClick={() => setShowTemplateModal(true)}
            style={{ height: 32, fontSize: 12, gap: 5 }}
          >
            <Icon name="folder" /> قوالب جاهزة
          </button>

          {/* زر الحفظ */}
          <button type="button" className="btn soft sm" onClick={save} disabled={isSaving} style={{ height: 32, fontSize: 12 }}>
            <Icon name="check" /> {isSaving ? 'جارٍ الحفظ...' : 'حفظ'}
          </button>

          {/* زر تصدير Word */}
          <button type="button" className="btn soft sm" onClick={exportToWord} style={{ height: 32, fontSize: 12, gap: 5 }} title="تنزيل كملف Word">
            <Icon name="doc" /> Word
          </button>

          {/* زر الطباعة والـ PDF */}
          <button type="button" className="btn soft sm" onClick={printDocument} style={{ height: 32, fontSize: 12, gap: 5 }}>
            <Icon name="upload" /> طباعة / PDF
          </button>

          {/* زر نسخ لناجز */}
          <button type="button" className="btn soft sm" onClick={copyContent} style={{ height: 32, fontSize: 12, gap: 5 }}>
            <Icon name="doc" /> نسخ
          </button>

          {/* اعتماد رسمي من الإدارة */}
          {doc && !doc.approved && (
            <button type="button" className="btn primary sm" onClick={approve} style={{ height: 32, fontSize: 12 }}>
              <Icon name="check" /> اعتماد
            </button>
          )}
        </div>
      </div>

      {/* ── 2. معلومات المستند (العنوان والنوع والترويسة) ── */}
      <div className="legal-editor-meta">
        <input
          type="text"
          className="legal-editor-title-input"
          placeholder="عنوان المستند أو اللائحة..."
          value={title}
          onChange={(e) => setTitle(e.target.value)}
        />
        <select
          className="select sm"
          value={type}
          onChange={(e) => setType(e.target.value)}
          style={{ minWidth: 160 }}
        >
          {Object.entries(types).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>

        <button
          type="button"
          className={`btn ${showHeaderSettings ? 'primary' : 'soft'} sm`}
          onClick={() => setShowHeaderSettings(!showHeaderSettings)}
          style={{ height: 32, fontSize: 12, gap: 5 }}
        >
          <Icon name="compass" /> {headerConfig.showHeader ? 'الترويسة (مفعلة)' : 'الترويسة (مخفية)'}
        </button>

        {linkedTicket && (
          <span style={{ fontSize: 12, color: 'var(--muted)', background: 'var(--paper-2)', padding: '4px 10px', borderRadius: 6, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            <Icon name="ticket" /> تذكرة: <strong>{linkedTicket.no}</strong>
          </span>
        )}

        {linkedCase && (
          <span style={{ fontSize: 12, color: '#0e5c9c', background: 'rgba(14, 92, 156, 0.08)', border: '1px solid rgba(14, 92, 156, 0.2)', padding: '4px 10px', borderRadius: 6, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            <Icon name="folder" /> قضية: <strong>{linkedCase.no}</strong>
          </span>
        )}
      </div>

      {/* ── 2.5. لوحة إعدادات الترويسة ── */}
      {showHeaderSettings && (
        <div className="legal-editor-header-settings">
          <div className="gl" style={{ marginBottom: 10, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>إعدادات الترويسة الرسمية وشعار المنصة</span>
            <button type="button" className="btn soft sm" onClick={() => setShowHeaderSettings(false)} style={{ height: 26, fontSize: 11 }}>إغلاق</button>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 700 }}>
              <input
                type="checkbox"
                checked={headerConfig.showHeader}
                onChange={(e) => setHeaderConfig({ ...headerConfig, showHeader: e.target.checked })}
              />
              إظهار الترويسة في المستند
            </label>
            <input
              className="input sm"
              placeholder="اسم المكتب (عربي)"
              value={headerConfig.officeName}
              onChange={(e) => setHeaderConfig({ ...headerConfig, officeName: e.target.value })}
            />
            <input
              className="input sm"
              placeholder="اسم المكتب (إنجليزي)"
              value={headerConfig.officeNameEn}
              onChange={(e) => setHeaderConfig({ ...headerConfig, officeNameEn: e.target.value })}
            />
            <input
              className="input sm"
              placeholder="رقم ترخيص المحاماة"
              value={headerConfig.licenseNo}
              onChange={(e) => setHeaderConfig({ ...headerConfig, licenseNo: e.target.value })}
            />
            <input
              className="input sm"
              placeholder="العنوان"
              value={headerConfig.address}
              onChange={(e) => setHeaderConfig({ ...headerConfig, address: e.target.value })}
            />
            <input
              className="input sm"
              placeholder="رقم الهاتف"
              value={headerConfig.phone}
              onChange={(e) => setHeaderConfig({ ...headerConfig, phone: e.target.value })}
            />
            <input
              className="input sm"
              placeholder="البريد الإلكتروني"
              value={headerConfig.email}
              onChange={(e) => setHeaderConfig({ ...headerConfig, email: e.target.value })}
            />
          </div>
        </div>
      )}

      {/* ── 3. شريط أدوات التنسيق (Toolbar) ── */}
      <div className="legal-editor-toolbar">
        {/* تنسيق النص الأساسي */}
        <div className="toolbar-group">
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('bold') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleBold().run()}
            title="غامق (Ctrl+B)"
          >
            <strong>غ</strong>
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('italic') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleItalic().run()}
            title="مائل (Ctrl+I)"
          >
            <em>م</em>
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('underline') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleUnderline().run()}
            title="تسطير (Ctrl+U)"
          >
            <u>ت</u>
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('strike') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleStrike().run()}
            title="يتوسطه خط"
          >
            <s>خ</s>
          </button>
        </div>

        <div className="toolbar-divider" />

        {/* العناوين المتدرجة */}
        <div className="toolbar-group">
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('paragraph') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().setParagraph().run()}
            title="نص عادي"
          >
            P
          </button>
          {([1, 2, 3, 4] as const).map((level) => (
            <button
              key={level}
              type="button"
              className={`toolbar-btn ${editor.isActive('heading', { level }) ? 'active' : ''}`}
              onClick={() => editor.chain().focus().toggleHeading({ level }).run()}
              title={`عنوان رئيسي H${level}`}
            >
              H{level}
            </button>
          ))}
        </div>

        <div className="toolbar-divider" />

        {/* محاذاة النص */}
        <div className="toolbar-group">
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive({ textAlign: 'right' }) ? 'active' : ''}`}
            onClick={() => editor.chain().focus().setTextAlign('right').run()}
            title="محاذاة لليمين"
          >
            ≡→
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive({ textAlign: 'center' }) ? 'active' : ''}`}
            onClick={() => editor.chain().focus().setTextAlign('center').run()}
            title="توسيط"
          >
            ≡≡
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive({ textAlign: 'left' }) ? 'active' : ''}`}
            onClick={() => editor.chain().focus().setTextAlign('left').run()}
            title="محاذاة لليسار"
          >
            ←≡
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive({ textAlign: 'justify' }) ? 'active' : ''}`}
            onClick={() => editor.chain().focus().setTextAlign('justify').run()}
            title="ضبط النص (Justify)"
          >
            ≡≡≡
          </button>
        </div>

        <div className="toolbar-divider" />

        {/* القوائم */}
        <div className="toolbar-group">
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('bulletList') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleBulletList().run()}
            title="قائمة نقطية"
          >
            ☰
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('orderedList') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleOrderedList().run()}
            title="قائمة رقمية"
          >
            1.
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('blockquote') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleBlockquote().run()}
            title="اقتباس أو سند نظامي"
          >
            ❝
          </button>
        </div>

        <div className="toolbar-divider" />

        {/* الألوان والتمييز */}
        <div className="toolbar-group">
          <select
            className="toolbar-color-select"
            onChange={(e) => {
              if (e.target.value) {
                editor.chain().focus().setColor(e.target.value).run();
              } else {
                editor.chain().focus().unsetColor().run();
              }
            }}
            title="لون الخط"
          >
            <option value="">🎨 لون</option>
            {COLORS.map((c) => (
              <option key={c} value={c} style={{ color: c }}>
                ■ {c}
              </option>
            ))}
          </select>

          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('highlight') ? 'active' : ''}`}
            onClick={() => editor.chain().focus().toggleHighlight({ color: '#ffeaa7' }).run()}
            title="تمييز خلفية النص"
          >
            ✨
          </button>
        </div>

        <div className="toolbar-divider" />

        {/* إدراج الجداول والوسائط */}
        <div className="toolbar-group">
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}
            title="إدراج جدول 3×3"
          >
            📊 جدول
          </button>
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => editor.chain().focus().setHorizontalRule().run()}
            title="خط فاصل أفقي"
          >
            ― فاصل
          </button>
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => {
              const url = prompt('أدخل رابط الصورة:');
              if (url) editor.chain().focus().setImage({ src: url }).run();
            }}
            title="إدراج صورة"
          >
            🖼️
          </button>
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => {
              const href = prompt('أدخل الرابط:');
              if (href) editor.chain().focus().setLink({ href }).run();
            }}
            title="إدراج رابط"
          >
            🔗
          </button>
        </div>

        <div className="toolbar-divider" />

        {/* أدوات الجدول المتقدمة (تظهر عند الوقوف داخل جدول) */}
        {isInsideTable && (
          <div className="toolbar-group" style={{ background: '#eef6fc', padding: '2px 6px', borderRadius: 6 }}>
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => editor.chain().focus().addRowAfter().run()}
              title="إضافة صف لأسفل"
            >
              +صف
            </button>
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => editor.chain().focus().deleteRow().run()}
              title="حذف الصف الحالي"
            >
              -صف
            </button>
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => editor.chain().focus().addColumnAfter().run()}
              title="إضافة عمود"
            >
              +عمود
            </button>
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => editor.chain().focus().deleteColumn().run()}
              title="حذف العمود"
            >
              -عمود
            </button>
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => editor.chain().focus().deleteTable().run()}
              title="حذف الجدول كاملاً"
              style={{ color: 'var(--red)' }}
            >
              ✕ حذف جدول
            </button>
          </div>
        )}

        {/* تراجع وإعادة */}
        <div className="toolbar-group" style={{ marginInlineStart: 'auto' }}>
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => editor.chain().focus().undo().run()}
            disabled={!editor.can().undo()}
            title="تراجع (Ctrl+Z)"
          >
            ↩
          </button>
          <button
            type="button"
            className="toolbar-btn"
            onClick={() => editor.chain().focus().redo().run()}
            disabled={!editor.can().redo()}
            title="إعادة (Ctrl+Y)"
          >
            ↪
          </button>
        </div>
      </div>

      {/* ── 4. ترويسة المستند الرسمية ── */}
      {headerConfig.showHeader && (
        <div className="legal-editor-header-preview">
          <div className="legal-header-inner">
            <div className="legal-header-logo">
              <img src={headerConfig.logoUrl || '/images/021.png'} alt="شعار المكتب" style={{ maxHeight: 52 }} />
            </div>
            <div className="legal-header-info">
              <div style={{ fontWeight: 800, fontSize: 17, color: '#0a2a55' }}>{headerConfig.officeName || 'مكتب المحاماة والاستشارات'}</div>
              {headerConfig.officeNameEn && (
                <div style={{ fontSize: 12, color: 'var(--muted)', fontFamily: 'sans-serif' }}>{headerConfig.officeNameEn}</div>
              )}
              {headerConfig.licenseNo && (
                <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2 }}>ترخيص رقم: {headerConfig.licenseNo}</div>
              )}
            </div>
            <div className="legal-header-contact">
              {headerConfig.phone && <div style={{ fontSize: 11 }}>هاتف: {headerConfig.phone}</div>}
              {headerConfig.email && <div style={{ fontSize: 11 }}>بريد: {headerConfig.email}</div>}
              {headerConfig.address && <div style={{ fontSize: 11 }}>{headerConfig.address}</div>}
            </div>
          </div>
          <div style={{ borderTop: '2px solid var(--primary)', marginTop: 10 }} />
        </div>
      )}

      {/* ── 5. منطقة التحرير (ورقة A4) ── */}
      <div className="legal-editor-paper">
        <EditorContent editor={editor} />
      </div>

      {/* ── 6. شريط الحالة السفلي ── */}
      <div className="legal-editor-statusbar">
        <span>📝 <strong>{wordCount}</strong> كلمة</span>
        {doc && <span>الحالة: <strong style={{ color: doc.approved ? 'var(--green)' : 'var(--amber)' }}>{doc.statusLabel}</strong></span>}
        {doc?.approved && <span style={{ color: 'var(--green)', fontWeight: 700 }}>✓ معتمد رسمياً</span>}
        <span>💾 حفظ تلقائي مفعّل</span>
      </div>

      {/* ── 7. لوحة المساعد الذكي المدمج (AI Assistant Drawer) ── */}
      {showAiDrawer && typeof document !== 'undefined' && createPortal(
        <div className="legal-ai-drawer-backdrop" onClick={() => setShowAiDrawer(false)}>
          <div className="legal-ai-drawer" onClick={(e) => e.stopPropagation()}>
            <div className="legal-ai-drawer-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 20 }}>🤖</span>
                <strong style={{ fontSize: 15 }}>المساعد القانوني الذكي</strong>
              </div>
              <button
                type="button"
                onClick={() => setShowAiDrawer(false)}
                style={{ color: '#fff', fontSize: 18, cursor: 'pointer', padding: 4 }}
              >
                ✕
              </button>
            </div>

            <div className="legal-ai-drawer-body">
              <div>
                <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 6, color: 'var(--ink)' }}>
                  اختر نوع المساعدة القانونية:
                </label>
                <div className="ai-action-pills">
                  <button
                    type="button"
                    className={`ai-action-pill ${aiAction === 'rephrase' ? 'active' : ''}`}
                    onClick={() => setAiAction('rephrase')}
                  >
                    📝 إعادة صياغة
                  </button>
                  <button
                    type="button"
                    className={`ai-action-pill ${aiAction === 'basis' ? 'active' : ''}`}
                    onClick={() => setAiAction('basis')}
                  >
                    ⚖️ اقتراح سند نظامي
                  </button>
                  <button
                    type="button"
                    className={`ai-action-pill ${aiAction === 'complete' ? 'active' : ''}`}
                    onClick={() => setAiAction('complete')}
                  >
                    ✨ إكمال الفقرة
                  </button>
                  <button
                    type="button"
                    className={`ai-action-pill ${aiAction === 'proofread' ? 'active' : ''}`}
                    onClick={() => setAiAction('proofread')}
                  >
                    🔍 تدقيق لغوي
                  </button>
                  <button
                    type="button"
                    className={`ai-action-pill ${aiAction === 'custom' ? 'active' : ''}`}
                    onClick={() => setAiAction('custom')}
                  >
                    💬 طلب مخصص
                  </button>
                </div>
              </div>

              {aiAction === 'custom' && (
                <div>
                  <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 4 }}>
                    اكتب أمرك للمساعد الذكي:
                  </label>
                  <textarea
                    className="input sm"
                    placeholder="مثال: صغ دفعاً شكلياً بعدم قبول الدعوى لرفعها قبل انتهاء مهلة الإخطار..."
                    value={aiCustomPrompt}
                    onChange={(e) => setAiCustomPrompt(e.target.value)}
                    rows={3}
                    style={{ width: '100%', resize: 'vertical' }}
                  />
                </div>
              )}

              <div style={{ fontSize: 11.5, color: 'var(--muted)', background: 'var(--paper-2)', padding: '8px 10px', borderRadius: 6 }}>
                💡 <em>يعتمد المساعد تلقائياً على النص المحدد في المحرر، أو الفقرة التي يقف عليها المؤشر لتقديم التوصية بدقة.</em>
              </div>

              <button
                type="button"
                className="btn primary"
                onClick={runAiAssist}
                disabled={aiLoading}
                style={{ height: 38, fontSize: 13, gap: 6 }}
              >
                <Icon name="sparkles" /> {aiLoading ? 'جارٍ التحليل والتوليد...' : 'توليد المقترح الذكي ▶'}
              </button>

              {aiOutput && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 10 }}>
                  <label style={{ fontSize: 12, fontWeight: 700, color: 'var(--deep)' }}>المقترح القانوني:</label>
                  <div className="ai-output-box">{aiOutput}</div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <button
                      type="button"
                      className="btn primary sm"
                      onClick={insertAiOutput}
                      style={{ flex: 1, height: 34, fontSize: 12.5 }}
                    >
                      ✓ إدراج في المحرر
                    </button>
                    <button
                      type="button"
                      className="btn soft sm"
                      onClick={() => {
                        navigator.clipboard?.writeText(aiOutput);
                        toast('تم نسخ المقترح');
                      }}
                      style={{ height: 34, fontSize: 12.5 }}
                    >
                      نسخ
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* ── 8. نافذة معرض القوالب القانونية (Template Gallery Modal) ── */}
      {showTemplateModal && typeof document !== 'undefined' && createPortal(
        <div className="legal-modal-backdrop" onClick={() => setShowTemplateModal(false)}>
          <div className="legal-modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="legal-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 17, color: 'var(--deep)' }}>📚 مكتبة القوالب القانونية السعودية</h3>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                  اختر قالباً رسمياً معتمداً لبدء الصياغة فوراً بهيكل قانوني مكتمل
                </span>
              </div>
              <button
                type="button"
                onClick={() => setShowTemplateModal(false)}
                style={{ fontSize: 18, color: 'var(--muted)', cursor: 'pointer', padding: 4 }}
              >
                ✕
              </button>
            </div>

            {/* شريط الفلترة والبحث */}
            <div style={{ padding: '12px 24px', background: 'var(--paper)', borderBottom: '1px solid var(--line)', display: 'flex', gap: 10, flexWrap: 'wrap' }}>
              <input
                type="text"
                className="input sm"
                placeholder="ابحث في القوالب..."
                value={templateSearch}
                onChange={(e) => setTemplateSearch(e.target.value)}
                style={{ flex: 1, minWidth: 200 }}
              />
              <select
                className="select sm"
                value={templateFilter}
                onChange={(e) => setTemplateFilter(e.target.value)}
                style={{ minWidth: 150 }}
              >
                <option value="all">كل التصنيفات ({LEGAL_TEMPLATES.length})</option>
                <option value="lawsuit">لوائح ودعاوى</option>
                <option value="memo">مذكرات قضائية</option>
                <option value="summary">آراء وملخصات</option>
                <option value="contract">عقود واتفاقيات</option>
                <option value="letter">خطابات ومحاضر</option>
                <option value="free">مستند حر</option>
              </select>
            </div>

            {/* شبكة القوالب */}
            <div className="legal-modal-body">
              <div className="templates-grid">
                {filteredTemplates.map((t) => (
                  <div
                    key={t.id}
                    className="template-card"
                    onClick={() => applyTemplate(t)}
                  >
                    <div>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                        <span style={{ fontSize: 24 }}>⚖️</span>
                        <span className="badge-s b-blue">{t.categoryLabel}</span>
                      </div>
                      <h4 style={{ margin: '0 0 6px', fontSize: 15, color: 'var(--ink)' }}>{t.name}</h4>
                      <p style={{ margin: 0, fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.6 }}>
                        {t.description}
                      </p>
                    </div>

                    <div style={{ marginTop: 14, paddingTop: 10, borderTop: '1px solid var(--line-soft)', display: 'flex', justifyContent: 'flex-end' }}>
                      <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--primary)' }}>
                        استخدام القالب ←
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* ── 9. نافذة استيراد المسودات غير المعتمدة (Import Unapproved Drafts Modal) ── */}
      {showImportModal && typeof document !== 'undefined' && createPortal(
        <div className="legal-modal-backdrop" onClick={() => setShowImportModal(false)}>
          <div className="legal-modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="legal-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 17, color: 'var(--deep)' }}>📥 استيراد مسودة غير معتمدة لتنسيقها في المحرر</h3>
                <span style={{ fontSize: 12, color: 'var(--muted)' }}>
                  اختر أي مسودة قضائية أو ملخص تذكرة أو محضر جلسة غير معتمد لتحويله فوراً إلى مستند رسمي جاهز للصياغة والتنسيق والطباعة
                </span>
              </div>
              <button
                type="button"
                onClick={() => setShowImportModal(false)}
                style={{ fontSize: 18, color: 'var(--muted)', cursor: 'pointer', padding: 4 }}
              >
                ✕
              </button>
            </div>

            {/* شريط الفلترة والبحث */}
            <div style={{ padding: '12px 24px', background: 'var(--paper)', borderBottom: '1px solid var(--line)', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
              <input
                type="text"
                className="input sm"
                placeholder="ابحث برقم القضية، التذكرة، العميل، أو المحامي..."
                value={importSearch}
                onChange={(e) => setImportSearch(e.target.value)}
                style={{ flex: 1, minWidth: 220 }}
              />
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className={`chip sm ${importFilter === 'all' ? 'on' : ''}`}
                  onClick={() => setImportFilter('all')}
                >
                  الكل ({importablesList.length})
                </button>
                <button
                  type="button"
                  className={`chip sm ${importFilter === 'case_pleading' ? 'on' : ''}`}
                  onClick={() => setImportFilter('case_pleading')}
                >
                  لوائح قضايا ({importablesList.filter((i) => i.sourceType === 'case_pleading').length})
                </button>
                <button
                  type="button"
                  className={`chip sm ${importFilter === 'ticket_summary' ? 'on' : ''}`}
                  onClick={() => setImportFilter('ticket_summary')}
                >
                  ملخصات تذاكر ({importablesList.filter((i) => i.sourceType === 'ticket_summary').length})
                </button>
                <button
                  type="button"
                  className={`chip sm ${importFilter === 'session_summary' ? 'on' : ''}`}
                  onClick={() => setImportFilter('session_summary')}
                >
                  جلسات استشارية ({importablesList.filter((i) => i.sourceType === 'session_summary').length})
                </button>
              </div>
            </div>

            {/* قائمة المسودات */}
            <div className="legal-modal-body">
              {loadingImportables ? (
                <div style={{ textAlign: 'center', padding: '40px 0', color: 'var(--muted)' }}>
                  <Icon name="reply" /> جارٍ جلب المسودات غير المعتمدة...
                </div>
              ) : filteredImportables.length > 0 ? (
                <div className="importables-list">
                  {filteredImportables.map((item) => (
                    <div key={item.id} className="importable-card">
                      <div className="importable-card-header">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                          <span className={`badge-s ${item.badgeTone}`}>{item.typeLabel}</span>
                          <strong style={{ fontSize: 14, color: '#0a2a55' }}>{item.ref}</strong>
                          <span style={{ fontSize: 13, color: 'var(--muted)' }}>·</span>
                          <span style={{ fontSize: 13, color: 'var(--ink)', fontWeight: 600 }}>{item.title}</span>
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                          <span style={{ fontSize: 12, color: 'var(--muted)' }}>{item.date}</span>
                          <button
                            type="button"
                            className="btn primary sm"
                            onClick={() => handleImportDraft(item)}
                            style={{ height: 30, fontSize: 12, gap: 5 }}
                          >
                            <Icon name="check" /> استيراد في المحرر
                          </button>
                        </div>
                      </div>

                      <div style={{ display: 'flex', gap: 16, fontSize: 12, color: 'var(--muted)', flexWrap: 'wrap' }}>
                        <span>العميل: <b>{item.client}</b></span>
                        <span>المستشار / المحامي: <b>{item.lawyer}</b></span>
                      </div>

                      {item.preview && (
                        <div className="importable-preview-box">
                          {item.preview}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div style={{ textAlign: 'center', padding: '48px 0', color: 'var(--muted)' }}>
                  <Icon name="check" />
                  <div style={{ fontSize: 14, fontWeight: 600, marginTop: 8 }}>
                    لا توجد مسودات غير معتمدة مطابقة في هذا التصنيف
                  </div>
                  <div style={{ fontSize: 12, marginTop: 4 }}>
                    جميع لوائح الدعاوى وملخصات التذاكر والجلسات معتمدة أو لا توجد مسودات معلقة حالياً.
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default LawyerEditor;

import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import React, { useState, useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import { TextStyle, FontFamily, FontSize, LineHeight } from '@tiptap/extension-text-style';
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
import { useConfirm, usePrompt } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import { LEGAL_TEMPLATES, LegalTemplate } from '@/lib/editor-templates';
import { firstError } from '@/lib/server-message';

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
  canApprove?: boolean;
}

// ── ألوان سريعة للتلوين ──
const COLORS = [
  { label: 'كحلي قضائي', value: '#0a2a55' },
  { label: 'أزرق ملكي', value: '#0e5c9c' },
  { label: 'كحلي غامق', value: '#13314f' },
  { label: 'أسود داكن', value: '#000000' },
  { label: 'أحمر تحذيري', value: '#d63031' },
  { label: 'أخضر معتمد', value: '#00b894' },
  { label: 'برتقالي مميز', value: '#e17055' },
  { label: 'بنفسجي نظامي', value: '#6c5ce7' },
  { label: 'رمادي مسودة', value: '#636e72' },
];

// ── الخطوط القضائية المعتمدة ──
const LEGAL_FONTS = [
  { label: 'خط تجوال (افتراضي قياسي)', value: 'Tajawal, sans-serif' },
  { label: 'الخط الأميري (قضائي/عقود)', value: 'Amiri, serif' },
  { label: 'الخط التقليدي (المحاكم/ناجز)', value: '"Traditional Arabic", Arial, sans-serif' },
  { label: 'الخط البسيط (Simplified Arabic)', value: '"Simplified Arabic", Arial, sans-serif' },
  { label: 'خط كايرو (عصري متوازن)', value: 'Cairo, sans-serif' },
];

// ── أحجام الخطوط القضائية القياسية ──
const LEGAL_FONT_SIZES = [
  { label: '12pt — هوامش وملاحظات', value: '12pt' },
  { label: '14pt — صلب النص القضائي المعتمد', value: '14pt' },
  { label: '16pt — عناوين فرعية وبنود', value: '16pt' },
  { label: '18pt — عناوين الأقسام الرئيسية', value: '18pt' },
  { label: '22pt — عنوان المستند والدعوى', value: '22pt' },
];

// ── تباعد الأسطر القانوني ──
const LEGAL_LINE_SPACINGS = [
  { label: '1.15 — تباعد مضغوط', value: '1.15' },
  { label: '1.50 — تباعد قياسي مريح', value: '1.5' },
  { label: '1.85 — التباعد القضائي المعتمد', value: '1.85' },
  { label: '2.00 — تباعد مزدوج للتدقيق', value: '2.0' },
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
  canApprove = false,
}) => {
  const ask = useConfirm();
  const askFor = usePrompt();
  const toast = useToast();
  const { url } = usePage();
  const base = (url as string).startsWith('/admin')
    ? '/admin'
    : (url as string).startsWith('/employee')
      ? '/employee'
      : '/lawyer';
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

  // ── مؤقت نبضات واجهة المحرر لتحديث تفاعلية الشريط فوراً ──
  const [, setEditorTick] = useState(0);

  // ── مرجع رفع الصور والأختام محلياً ──
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const handleLocalImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      toast('⚠️ حجم الصورة كبير جداً، الحد الأقصى 5 ميجابايت');
      return;
    }
    const reader = new FileReader();
    reader.onload = (event) => {
      const src = event.target?.result as string;
      if (src && editor) {
        editor.chain().focus().setImage({ src, alt: file.name }).run();
        toast('🖼️ تم إدراج الصورة/الختم في المستند');
      }
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  };

  // ── إدراج بنود قانونية قضائية نموذجية ──
  const insertLegalClause = (clauseType: string) => {
    if (!editor) return;
    let html = '';
    switch (clauseType) {
      case 'basmala':
        html = '<p style="text-align: center; margin-bottom: 16px;"><strong>بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</strong></p><p><strong>فضيلة رئيس وأعضاء الدائرة القضائية الموقرين،،،</strong><br/><strong>السلام عليكم ورحمة الله وبركاته،، وبعد:</strong></p>';
        break;
      case 'capacity':
        html = '<p>بصفتي وكيلاً شرعياً ونظامياً عن المدعي بموجب الوكالة الشرعية رقم (...) وتاريخ (.../ .../ ....هـ) الصادرة من وزارة العدل، أتقدم لعدالتكم بلائحتنا هذه بياناً لما يلي:</p>';
        break;
      case 'facts':
        html = '<h2>⚖️ أولاً: الوقائع</h2><p>1- حيث إنه بتاريخ (.../ .../ ....هـ)، اتفق موكلي مع المدعى عليه على [...].</p><p>2- وحيث إن المدعى عليه قد أخل بالتزاماته المترتبة عليه والمتمثلة في [...].</p>';
        break;
      case 'grounds':
        html = '<h2>📜 ثانياً: الأسانيد الشرعية والنظامية</h2><blockquote>استناداً إلى القاعدة الشرعية القطعية: «المسلمون على شروطهم»، ولقول النبي ﷺ: «لا ضرر ولا ضرار».</blockquote><blockquote>واستناداً إلى أحكام المادة (...) من نظام (...) الصادر بالمرسوم الملكي رقم (...)، والتي نصت على: «[...]».</blockquote>';
        break;
      case 'requests':
        html = '<h2>🎯 ثالثاً: الطلبات الختامية</h2><p>بناءً على ما تقدم من وقائع وأسانيد، نلتمس من فضيلتكم الموقرة التفضل بالحكم بـ:</p><ol><li><strong>أصلياً:</strong> إلزام المدعى عليه بـ [...].</li><li><strong>احتياطياً:</strong> ندب خبير هندسي / محاسبي لتحديد قيمة المطالبة والضرر.</li><li>إلزام المدعى عليه بأتعاب المحاماة ومصاريف التقاضي.</li></ol>';
        break;
      case 'closing':
        html = '<div style="margin-top: 28px; text-align: left;"><p><strong>وتفضلوا بقبول فائق التقدير والاحترام،،،</strong><br/><strong>مقدمه لفضيلتكم وكيل المدعي:</strong> .............................<br/><strong>رقم الترخيص / الوكالة:</strong> .............................&nbsp;&nbsp;&nbsp;&nbsp;<strong>التاريخ:</strong> .../ .../ .....هـ<br/><strong>التوقيع:</strong> .............................&nbsp;&nbsp;&nbsp;&nbsp;<strong>الختم الرسمي:</strong></p></div>';
        break;
      default:
        return;
    }
    editor.chain().focus().insertContent(html).run();
    toast('⚖️ تم إدراج البند القضائي بنجاح');
  };

  // ── تحويل النصوص النقية إلى HTML منظم ──
  const normalizeTextToHtml = (text: string) => {
    if (!text) return '<p dir="rtl"></p>';
    if (text.trim().startsWith('<')) return text;
    return `<p dir="rtl">${text.replace(/\r\n/g, '\n').replace(/\n\n+/g, '</p><p dir="rtl">').replace(/\n/g, '<br/>')}</p>`;
  };

  // ── مراجع حية لتفادي مشكلة الـ Stale Closure في الحفظ التلقائي ──
  const titleRef = useRef(title);
  const typeRef = useRef(type);
  const metaRef = useRef(meta);
  const headerConfigRef = useRef(headerConfig);
  const linkedTicketRef = useRef(linkedTicket);
  const linkedCaseRef = useRef(linkedCase);
  const docRef = useRef(doc);
  const isNewRef = useRef(isNew);

  useEffect(() => { titleRef.current = title; }, [title]);
  useEffect(() => { typeRef.current = type; }, [type]);
  useEffect(() => { metaRef.current = meta; }, [meta]);
  useEffect(() => { headerConfigRef.current = headerConfig; }, [headerConfig]);
  useEffect(() => { linkedTicketRef.current = linkedTicket; }, [linkedTicket]);
  useEffect(() => { linkedCaseRef.current = linkedCase; }, [linkedCase]);
  useEffect(() => {
    docRef.current = doc;
    isNewRef.current = isNew;
  }, [doc, isNew]);

  const autoSaveRef = useRef<() => Promise<void>>(async () => {});

  // ── TipTap editor ──
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3, 4] },
      }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      Underline,
      TextStyle,
      FontFamily,
      FontSize,
      LineHeight.configure({ types: ['textStyle', 'paragraph', 'heading'] }),
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
    content: (doc?.contentJson && Object.keys(doc.contentJson).length > 0)
      ? doc.contentJson
      : doc?.contentHtml
        ? doc.contentHtml
        : matchedTemplate
          ? matchedTemplate.contentHtml
          : incomingDraft
            ? normalizeTextToHtml(incomingDraft)
            : '<p dir="rtl"></p>',
    editorProps: {
      attributes: {
        // `legal-doc`: تنسيق المستند الواحد مع PDF وWord (resources/css/legal-document.css)
        class: 'legal-editor-content legal-doc',
        dir: 'rtl',
      },
    },
    onSelectionUpdate: () => {
      setEditorTick((t) => t + 1);
    },
    onTransaction: () => {
      setEditorTick((t) => t + 1);
    },
    onUpdate: () => {
      // حفظ تلقائي بعد 3 ثوانٍ من آخر تعديل للمستندات المحفوظة
      if (!isNewRef.current && docRef.current) {
        if (autoSaveTimer.current) clearTimeout(autoSaveTimer.current);
        autoSaveTimer.current = setTimeout(() => {
          autoSaveRef.current();
        }, 3000);
      }
    },
  });

  // ── مزامنة المحتوى إذا تغيّر المستند المعروض دون إعادة تحميل الصفحة ──
  const lastDocIdRef = useRef<number | null>(doc?.id || null);
  useEffect(() => {
    if (editor && doc && doc.id !== lastDocIdRef.current) {
      lastDocIdRef.current = doc.id;
      setTitle(doc.title || '');
      setType(doc.type || 'free');
      setMeta(doc.metadata || null);
      setHeaderConfig(doc.headerConfig || defaultHeader);
      setLinkedTicket(ticket || null);
      setLinkedCase(initialCase || null);
      setLastSaved('الآن');

      const targetContent = (doc.contentJson && Object.keys(doc.contentJson).length > 0)
        ? doc.contentJson
        : (doc.contentHtml || '<p dir="rtl"></p>');

      editor.commands.setContent(targetContent);
    }
  }, [doc, editor, defaultHeader, ticket, initialCase]);

  // ── حفظ تلقائي يقرأ أحدث القيم من الـ Refs لتفادي أي كتابة فوق العنوان الجديد ──
  const autoSave = useCallback(async () => {
    const currentDoc = docRef.current;
    if (!editor || !currentDoc || isNewRef.current) return;
    try {
      const { data } = await axios.put(`${base}/editor/${currentDoc.id}`, {
        title: titleRef.current || 'بدون عنوان',
        type: typeRef.current,
        content_html: editor.getHTML(),
        content_json: editor.getJSON(),
        header_config: headerConfigRef.current,
        ticket_id: linkedTicketRef.current?.id || null,
        case_id: linkedCaseRef.current?.id || null,
        metadata: metaRef.current || null,
      });
      setLastSaved(data.updatedAt || 'الآن');
    } catch {
      // صامت — الحفظ اليدوي متاح
    }
  }, [editor, base]);

  useEffect(() => {
    autoSaveRef.current = autoSave;
  }, [autoSave]);

  /** أوّل رسالة من أخطاء الخادم، أو بديلٌ حين لا رسالة */

  // ── حفظ يدوي ──
  const save = () => persist();

  /** يحفظ ثمّ ينفّذ `after` عند النجاح — التنزيل يأخذ المحفوظ، فيُحفظ ما على الشاشة قبله. */
  const persist = (after?: () => void) => {
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

    // رسالة النجاح من الخادم (`with('success')`) يعرضها التخطيط مرّةً — كان هنا توستٌ ثانٍ لها.
    // والخطأ نصُّ الخادم (العنوان مطلوب، النوع غير صالح…) لا «تعذّر» عامّة تُخفي سببها.
    if (isNew) {
      router.post(`${base}/editor`, payload as any, {
        onError: (errs) => toast(`⚠️ ${firstError(errs, 'تعذّر حفظ المستند')}`, 'error'),
        onFinish: () => setIsSaving(false),
      });
    } else {
      router.put(`${base}/editor/${doc!.id}`, payload as any, {
        preserveScroll: true,
        onSuccess: () => {
          setLastSaved('الآن');
          after?.();
        },
        onError: (errs) => toast(`⚠️ ${firstError(errs, 'تعذّر حفظ المستند')}`, 'error'),
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

  const handleImportDraft = async (item: ImportableItem) => {
    if (!editor) return;

    if (editor.getText().trim().length > 10) {
      const ok = await ask({
        title: 'استبدال محتوى المحرّر',
        message: 'المحرّر ليس فارغاً — تحلّ المسودّة المستوردة محلّ نصّه الحاليّ.',
        confirmLabel: 'استبدال',
        tone: 'danger',
      });

      if (!ok) {
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
      onError: (errs) => toast(`⚠️ ${firstError(errs, 'تعذّر اعتماد المستند')}`, 'error'),
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

  // ── التنزيل: PDF وWord من الخادم، من المحفوظ نفسه وبتنسيق المحرّر (legal-document.css · LegalDocx) ──
  // كان PDF يأخذ آخر محفوظ وWord ما على الشاشة (صفحة HTML باسم ‎.doc) فيختلفان — الآن يُحفظ أوّلاً ثمّ يُنزَّل الاثنان من الخادم.
  const download = (format: 'pdf' | 'docx') => {
    if (isNew || !doc) {
      toast('يرجى حفظ المستند أولاً ثمّ تنزيله');
      return;
    }
    toast(format === 'pdf' ? '⏳ جارٍ حفظ المستند وتجهيز ملف PDF…' : '⏳ جارٍ حفظ المستند وتجهيز ملف Word…');
    persist(() => {
      window.location.href = `${base}/editor/${doc.id}/${format}`;
    });
  };
  const downloadPdf = () => download('pdf');
  const exportToWord = () => download('docx');

  // ── نسخ المحتوى لناجز ──
  const copyContent = () => {
    if (!editor) return;
    navigator.clipboard?.writeText(editor.getText());
    toast('✅ تم نسخ نص المستند للحافظة (جاهز للّصق في ناجز)');
  };

  // ── تطبيق قالب قانوني جاهز ──
  const applyTemplate = async (tmpl: LegalTemplate) => {
    if (!editor) return;
    if (editor.getText().trim().length > 30) {
      const ok = await ask({
        title: 'تطبيق القالب',
        message: 'المحرّر ليس فارغاً — يحلّ محتوى القالب محلّ نصّه الحاليّ.',
        confirmLabel: 'تطبيق القالب',
        tone: 'danger',
      });

      if (!ok) {
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

  const isInsideTable = editor ? (editor.isActive('table') || editor.can().deleteTable()) : false;

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

          {/* زر تحميل PDF حقيقي عبر Browsershot */}
          <button
            type="button"
            className="btn soft sm"
            onClick={downloadPdf}
            style={{ height: 32, fontSize: 12, gap: 5 }}
            title="تحميل المستند كملف PDF حقيقي"
          >
            <Icon name="download" /> تحميل PDF
          </button>

          {/* زر الطباعة والمعاينة */}
          <button
            type="button"
            className="btn soft sm"
            onClick={printDocument}
            style={{ height: 32, fontSize: 12, gap: 5 }}
            title="معاينة المستند والطباعة الورقية"
          >
            <Icon name="upload" /> طباعة
          </button>

          {/* زر نسخ لناجز */}
          <button type="button" className="btn soft sm" onClick={copyContent} style={{ height: 32, fontSize: 12, gap: 5 }}>
            <Icon name="doc" /> نسخ
          </button>

          {/* اعتماد رسمي من الإدارة أو المستشار المصرح له */}
          {doc && !doc.approved && canApprove && (
            <button type="button" className="btn primary sm" onClick={approve} style={{ height: 32, fontSize: 12 }}>
              <Icon name="check" /> اعتماد
            </button>
          )}
          {doc && !doc.approved && !canApprove && (
            <span
              className="chip"
              style={{
                height: 32,
                fontSize: 11.5,
                background: '#f8fafc',
                border: '1px solid #e2e8f0',
                color: '#64748b',
                display: 'inline-flex',
                alignItems: 'center',
                gap: 5,
                padding: '0 10px',
              }}
              title="الاعتماد النهائي يتطلب صلاحية «اعتماد الصياغة القانونية»"
            >
              <Icon name="lock" style={{ width: 13, height: 13 }} /> مسودة (بانتظار الاعتماد)
            </span>
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
        {/* ملف مخفي لرفع الصور والأختام من الجهاز */}
        <input
          type="file"
          ref={fileInputRef}
          accept="image/*"
          style={{ display: 'none' }}
          onChange={handleLocalImageUpload}
        />

        {/* 1. تراجع وإعادة */}
        <div className="toolbar-group">
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

        <div className="toolbar-divider" />

        {/* 2. الخطوط القضائية والأحجام والتباعد (Legal Typography) */}
        <div className="toolbar-group">
          <select
            className="toolbar-select"
            value=""
            onChange={(e) => {
              const val = e.target.value;
              if (val) editor.chain().focus().setFontFamily(val).run();
              e.target.value = '';
            }}
            title="الخطوط القضائية المعتمدة"
          >
            <option value="" disabled hidden>🔤 نوع الخط</option>
            {LEGAL_FONTS.map((f) => (
              <option key={f.value} value={f.value} style={{ fontFamily: f.value }}>
                {f.label}
              </option>
            ))}
          </select>

          <select
            className="toolbar-select"
            value=""
            onChange={(e) => {
              const val = e.target.value;
              if (val) editor.chain().focus().setFontSize(val).run();
              e.target.value = '';
            }}
            title="حجم الخط القضائي"
          >
            <option value="" disabled hidden>📏 الحجم</option>
            {LEGAL_FONT_SIZES.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>

          <select
            className="toolbar-select"
            value=""
            onChange={(e) => {
              const val = e.target.value;
              if (val) editor.chain().focus().setLineHeight(val).run();
              e.target.value = '';
            }}
            title="تباعد الأسطر القضائي"
          >
            <option value="" disabled hidden>↕️ التباعد</option>
            {LEGAL_LINE_SPACINGS.map((lh) => (
              <option key={lh.value} value={lh.value}>
                {lh.label}
              </option>
            ))}
          </select>
        </div>

        <div className="toolbar-divider" />

        {/* 3. بنود وهيكلية الصياغة القضائية المتخصصة */}
        <div className="toolbar-group">
          <select
            className="toolbar-select"
            value=""
            onChange={(e) => {
              if (e.target.value) insertLegalClause(e.target.value);
              e.target.value = '';
            }}
            title="إدراج بند قضائي نموذجي جاهز"
            style={{ borderColor: '#0e5c9c', color: '#0e5c9c', fontWeight: 700 }}
          >
            <option value="" disabled hidden>📜 صياغة البنود القضائية</option>
            <option value="basmala">﷽ ديباجة وتحية الدائرة الموقرة</option>
            <option value="capacity">⚖️ التمهيد وبيان صفة التمثيل الشرعي</option>
            <option value="facts">📋 أولاً: بند الوقائع وتفاصيل الدعوى</option>
            <option value="grounds">📜 ثانياً: بند الأسانيد الشرعية والنظامية</option>
            <option value="requests">🎯 ثالثاً: بند الطلبات الختامية للمحكمة</option>
            <option value="closing">✍️ خاتمة الدعوى واعتماد التوقيع والختم</option>
          </select>
        </div>

        <div className="toolbar-divider" />

        {/* 4. تنسيق النص الأساسي */}
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

        {/* 5. الألوان والتمييز */}
        <div className="toolbar-group">
          <select
            className="toolbar-select"
            value=""
            onChange={(e) => {
              const val = e.target.value;
              if (val === 'unset') {
                editor.chain().focus().unsetColor().run();
              } else if (val) {
                editor.chain().focus().setColor(val).run();
              }
              e.target.value = '';
            }}
            title="لون الخط"
          >
            <option value="" disabled hidden>🎨 لون الخط</option>
            <option value="unset">تلقائي (افتراضي)</option>
            {COLORS.map((c) => (
              <option key={c.value} value={c.value} style={{ color: c.value, fontWeight: 'bold' }}>
                ■ {c.label}
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

        {/* 6. العناوين المتدرجة */}
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

        {/* 7. محاذاة النص */}
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

        {/* 8. القوائم والاقتباسات */}
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

        {/* 9. إدراج الجداول والوسائط */}
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
            onClick={() => fileInputRef.current?.click()}
            title="إدراج صورة أو ختم أو توقيع من الجهاز"
          >
            🖼️ ختم/صورة
          </button>
          <button
            type="button"
            className="toolbar-btn"
            onClick={async () => {
              const url = await askFor({ title: 'إدراج صورة من رابط', label: 'رابط الصورة', placeholder: 'https://' });
              if (url && url.trim()) editor.chain().focus().setImage({ src: url.trim() }).run();
            }}
            title="إدراج صورة من رابط إنترنت"
          >
            🌐
          </button>
          <button
            type="button"
            className={`toolbar-btn ${editor.isActive('link') ? 'active' : ''}`}
            onClick={async () => {
              const previousUrl = editor.getAttributes('link').href || '';
              // `required: false` عمداً: إفراغُ الحقل ثمّ التأكيد هو كيف يُلغى الرابط
              const href = await askFor({
                title: 'رابط الموقع',
                label: 'الرابط',
                message: 'أفرغ الحقل ثمّ أكّد لإلغاء الرابط الحاليّ.',
                defaultValue: previousUrl || 'https://',
                required: false,
              });
              if (href === null) return;
              if (href.trim() === '') {
                editor.chain().focus().unsetLink().run();
                toast('🔗 تم إلغاء الرابط');
              } else {
                editor.chain().focus().setLink({ href: href.trim() }).run();
                toast('🔗 تم تطبيق الرابط');
              }
            }}
            title={editor.isActive('link') ? 'تعديل الرابط الحالي' : 'إدراج رابط'}
          >
            🔗
          </button>
          {editor.isActive('link') && (
            <button
              type="button"
              className="toolbar-btn"
              onClick={() => {
                editor.chain().focus().unsetLink().run();
                toast('🔗 تم إلغاء الرابط');
              }}
              title="إلغاء الرابط الحالي"
              style={{ color: 'var(--red)', fontSize: 11.5 }}
            >
              ✕ فك
            </button>
          )}
        </div>

        {/* 10. أدوات الجدول المتقدمة (تظهر فوراً عند الوقوف داخل أي خلية جدول) */}
        {isInsideTable && (
          <>
            <div className="toolbar-divider" />
            <div className="toolbar-table-tools">
              <span style={{ fontSize: 11, fontWeight: 700, color: '#0e5c9c', padding: '0 4px' }}>الجدول:</span>
              <button
                type="button"
                className="toolbar-table-btn"
                onClick={() => editor.chain().focus().addRowBefore().run()}
                title="إضافة صف لأعلى"
              >
                +صف أعلى
              </button>
              <button
                type="button"
                className="toolbar-table-btn"
                onClick={() => editor.chain().focus().addRowAfter().run()}
                title="إضافة صف لأسفل"
              >
                +صف أسفل
              </button>
              <button
                type="button"
                className="toolbar-table-btn danger"
                onClick={() => editor.chain().focus().deleteRow().run()}
                title="حذف الصف الحالي"
              >
                -صف
              </button>
              <button
                type="button"
                className="toolbar-table-btn"
                onClick={() => editor.chain().focus().addColumnBefore().run()}
                title="إضافة عمود يمين"
              >
                +عمود يمين
              </button>
              <button
                type="button"
                className="toolbar-table-btn"
                onClick={() => editor.chain().focus().addColumnAfter().run()}
                title="إضافة عمود يسار"
              >
                +عمود يسار
              </button>
              <button
                type="button"
                className="toolbar-table-btn danger"
                onClick={() => editor.chain().focus().deleteColumn().run()}
                title="حذف العمود الحالي"
              >
                -عمود
              </button>
              <button
                type="button"
                className="toolbar-table-btn"
                onClick={() => editor.chain().focus().toggleHeaderRow().run()}
                title="تبديل صف الترويسة"
              >
                صف ترويسة
              </button>
              <button
                type="button"
                className="toolbar-table-btn danger"
                onClick={async () => {
                  if (await ask({ title: 'حذف الجدول', message: 'يُحذف الجدول كاملاً بصفوفه وأعمدته.', confirmLabel: 'حذف', tone: 'danger' })) {
                    editor.chain().focus().deleteTable().run();
                  }
                }}
                title="حذف الجدول كاملاً"
              >
                ✕ حذف الجدول
              </button>
            </div>
          </>
        )}
      </div>

      {/* ── 4. ترويسة المستند الرسمية ── */}
      {headerConfig.showHeader && (
        <div className="legal-editor-header-preview">
          <div className="legal-header-inner">
            <div className="legal-header-logo">
              <img src={headerConfig.logoUrl || '/images/021.png'} alt="شعار المكتب" style={{ maxHeight: 52 }} />
            </div>
            <div className="legal-header-info">
              <div style={{ fontWeight: 800, fontSize: 17, color: '#0a2a55' }}>{headerConfig.officeName || defaultHeader.officeName}</div>
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

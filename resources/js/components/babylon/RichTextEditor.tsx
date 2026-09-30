import Color from '@tiptap/extension-color';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import React, { useEffect, useState } from 'react';

/**
 * **محرّر نصٍّ منسّق مشترك** (ملخّص التذكرة أوّلاً — 2026-09-30).
 *
 * أدواتٌ محدودة بما يحتاجه ملخّصٌ يصل العميل: عريض/مائل/تسطير، عنوان، قائمة نقطيّة ومرقّمة، محاذاة، لون،
 * تراجع. المخرَج HTML يُنقّى في الخادم (`RichHtml::clean`) عند الحفظ وعند العرض — فالمكوّن لا يحرس شيئاً.
 * التنسيق من `legal-document.css` (`.legal-doc`) نفسه الذي في محرّر الصياغة وPDF.
 */

const COLORS = [
  { value: '#13314f', label: 'أساسي' },
  { value: '#0e5c9c', label: 'أزرق' },
  { value: '#1e9d6b', label: 'أخضر' },
  { value: '#c0392b', label: 'أحمر' },
];

interface Props {
  value: string;
  onChange: (html: string) => void;
  readOnly?: boolean;
  placeholder?: string;
}

const RichTextEditor: React.FC<Props> = ({ value, onChange, readOnly = false, placeholder }) => {
  // إعادة رسم الشريط مع كلّ تحديد — حالة الأزرار (مفعّل/غير مفعّل) تُقرأ من المحرّر
  const [, setTick] = useState(0);

  const editor = useEditor({
    editable: !readOnly,
    extensions: [
      StarterKit.configure({ heading: { levels: [3, 4] }, link: false }),
      TextAlign.configure({ types: ['heading', 'paragraph'], defaultAlignment: 'right' }),
      TextStyle,
      Color,
    ],
    content: value,
    editorProps: { attributes: { class: 'legal-doc rte-content', dir: 'rtl', 'data-placeholder': placeholder ?? '' } },
    onUpdate: ({ editor: e }) => onChange(e.isEmpty ? '' : e.getHTML()),
    onTransaction: () => setTick((t) => t + 1),
  });

  // `false`: بلا حدث تحديث — كان `setEditable` يُطلقه افتراضيّاً عند التحميل، فيصل النموذجَ نصُّ المحرّر المُعاد
  // تشكيله (محاذاةٌ صريحة، فقرةٌ داخل عنصر القائمة) كأنّ المستخدم حرّره: فيُجاز قالبٌ لم يلمسه أحد، ويُعدّ «تعديلاً»
  useEffect(() => {
    editor?.setEditable(!readOnly, false);
  }, [editor, readOnly]);

  // قيمةٌ جاءت من خارج المحرّر (إعادة توليد، تحميل) — لا تُعاد عند كتابة المستخدم نفسه
  useEffect(() => {
    if (editor && value !== editor.getHTML() && !(value === '' && editor.isEmpty)) {
      editor.commands.setContent(value, { emitUpdate: false });
    }
  }, [editor, value]);

  if (!editor) {
    return null;
  }

  const btn = (label: React.ReactNode, title: string, active: boolean, run: () => void, disabled = false) => (
    <button type="button" className={`toolbar-btn${active ? ' active' : ''}`} title={title} disabled={disabled} onMouseDown={(e) => e.preventDefault()} onClick={run}>
      {label}
    </button>
  );
  const chain = () => editor.chain().focus();

  return (
    <div className={`rte${readOnly ? ' rte-readonly' : ''}`}>
      {!readOnly && (
        <div className="rte-toolbar">
          <div className="toolbar-group">
            {btn('↩', 'تراجع', false, () => chain().undo().run(), !editor.can().undo())}
            {btn('↪', 'إعادة', false, () => chain().redo().run(), !editor.can().redo())}
          </div>
          <div className="toolbar-divider" />
          <div className="toolbar-group">
            {btn(<b>B</b>, 'عريض', editor.isActive('bold'), () => chain().toggleBold().run())}
            {btn(<i>I</i>, 'مائل', editor.isActive('italic'), () => chain().toggleItalic().run())}
            {btn(<u>U</u>, 'تسطير', editor.isActive('underline'), () => chain().toggleUnderline().run())}
          </div>
          <div className="toolbar-divider" />
          <div className="toolbar-group">
            {btn('عنوان', 'عنوان', editor.isActive('heading', { level: 3 }), () => chain().toggleHeading({ level: 3 }).run())}
            {btn('• قائمة', 'قائمة نقطيّة', editor.isActive('bulletList'), () => chain().toggleBulletList().run())}
            {btn('1. قائمة', 'قائمة مرقّمة', editor.isActive('orderedList'), () => chain().toggleOrderedList().run())}
          </div>
          <div className="toolbar-divider" />
          <div className="toolbar-group">
            {btn('⇥', 'محاذاة يمين', editor.isActive({ textAlign: 'right' }), () => chain().setTextAlign('right').run())}
            {btn('↔', 'توسيط', editor.isActive({ textAlign: 'center' }), () => chain().setTextAlign('center').run())}
            {btn('⇤', 'محاذاة يسار', editor.isActive({ textAlign: 'left' }), () => chain().setTextAlign('left').run())}
            {btn('☰', 'ضبط', editor.isActive({ textAlign: 'justify' }), () => chain().setTextAlign('justify').run())}
          </div>
          <div className="toolbar-divider" />
          <div className="toolbar-group">
            {COLORS.map((c) => (
              <button
                key={c.value}
                type="button"
                className="rte-color"
                title={`لون ${c.label}`}
                style={{ background: c.value }}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => (c.value === COLORS[0].value ? chain().unsetColor().run() : chain().setColor(c.value).run())}
              />
            ))}
          </div>
        </div>
      )}
      <EditorContent editor={editor} />
    </div>
  );
};

/** نصّ HTML المنسّق بلا وسوم — للعدّ والنسخ (الكتل أسطر، وعناصر القوائم «• »). */
export function htmlToText(html: string): string {
  if (!html) {
    return '';
  }

  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll('li').forEach((li) => li.prepend('• '));
  doc.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
  doc.querySelectorAll('p, h1, h2, h3, h4, li').forEach((el) => el.append('\n'));

  return (doc.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim();
}

/** عرض HTML منسّقٍ **منقّى في الخادم** (`TicketSummary::html` / `RichHtml::clean`) — لا يُمرَّر إليه غيره. */
export const RichHtmlView: React.FC<{ html: string; className?: string }> = ({ html, className }) => (
  <div className={`legal-doc rich-view ${className ?? ''}`} dir="rtl" dangerouslySetInnerHTML={{ __html: html }} />
);

export default RichTextEditor;

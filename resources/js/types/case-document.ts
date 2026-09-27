/*
 * **مستندات القضيّة — نوعٌ واحد لكلّ بانٍ في الخادم** (خطّة «إزالة التعارض» — المرحلة ٣). كان نوع
 * المستند مُعرَّفاً أربع مرّاتٍ بحقولٍ متباعدة (اختياريّ هنا وإلزاميّ هناك، و`downloadUrl` غائبٌ في
 * محادثة العميل)، ومستند التذكرة المرتبطة ثلاث مرّات.
 */

/** مستند التذكرة المرتبطة بالقضيّة — `CaseTicketDocuments::for` (بلا «غير مرتبط»). */
export interface TicketDocumentCard {
  id: number;
  name: string;
  /** المكتب · العميل */
  by: string;
  status: string;
  docType: string;
  summary: string;
  date: string;
  /** `null` لمن لا تُجيزه `ConversationFiles` — الخادم يقرّر لا الشاشة. */
  downloadUrl: string | null;
}

/** مستند القضيّة — `CaseDocument::toData`. */
export interface CaseDocumentCard extends TicketDocumentCard {
  hearingId: number | null;
  hearingTitle: string | null;
}

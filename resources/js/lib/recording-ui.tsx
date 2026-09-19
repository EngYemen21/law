import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Modal from '@/components/babylon/Modal';
import Icon from '@/lib/icons';

// ============================================================
// مخرجات جلسات Zoom داخل النظام — تشغيلٌ وتنزيلٌ ونصٌّ عبر الخادم
// ============================================================

/**
 * **ما يتوفّر من مخرجات الجلسة** — يطابق `RecordingArchive::availability` على الخادم.
 *
 * أعلامٌ لا روابط (قرار المالك 2026-09-15): كانت البطاقات تحمل روابط سحابة Zoom فتفتحها
 * الأزرار في نافذةٍ خارج النظام. «متاح» = لدى Zoom مصدر أو الملفّ محفوظ؛ «جاهز» = على القرص.
 */
export interface SessionMedia {
  video: boolean;
  audio: boolean;
  transcript: boolean;
  videoReady: boolean;
  audioReady: boolean;
  transcriptReady: boolean;
  /** الموظّف بلا «تشغيل تسجيلات الجلسات» — كلّ ما سبق `false` (قرار المالك 2026-09-18). */
  locked?: boolean;
}

/** المسارات الداخليّة لمخرجات جلسةٍ واحدة — تُبنى بمسار الدور فلا يُكتب رابطٌ يدويّاً في الشاشات. */
export interface SessionMediaUrls {
  video: string;
  audio: string;
  transcript: string;
  streamVideo: string;
  streamAudio: string;
}

/** مسارات مخرجات الاستشارة لدورٍ ما (`/admin` · `/employee` · `/lawyer`). */
export function consultMediaUrls(base: string, consultId: number): SessionMediaUrls {
  const root = `${base}/consults/${consultId}`;

  return {
    video: `${root}/recording.zip`,
    audio: `${root}/audio.zip`,
    transcript: `${root}/transcript.txt`,
    streamVideo: `${root}/stream/video`,
    streamAudio: `${root}/stream/audio`,
  };
}

/** مسارات مخرجات الاجتماع لدورٍ ما. */
export function meetingMediaUrls(base: string, meetingId: number): SessionMediaUrls {
  const root = `${base}/meetings/${meetingId}`;

  return {
    video: `${root}/recording.zip`,
    audio: `${root}/audio.zip`,
    transcript: `${root}/transcript`,
    streamVideo: `${root}/stream/video`,
    streamAudio: `${root}/stream/audio`,
  };
}

/**
 * **زرُّ وسيطٍ يقول ما سيفعله.**
 *
 * حين يكون الملفّ مبنيّاً فهو رابط تنزيلٍ عاديّ. وحين لا يكون، كان الزرّ نفسه يَعِد
 * بالتنزيل ثمّ يردّ الخادم `back()` — فتومض الصفحة ولا ينزل شيء، ويُعيد المستخدم
 * النقر ظنّاً أنّ الأولى ضاعت، فتُجدوَل مهمّةُ بناءٍ في كلّ نقرة.
 *
 * صار غيرُ الجاهز طلبَ تحضيرٍ صريحاً: نصُّه «تحضير» لا «تنزيل»، ويُرسل مرّةً واحدة
 * (`asked` يمنع التكرار)، ويعرض ردَّ الخادم بدل ابتلاعه.
 */
export const MediaButton: React.FC<{
  href: string;
  ready: boolean;
  icon: string;
  label: string;
  grow?: boolean;
}> = ({ href, ready, icon, label, grow }) => {
  const [asked, setAsked] = useState(false);
  const style = grow ? { flex: 1, justifyContent: 'center' } : undefined;

  if (ready) {
    return (
      <a className="btn soft sm" style={style} href={href} title={`تنزيل ${label}`}>
        <Icon name={icon} /> {label}
      </a>
    );
  }

  return (
    <button
      type="button"
      className="btn soft sm"
      style={{ ...style, opacity: asked ? 0.6 : 1 }}
      disabled={asked}
      title={`${label} — يُحضَّر من سحابة Zoom ثمّ يصلك إشعار`}
      onClick={() => {
        setAsked(true);
        router.visit(href, { preserveScroll: true, preserveState: true });
      }}
    >
      <Icon name={asked ? 'clock' : icon} /> {asked ? 'قيد التحضير' : `تحضير ${label}`}
    </button>
  );
};

/**
 * **مشغّلٌ مضمَّن** — الفيديو أو الصوت داخل الصفحة من الملفّ المحفوظ على الخادم.
 *
 * يُفتح بنقرة لا تلقائياً: تحميل وسائط كلّ جلسةٍ عند فتح الصفحة يستهلك الشبكة بلا طلب.
 * لا يُعرض إلا لملفٍّ جاهز — غيرُ الجاهز يمرّ بزرّ «تحضير» في `MediaButton`.
 */
export const InlinePlayer: React.FC<{ src: string; kind: 'video' | 'audio'; label: string }> = ({ src, kind, label }) => {
  const [open, setOpen] = useState(false);

  if (!open) {
    return (
      <button type="button" className="btn sm" onClick={() => setOpen(true)}>
        <Icon name={kind === 'video' ? 'video' : 'mic'} /> {label}
      </button>
    );
  }

  return (
    <div style={{ flexBasis: '100%', display: 'flex', flexDirection: 'column', gap: 6 }}>
      {kind === 'video' ? (
        <video controls autoPlay preload="metadata" src={src} style={{ width: '100%', maxHeight: 420, borderRadius: 8, background: '#000' }}>
          متصفّحك لا يدعم تشغيل الفيديو — نزّل الملفّ بدلاً من ذلك.
        </video>
      ) : (
        <audio controls autoPlay preload="metadata" src={src} style={{ width: '100%' }}>
          متصفّحك لا يدعم تشغيل الصوت — نزّل الملفّ بدلاً من ذلك.
        </audio>
      )}
      <button type="button" className="btn soft sm" style={{ alignSelf: 'flex-start' }} onClick={() => setOpen(false)}>
        <Icon name="close" /> إغلاق المشغّل
      </button>
    </div>
  );
};

/**
 * **لوحة مخرجات الجلسة** — مصدرٌ واحد لصفحات الاستشارة والاجتماع ودرج الإدارة.
 *
 * لكلّ وسيط: تشغيلٌ داخل الصفحة حين يكون جاهزاً، وزرّ تنزيلٍ أو تحضير. والنصّ رابطُ تنزيلٍ
 * مباشر لأنّ الخادم يجلبه عند الطلب ويحفظه (`RecordingArchive::transcript`)، ومعه عرضٌ
 * داخل الصفحة إن مُرِّر `onViewTranscript`. لا رابطَ خارج النظام في أيّ زرّ.
 */
export const SessionMediaPanel: React.FC<{
  media: SessionMedia;
  urls: SessionMediaUrls;
  onViewTranscript?: () => void;
}> = ({ media, urls, onViewTranscript }) => {
  // السببُ لا «لا تسجيل»: قد يكون للجلسة تسجيلٌ لا يملك هذا الموظّف صلاحيّة تشغيله
  if (media.locked) {
    return <span style={{ fontSize: 12.5, color: 'var(--muted)' }}>تشغيل التسجيلات بصلاحيّة «تشغيل تسجيلات الجلسات» — تمنحها الإدارة</span>;
  }

  if (!media.video && !media.audio && !media.transcript) {
    return <span style={{ fontSize: 12.5, color: 'var(--muted)' }}>لا يوجد تسجيل لهذه الجلسة بعد</span>;
  }

  return (
    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
      {media.video && media.videoReady && <InlinePlayer src={urls.streamVideo} kind="video" label="تشغيل التسجيل المرئيّ" />}
      {media.video && <MediaButton href={urls.video} ready={media.videoReady} icon="download" label="الفيديو (MP4)" />}
      {media.audio && media.audioReady && <InlinePlayer src={urls.streamAudio} kind="audio" label="تشغيل الصوت" />}
      {media.audio && <MediaButton href={urls.audio} ready={media.audioReady} icon="download" label="الصوت (M4A)" />}
      {media.transcript && (
        <a className="btn soft sm" href={urls.transcript} title="تنزيل النصّ التفريغيّ للجلسة">
          <Icon name="doc" /> تنزيل النصّ
        </a>
      )}
      {media.transcript && onViewTranscript && (
        <button type="button" className="btn soft sm" onClick={onViewTranscript}>
          <Icon name="doc" /> عرض النصّ
        </button>
      )}
    </div>
  );
};

/** سطرٌ من النصّ الحرفيّ: الوقت والمتحدّث والكلام. */
export interface TranscriptRow {
  time: string;
  speaker: string;
  text: string;
}

/**
 * يحوّل نصّ Zoom (VTT) إلى أسطر — كما ورد بلا تعديل.
 * سطر فاصل الجزء («— الجزء N (01:11) —») ليس متحدّثاً، والملفّ القديم المنظَّف من التوقيت يُعرض أسطراً بلا وقت.
 */
export function parseTranscript(vtt: string): TranscriptRow[] {
  const rows: TranscriptRow[] = [];

  for (const block of vtt.replace(/^WEBVTT[^\n]*\n?/u, '').split(/\n\s*\n/)) {
    const lines = block.split('\n').map((l) => l.trim()).filter(Boolean);
    const ti = lines.findIndex((l) => l.includes('-->'));

    if (ti === -1) {
      continue;
    }

    const time = (lines[ti].split('-->')[0] ?? '').trim().replace(/\.\d+$/u, '');
    const speech = lines.slice(ti + 1).join(' ');

    if (!speech) {
      continue;
    }

    const mSp = speech.startsWith('—') ? null : speech.match(/^([^:]{1,60}):\s*(.*)$/u);
    rows.push({ time, speaker: mSp ? mSp[1] : '—', text: mSp ? mSp[2] : speech });
  }

  if (rows.length === 0 && vtt.trim() !== '') {
    for (const line of vtt.split('\n').map((l) => l.trim()).filter(Boolean)) {
      const mSp = line.match(/^([^:]{1,60}):\s*(.*)$/u);
      rows.push({ time: '', speaker: mSp ? mSp[1] : '—', text: mSp ? mSp[2] : line });
    }
  }

  return rows;
}

/**
 * **النصّ الحرفيّ داخل نافذة** — يُجلب من مسار الخادم عند أوّل فتح ويُحفظ للفتحات التالية.
 * يُعرض كما ورد: المتحدّث والوقت والكلام، بلا أيّ تعديل.
 */
export const TranscriptModal: React.FC<{ title: string; url: string; open: boolean; onClose: () => void }> = ({ title, url, open, onClose }) => {
  const [rows, setRows] = useState<TranscriptRow[] | null>(null);
  const [failed, setFailed] = useState(false);
  const loaded = rows !== null;

  useEffect(() => {
    if (!open || loaded) {
      return;
    }

    let cancelled = false;
    fetch(url, { credentials: 'same-origin' })
      .then((r) => {
        if (!r.ok) {
          throw new Error(String(r.status));
        }

        return r.text();
      })
      .then((vtt) => {
        if (!cancelled) {
          setRows(parseTranscript(vtt));
        }
      })
      .catch(() => {
        if (!cancelled) {
          setRows([]);
          setFailed(true);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [open, loaded, url]);

  return (
    <Modal title={title} open={open} onClose={onClose}>
      <div style={{ maxHeight: '60vh', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8 }}>
        {rows === null ? (
          <p style={{ color: 'var(--muted)', fontSize: 13 }}>جارٍ جلب النص من الخادم…</p>
        ) : rows.length === 0 ? (
          <p style={{ color: 'var(--muted)', fontSize: 13 }}>
            {failed ? 'تعذّر جلب النص — تأكد من توفره لدى Zoom ثم أعد المحاولة.' : 'لا نصّ متاحاً لهذه الجلسة — يتوفر بعد جلسة فعلية مسجَّلة لدى Zoom.'}
          </p>
        ) : rows.map((r, i) => (
          <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 10px', background: 'var(--paper-2)', borderRadius: 8 }}>
            <span style={{ fontFamily: 'monospace', fontSize: 11.5, color: 'var(--faint)', whiteSpace: 'nowrap', paddingTop: 2 }}>{r.time}</span>
            <div style={{ minWidth: 0 }}>
              <b style={{ fontSize: 12.5, color: 'var(--primary)', display: 'block' }}>{r.speaker}</b>
              <span style={{ fontSize: 13, lineHeight: 1.7, wordBreak: 'break-word' }}>{r.text}</span>
            </div>
          </div>
        ))}
      </div>
    </Modal>
  );
};

import React, { useEffect, useState } from 'react';

// مُنتقي مشترك للمستشارين المتخصّصين + الفترات المتاحة — يستخدمه /book وحجز التذكرة.
// يجلب التفرّغ من نقطة الخادم (مرتّب بالذكاء الاصطناعي + سجلّ النجاح)، ويُصدر الاختيار للأب.

export interface Slot { time: string; taken: boolean; }
export interface Success { rate: number; closed: number; total: number; }
export interface LawyerOpt { id: number; name: string; dept: string; success: Success; load: number; slots: Slot[]; freeCount: number; }

export const todayISO = (): string => new Date().toISOString().slice(0, 10);

// يحجب اختيار وقت انقضى فعلاً (اليوم الحالي فقط — الفترات كلّها بالساعة HH:00). مشترك مع منتقيات الموظف.
export const isPastSlot = (date: string, t: string): boolean =>
  date === todayISO() && parseInt(t.slice(0, 2), 10) <= new Date().getHours();

interface Props {
  fetchUrl: string;                          // '/book/availability' أو `/tickets/{no}/availability`
  fetchParams?: Record<string, string>;      // معطيات إضافية (specialty/subject لصفحة /book)
  enabled: boolean;                          // لا يجلب قبل اكتمال الشروط (مثل اختيار التخصّص)
  date: string;                              // اليوم المختار (متحكّم من الأب)
  onDateSnap: (d: string) => void;           // قفزة الخادم لأقرب يوم عمل
  lawyerId: number | null;
  onLawyerChange: (id: number | null) => void;
  time: string;
  onTimeChange: (t: string) => void;
  autoAssign?: boolean;                      // العميل يختار الوقت فقط؛ النظام يُسند أعلى مختصّ متاح
}

const SpecialistPicker: React.FC<Props> = ({
  fetchUrl, fetchParams = {}, enabled, date, onDateSnap, lawyerId, onLawyerChange, time, onTimeChange, autoAssign = false,
}) => {
  const [lawyers, setLawyers] = useState<LawyerOpt[]>([]);
  const [loading, setLoading] = useState(false);
  const selected = lawyers.find((l) => l.id === lawyerId) || null;
  const paramsKey = JSON.stringify(fetchParams);

  useEffect(() => {
    if (!enabled || !date) { setLawyers([]); return; }
    let cancelled = false;
    setLoading(true);
    const params = new URLSearchParams({ ...fetchParams, date });
    fetch(`${fetchUrl}?${params.toString()}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => r.json())
      .then((json: { date: string; lawyers: LawyerOpt[] }) => {
        if (cancelled) return;
        const list = json.lawyers || [];
        setLawyers(list);
        if (json.date && json.date !== date) onDateSnap(json.date); // اقفز لأقرب يوم عمل
        if (!list.some((l) => l.id === lawyerId)) onLawyerChange(null); // اختيار زائل
        onTimeChange('');
      })
      .catch(() => { if (!cancelled) setLawyers([]); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetchUrl, paramsKey, date, enabled]);

  if (!enabled) return null;

  // اتحاد الأوقات عبر كل المختصّين، ومَن يُسنَد لكل وقت (أعلى مرتّب متاح)
  const times = lawyers[0]?.slots ?? [];
  const freeAt = (t: string) => lawyers.filter((l) => l.slots.some((s) => s.time === t && !s.taken));
  const assigned = time ? (freeAt(time)[0] ?? null) : null;

  // الاسم الأول فقط (لقب + أول اسم) — لا يُظهَر الاسم الكامل للعميل
  const firstName = (n: string) => {
    const p = n.trim().split(/\s+/);
    return /^(أ|د|م|الأستاذ|الأستاذة|المحامي|المحامية)\.?$/.test(p[0]) && p.length > 1 ? `${p[0]} ${p[1]}` : (p[0] || n);
  };

  // العميل يختار الوقت فقط؛ النظام يُسند أعلى مختصّ متاح — ولا يُظهَر إلا اسمه الأول
  if (autoAssign) {
    return (
      <>
        {loading && <p className="sub">جارٍ جلب الأوقات المتاحة…</p>}
        {!loading && times.length === 0 && <p className="sub">لا يوجد مستشارون متاحون في هذا اليوم — غيّر التاريخ.</p>}
        {!loading && times.length > 0 && (
          <>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--ink)', margin: '4px 0 6px' }}>
              اختر الوقت المتاح
            </label>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {times.map((s) => {
                const past = isPastSlot(date, s.time);
                const free = !past && freeAt(s.time).length > 0;
                return (
                  <button
                    key={s.time}
                    type="button"
                    className={`btn ${time === s.time ? '' : 'soft'} sm`}
                    disabled={!free}
                    title={past ? 'انقضى الوقت' : free ? 'متاح' : 'محجوز'}
                    style={!free ? { opacity: 0.4, textDecoration: 'line-through' } : undefined}
                    onClick={() => { onTimeChange(s.time); onLawyerChange(freeAt(s.time)[0]?.id ?? null); }}
                  >
                    {s.time}
                  </button>
                );
              })}
            </div>
            {assigned
              ? <p className="sub" style={{ marginTop: 10 }}>سيتولّى استشارتك: <b>{firstName(assigned.name)}</b></p>
              : <p className="sub" style={{ marginTop: 10 }}>يُسند النظام المستشار المختصّ تلقائيّاً حسب نوع طلبك.</p>}
          </>
        )}
      </>
    );
  }

  return (
    <>
      {loading && <p className="sub">جارٍ جلب المتاحين…</p>}
      {!loading && lawyers.length === 0 && <p className="sub">لا يوجد مستشارون متاحون حالياً.</p>}
      {!loading && lawyers.map((l, i) => {
        const busyDay = l.freeCount === 0;
        const isSel = l.id === lawyerId;
        return (
          <div
            key={l.id}
            className={`card${isSel ? ' sel' : ''}`}
            style={{ marginBottom: 10, opacity: busyDay ? 0.6 : 1, borderColor: isSel ? 'var(--brand)' : undefined }}
          >
            <div className="card-b" style={{ padding: 12, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
              <div style={{ flex: 1, minWidth: 200 }}>
                <b>{i === 0 && !busyDay ? '⭐ ' : ''}{l.name}</b>
                <div className="sub">{l.dept}</div>
                <div style={{ marginTop: 4, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <span className="chip">معدّل الإنجاز {l.success.rate}% · {l.success.closed} مغلقة</span>
                  <span className="chip">{busyDay ? 'مشغول هذا اليوم' : `${l.freeCount} فترة متاحة`}</span>
                </div>
              </div>
              <button className="btn soft sm" type="button" disabled={busyDay} onClick={() => { onLawyerChange(l.id); onTimeChange(''); }}>
                {isSel ? '✓ مختار' : busyDay ? 'لا فترات' : 'اختيار'}
              </button>
            </div>
          </div>
        );
      })}

      {selected && selected.freeCount > 0 && (
        <>
          <label style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--ink)', margin: '12px 0 6px' }}>
            الوقت المتاح — {selected.name}
          </label>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {selected.slots.map((s) => {
              const past = isPastSlot(date, s.time);
              const blocked = s.taken || past;
              return (
                <button
                  key={s.time}
                  type="button"
                  className={`btn ${time === s.time ? '' : 'soft'} sm`}
                  disabled={blocked}
                  title={past ? 'انقضى الوقت' : s.taken ? 'محجوز' : 'متاح'}
                  style={blocked ? { opacity: 0.4, textDecoration: 'line-through' } : undefined}
                  onClick={() => onTimeChange(s.time)}
                >
                  {s.time}
                </button>
              );
            })}
          </div>
        </>
      )}
      {!loading && selected && selected.freeCount === 0 && (
        <p className="sub">المستشار المختار مشغول في هذا اليوم — اختر مستشاراً آخر من الأعلى أو غيّر التاريخ.</p>
      )}
    </>
  );
};

export default SpecialistPicker;

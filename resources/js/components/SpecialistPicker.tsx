import React, { useEffect, useState } from 'react';

// مُنتقي مشترك للمستشارين المتخصّصين + الفترات المتاحة — يستخدمه /book وحجز التذكرة.
// يجلب التفرّغ من نقطة الخادم (مرتّب بالذكاء الاصطناعي + سجلّ النجاح)، ويُصدر الاختيار للأب.

export interface Slot { time: string; taken: boolean; }
export interface Success { rate: number; closed: number; total: number; }
export interface LawyerOpt { id: number; name: string; dept: string; success: Success; load: number; slots: Slot[]; freeCount: number; }

export const todayISO = (): string => new Date().toISOString().slice(0, 10);

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
}

const SpecialistPicker: React.FC<Props> = ({
  fetchUrl, fetchParams = {}, enabled, date, onDateSnap, lawyerId, onLawyerChange, time, onTimeChange,
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
            {selected.slots.map((s) => (
              <button
                key={s.time}
                type="button"
                className={`btn ${time === s.time ? '' : 'soft'} sm`}
                disabled={s.taken}
                title={s.taken ? 'محجوز' : 'متاح'}
                style={s.taken ? { opacity: 0.4, textDecoration: 'line-through' } : undefined}
                onClick={() => onTimeChange(s.time)}
              >
                {s.time}
              </button>
            ))}
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

import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { normalizeDigits } from '@/lib/digits';
import { EXEC_CLOSE_REASONS, execMoney } from '@/lib/exec-flow';
import type { ExecNajiz } from '@/lib/exec-flow';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';

// ============================================================
// مسار التنفيذ في ناجز داخل المرحلتين 7 و8 (قرار المالك 2026-09-12).
// بطاقةٌ واحدة للمحامي المسنَد وللمكتب: رفع الطلب ← القيد ← أمر التنفيذ ومهلة الوفاء ←
// إجراءات عدم الوفاء ← التحصيل ← الإنهاء بسببه. كلّ خطوة تُرسَل إلى موزّع الإجراءات نفسه
// (`POST /exec-flow/{n}/action`) فلا مسارات ولا حرّاس مكرّرة.
// ============================================================

/**
 * إجراءات عدم الوفاء — **احتياطيٌّ وحده**: القائمة الحيّة تصل في `najiz.measureOptions` من
 * `ExecFlow::MEASURES`؛ ونسخةٌ يدويّة هنا كانت تتباعد عن الخادم فتعرض خياراً يسقطه `array_intersect`.
 */
export const EXEC_MEASURES = ['منع السفر', 'إيقاف الخدمات الحكومية', 'إيقاف إصدار الوكالات', 'الإفصاح عن الأموال والحجز عليها', 'الحجز على المركبات والعقارات', 'البيع بالمزاد', 'الحبس التنفيذيّ'];

type Step = 'file' | 'register' | 'notify' | 'measures' | 'collect' | 'amount' | 'close';

/**
 * `canAct`: المحامي المسنَد أو المكتب المصرَّح له — والخادم يرفض من سواه بالرسالة نفسها.
 * `canClose`: الإنهاء أضيق من باقي الخطوات (محامٍ/إدارة)، فلا يُشتقّ من `canAct` إلّا افتراضاً.
 * `stage`: 7 «بانتظار الرفع في ناجز» أو 8 «قيد التنفيذ» — وما عداهما لا تُعرض البطاقة.
 */
export const ExecNajizCard: React.FC<{ base: string; najiz: ExecNajiz; stage: number; canAct: boolean; canClose?: boolean }> = ({ base, najiz, stage, canAct, canClose = canAct }) => {
  const [open, setOpen] = useState<Step | null>(null);
  // قفلٌ موحّد: خطوات ناجز (ومنها تسجيل مبلغٍ محصَّل) لا تُسجَّل مرّتين بنقرةٍ مزدوجة
  const action = useServerAction();
  const busy = action.busy;
  const [filing, setFiling] = useState({ request_no: '', filed_at: '' });
  const [reg, setReg] = useState({ court: najiz.court || '', circuit: '', registered_at: '' });
  const [notified, setNotified] = useState('');
  const [picked, setPicked] = useState<string[]>(najiz.measures ?? []);
  const [collect, setCollect] = useState({ amount: '', note: '' });
  const [claim, setClaim] = useState({ amount: '', reason: '' });
  const [closeReason, setCloseReason] = useState('');

  if (stage < 7 && !najiz.requestNo) {
    return null;
  }

  // قوائم الخادم متى وصلت، والثوابت المحلّيّة احتياطاً وحدها — فلا تتباعد الشاشة عن `ExecFlow` بصمت
  const measureOptions = najiz.measureOptions?.length ? najiz.measureOptions : EXEC_MEASURES;
  const closeReasons = najiz.closeReasons?.length ? najiz.closeReasons : EXEC_CLOSE_REASONS;
  const pickedReason = closeReason || closeReasons[0];
  const remaining = Math.max(0, (najiz.amount || 0) - (najiz.collected || 0));
  const isFullyCollected = (najiz.amount || 0) > 0 && remaining <= 0;
  const collectAmount = normalizeDigits(collect.amount);
  const collectOk = /^\d+$/.test(collectAmount) && Number(collectAmount) > 0 && Number(collectAmount) <= remaining && remaining > 0;
  const collectNum = Number(collectAmount);
  const exceedsRemaining = /^\d+$/.test(collectAmount) && collectNum > remaining;
  // ملفٌّ بلا مبلغ مطالبة لا يُحصَّل عليه — يُحدَّد المبلغ أوّلاً (كانت رسالة «يتجاوز المتبقي 0» تضلّل)
  const noClaimAmount = (najiz.amount || 0) <= 0;
  const claimAmount = normalizeDigits(claim.amount);
  const claimOk = /^\d+$/.test(claimAmount) && Number(claimAmount) > 0 && claim.reason.trim().length >= 10;
  // ملفٌّ أُنهي أو أُرشف (المرحلة 9) لا تُسجَّل عليه خطوة — الخادم يردّها، فلا تُعرض أزرارها
  const locked = Boolean(najiz.closedReason) || stage >= 9;

  const act = (name: string, payload: Record<string, string | string[]>, ok: string) => {
    void action.run(`${base}/action`, {
      data: { action: name, ...payload },
      success: ok,
      onSuccess: () => setOpen(null),
    });
  };
  const toggle = (m: string) => setPicked((prev) => (prev.includes(m) ? prev.filter((x) => x !== m) : [...prev, m]));
  const badge: [string, string] = najiz.closedReason
    ? [`أُنهي — ${najiz.closedReason}`, 'b-grey']
    : najiz.registeredAt
      ? ['مقيّد لدى محكمة التنفيذ', 'b-green']
      : najiz.requestNo
        ? ['مرفوع — بانتظار القيد', 'b-amber']
        : ['لم يُرفع بعد', 'b-amber'];

  return (
    <div className="card">
      <div className="card-h">
        <h3>التنفيذ في ناجز</h3>
        <Badge text={badge[0]} tone={badge[1]} />
      </div>
      <div className="card-b" style={{ padding: 14 }}>
        <div className="tc-body" style={{ padding: 0, marginBottom: 10 }}>
          {najiz.requestNo && <div className="tc-row"><span className="k">رقم الطلب في ناجز</span><span className="v">{najiz.requestNo}{najiz.filedAt ? ` · ${najiz.filedAt}` : ''}</span></div>}
          {najiz.court && <div className="tc-row"><span className="k">محكمة التنفيذ</span><span className="v">{najiz.court}{najiz.circuit ? ` — ${najiz.circuit}` : ''}</span></div>}
          {najiz.registeredAt && <div className="tc-row"><span className="k">تاريخ القيد</span><span className="v">{najiz.registeredAt}</span></div>}
          {najiz.notifiedAt && (
            <div className="tc-row">
              <span className="k">إبلاغ المنفَّذ ضدّه</span>
              <span className="v">{najiz.notifiedAt} · مهلة الوفاء حتى {najiz.payDueAt}{najiz.payDueOver ? ' (انقضت)' : ''}</span>
            </div>
          )}
          {najiz.measures?.length > 0 && <div className="tc-row"><span className="k">إجراءات عدم الوفاء</span><span className="v">{najiz.measures.join(' · ')}</span></div>}
          <div className="tc-row"><span className="k">مبلغ المطالبة</span><span className="v">{noClaimAmount ? 'لم يُحدَّد' : `${execMoney(najiz.amount)} ريال`}</span></div>
          {(najiz.amount > 0 || najiz.collected > 0) && (
            <div className="tc-row"><span className="k">المحصَّل</span><span className="v">{execMoney(najiz.collected)} من {execMoney(najiz.amount)} ريال · المتبقّي {execMoney(remaining)}</span></div>
          )}
        </div>

        {najiz.payDueOver && !najiz.measures?.length && (
          <div className="action-hint" style={{ marginBottom: 10 }}>
            <Icon name="alert" /> انقضت مهلة الوفاء ولم تُسجَّل إجراءات — يُطلب اتّخاذها في ناجز.
          </div>
        )}

        {!canAct && <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>للاطّلاع — تسجيل خطوات ناجز من صلاحيّة المكتب المسنَد.</div>}
        {canAct && locked && <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>أُنهي الملفّ — لا تُسجَّل عليه خطوات جديدة.</div>}

        {canAct && !locked && noClaimAmount && (
          <div className="action-hint" style={{ marginBottom: 10 }}>
            <Icon name="alert" /> لم يُحدَّد مبلغ المطالبة — {canClose && najiz.amountEditable ? 'حدّده أوّلاً ليُسجَّل عليه التحصيل.' : 'يحدّده المحامي المسنَد أو الإدارة قبل تسجيل أيّ تحصيل.'}
          </div>
        )}

        {canAct && !locked && (
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {!najiz.requestNo && <button className="btn sm" type="button" onClick={() => setOpen(open === 'file' ? null : 'file')}><Icon name="send" /> تسجيل رفع الطلب</button>}
            {najiz.requestNo && !najiz.registeredAt && <button className="btn sm" type="button" onClick={() => setOpen(open === 'register' ? null : 'register')}><Icon name="check" /> تسجيل القيد</button>}
            {najiz.registeredAt && !najiz.notifiedAt && <button className="btn sm" type="button" onClick={() => setOpen(open === 'notify' ? null : 'notify')}><Icon name="cal" /> تسجيل الإبلاغ بأمر التنفيذ</button>}
            {najiz.notifiedAt && <button className="btn soft sm" type="button" onClick={() => setOpen(open === 'measures' ? null : 'measures')}><Icon name="scale" /> إجراءات عدم الوفاء</button>}
            {najiz.registeredAt && (
              <button
                className="btn soft sm"
                type="button"
                onClick={() => setOpen(open === 'collect' ? null : 'collect')}
                disabled={isFullyCollected || noClaimAmount}
                title={isFullyCollected ? 'تم تحصيل كامل قيمة المطالبة' : noClaimAmount ? 'حدّد مبلغ المطالبة أوّلاً' : undefined}
              >
                <Icon name="card" /> {isFullyCollected ? 'تم تحصيل كامل المطالبة' : 'تسجيل مبلغ محصَّل'}
              </button>
            )}
            {/* تصحيح مبلغ المطالبة قبل أوّل تحصيل — للمحامي المسنَد والإدارة (`SetExecutionClaimAmount`) */}
            {canClose && najiz.amountEditable && (
              <button className="btn soft sm" type="button" onClick={() => setOpen(open === 'amount' ? null : 'amount')}>
                <Icon name="card" /> {noClaimAmount ? 'تحديد مبلغ المطالبة' : 'تصحيح مبلغ المطالبة'}
              </button>
            )}
            {/* الإنهاء متاحٌ متى فُتح الملفّ (7 و8 كحارس الخادم)، وللمحامي والإدارة وحدهما — والموظّف يردّه الخادم */}
            {canClose && <button className="btn soft sm" type="button" onClick={() => setOpen(open === 'close' ? null : 'close')}><Icon name="folder" /> إنهاء الملفّ</button>}
          </div>
        )}

        {open === 'amount' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('setClaimAmount', { amount: claimAmount, reason: claim.reason.trim() }, 'حُدِّث مبلغ المطالبة');
            }}
            style={{ marginTop: 10 }}
          >
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 8 }}>المبلغ المحكوم به أو قيمة السند — عليه يُقاس كلّ تحصيل. يُصحَّح قبل أوّل تحصيل فقط.</div>
            <div className="field">
              <label>مبلغ المطالبة (ريال)</label>
              <input className="input" type="text" inputMode="numeric" value={claim.amount} onChange={(e) => setClaim({ ...claim, amount: e.target.value })} placeholder={najiz.amount > 0 ? String(najiz.amount) : 'مثال: 150000'} />
            </div>
            <div className="field"><label>سبب التصحيح</label><input className="input" value={claim.reason} onChange={(e) => setClaim({ ...claim, reason: e.target.value })} placeholder="مثال: المبلغ المحكوم به في منطوق الحكم" /></div>
            <button className="btn sm" type="submit" disabled={busy || !claimOk}>حفظ</button>
          </form>
        )}

        {open === 'file' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('fileNajiz', filing, 'سُجّل رفع الطلب في ناجز');
            }}
            style={{ marginTop: 10 }}
          >
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 8 }}>ارفع الطلب في منصّة ناجز، ثمّ سجّل رقم الطلب الذي صدر.</div>
            <div className="field"><label>رقم الطلب في ناجز</label><input className="input" value={filing.request_no} onChange={(e) => setFiling({ ...filing, request_no: e.target.value })} /></div>
            <div className="field"><label>تاريخ الرفع</label><input className="input" type="date" value={filing.filed_at} onChange={(e) => setFiling({ ...filing, filed_at: e.target.value })} /></div>
            <button className="btn sm" type="submit" disabled={busy || !filing.request_no.trim() || !filing.filed_at}>حفظ</button>
          </form>
        )}

        {open === 'register' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('registerNajiz', reg, 'سُجّل قيد الطلب لدى محكمة التنفيذ');
            }}
            style={{ marginTop: 10 }}
          >
            <div className="field"><label>محكمة التنفيذ</label><input className="input" value={reg.court} onChange={(e) => setReg({ ...reg, court: e.target.value })} placeholder="محكمة التنفيذ بالرياض" /></div>
            <div className="field"><label>الدائرة</label><input className="input" value={reg.circuit} onChange={(e) => setReg({ ...reg, circuit: e.target.value })} placeholder="الدائرة الثالثة" /></div>
            <div className="field"><label>تاريخ القيد</label><input className="input" type="date" value={reg.registered_at} onChange={(e) => setReg({ ...reg, registered_at: e.target.value })} /></div>
            <button className="btn sm" type="submit" disabled={busy || !reg.court.trim() || !reg.circuit.trim() || !reg.registered_at}>حفظ</button>
          </form>
        )}

        {open === 'notify' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('notifyDebtor', { notified_at: notified }, 'سُجّل الإبلاغ — وتُحسب مهلة الوفاء');
            }}
            style={{ marginTop: 10 }}
          >
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 8 }}>من تاريخ الإبلاغ تبدأ مهلة الوفاء، ويحسبها النظام وينبّه عند انقضائها.</div>
            <div className="field"><label>تاريخ إبلاغ المنفَّذ ضدّه</label><input className="input" type="date" value={notified} onChange={(e) => setNotified(e.target.value)} /></div>
            <button className="btn sm" type="submit" disabled={busy || !notified}>حفظ</button>
          </form>
        )}

        {open === 'measures' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('applyMeasures', { measures: picked }, 'سُجّلت إجراءات عدم الوفاء');
            }}
            style={{ marginTop: 10 }}
          >
            <div className="chips" style={{ marginBottom: 8 }}>
              {measureOptions.map((m) => (
                <button key={m} type="button" className={`chip sel-toggle${picked.includes(m) ? ' on' : ''}`} onClick={() => toggle(m)}>{m}</button>
              ))}
            </div>
            <button className="btn sm" type="submit" disabled={busy}>حفظ</button>
          </form>
        )}

        {open === 'collect' && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('addCollection', { amount: collectAmount, note: collect.note }, 'سُجّل المبلغ المحصَّل');
            }}
            style={{ marginTop: 10 }}
          >
            {isFullyCollected ? (
              <div className="action-hint" style={{ marginBottom: 10 }}>
                <Icon name="check" /> تم تحصيل كامل قيمة المطالبة لهذا الملف بالفعل ({execMoney(najiz.amount)} ريال).
              </div>
            ) : (
              <>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 8 }}>
                  المتبقي للتحصيل من قيمة المطالبة: <b style={{ color: 'var(--primary)' }}>{execMoney(remaining)} ريال</b>
                </div>
                <div className="field">
                  <label>المبلغ المحصَّل (ريال)</label>
                  <input className="input" type="text" inputMode="numeric" value={collect.amount} onChange={(e) => setCollect({ ...collect, amount: e.target.value })} placeholder={`مثال: ${remaining > 0 ? Math.min(25000, remaining) : 25000}`} />
                  {collect.amount.trim() !== '' && !/^\d+$/.test(collectAmount) && <span style={{ fontSize: 11.5, color: 'var(--amber)' }}>أدخل مبلغاً صحيحاً أكبر من صفر.</span>}
                  {collect.amount.trim() !== '' && /^\d+$/.test(collectAmount) && collectNum <= 0 && <span style={{ fontSize: 11.5, color: 'var(--amber)' }}>أدخل مبلغاً صحيحاً أكبر من صفر.</span>}
                  {exceedsRemaining && (
                    <span style={{ fontSize: 11.5, color: 'var(--amber)' }}>
                      مبلغ التحصيل يتجاوز المتبقي من قيمة المطالبة (المتبقي: {execMoney(remaining)} ريال).
                    </span>
                  )}
                </div>
                <div className="field"><label>بيان (اختياري)</label><input className="input" value={collect.note} onChange={(e) => setCollect({ ...collect, note: e.target.value })} placeholder="حجز حساب بنكيّ" /></div>
                <button className="btn sm" type="submit" disabled={busy || !collectOk}>حفظ</button>
              </>
            )}
          </form>
        )}

        {open === 'close' && canClose && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              act('close', { reason: pickedReason }, 'أُنهي ملفّ التنفيذ');
            }}
            style={{ marginTop: 10 }}
          >
            <div className="field">
              <label>سبب الإنهاء</label>
              <select className="input" value={pickedReason} onChange={(e) => setCloseReason(e.target.value)}>
                {closeReasons.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
            </div>
            <button className="btn sm" type="submit" disabled={busy}>إنهاء وأرشفة</button>
          </form>
        )}
      </div>
    </div>
  );
};

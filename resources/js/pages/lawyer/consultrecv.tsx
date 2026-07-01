import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import {
  type Consult,
  CONSULTS,
  CONSULT_CHANNELS,
  crChannelIcon,
  crChannelTone,
  maskClient,
  maskLawyer,
} from '@/lib/employee-data';

// يطابق consultRecvView (دور المحامي) + crStart/crEnd/crSetFilter في index (82).html

const LawyerConsultRecv: React.FC = () => {
  const toast = useToast();
  const [items, setItems] = useState<Consult[]>(() => CONSULTS.map((c) => ({ ...c })));
  const [filter, setFilter] = useState('all');

  const counts: Record<string, number> = { 'مرئية': 0, 'حضورية': 0, 'هاتفية': 0 };
  items.forEach((c) => { if (counts[c.channel] != null) counts[c.channel]++; });
  const ended = items.filter((c) => c.session === 'منتهية').length;

  const stats: StatItem[] = [
    ['t-blue', 'video', counts['مرئية'], 'مرئية (فيديو)'],
    ['t-green', 'office', counts['حضورية'], 'حضورية'],
    ['t-amber', 'phone', counts['هاتفية'], 'هاتفية'],
    ['t-cyan', 'check', ended, 'منتهية'],
  ];

  // يطابق crStart
  const start = (ref: string) => {
    setItems((p) => p.map((c) => {
      if (c.ref !== ref) return c;
      const msg = c.channel === 'مرئية' ? 'تم بدء الجلسة المرئية مع العميل'
        : c.channel === 'هاتفية' ? 'تم بدء المكالمة الهاتفية مع العميل'
        : 'تم تسجيل وصول العميل وبدء الجلسة الحضورية';
      toast(msg);
      return { ...c, session: 'جلسة جارية' };
    }));
  };

  // يطابق crEnd
  const end = (ref: string) => {
    setItems((p) => p.map((c) => c.ref === ref ? { ...c, session: 'منتهية', status: 'منجزة' } : c));
    toast('انتهت الجلسة — جاهزة لكتابة ملخص الاستشارة');
  };

  const enterRoom = (ref: string) =>
    router.visit(`/lawyer/videoroom?kind=consult&ref=${encodeURIComponent(ref)}`);

  const list = items.filter((c) => filter === 'all' || c.channel === filter);

  return (
    <>
      <div className="greet">
        <h2>استقبال الاستشارات</h2>
        <p>تكملة رحلة الاستشارة: استقبال الجلسات حسب القناة — مرئية (فيديو) / حضورية / هاتفية — حتى كتابة الملخص.</p>
      </div>

      <StatRow items={stats} />

      <div className="mtabs">
        {CONSULT_CHANNELS.map((t) => (
          <button
            key={t[0]}
            className={`mtab${filter === t[0] ? ' on' : ''}`}
            onClick={() => setFilter(t[0])}
            type="button"
          >
            {t[1]}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-h">
          <h3>جلسات الاستشارات</h3>
          <span className="sub">{list.length} استشارة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((c) => {
            const extra = c.channel === 'حضورية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="pin" /> {c.branch}
              </span>
            ) : c.channel === 'هاتفية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="phone" /> {c.phone}
              </span>
            ) : (
              <span style={{ display: 'block', marginTop: 3, direction: 'ltr', textAlign: 'right', fontSize: 11, color: 'var(--primary)', fontWeight: 700 }}>
                🔗 {c.slink}
              </span>
            );

            return (
              <div key={c.ref} className="item">
                <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
                <div className="imeta">
                  <b>{c.ref} — {maskClient(c.client)}</b>
                  <span style={{ display: 'block', margin: '2px 0' }}>
                    {c.subject} · {maskLawyer(c.lawyer)} · {c.when || ''}
                  </span>
                  {extra}
                </div>
                <div className="iact">
                  <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                  {c.session === 'بانتظار الجلسة' ? (
                    c.channel === 'مرئية' ? (
                      <>
                        <button className="btn sm" onClick={() => enterRoom(c.ref)} type="button">
                          <Icon name="video" /> دخول الجلسة
                        </button>
                        <button className="btn soft sm" onClick={() => toast('تم نسخ رابط الاجتماع')} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      </>
                    ) : c.channel === 'هاتفية' ? (
                      <button className="btn sm" onClick={() => start(c.ref)} type="button">
                        <Icon name="phone" /> بدء المكالمة
                      </button>
                    ) : (
                      <button className="btn sm" onClick={() => start(c.ref)} type="button">
                        <Icon name="check" /> تسجيل وصول العميل
                      </button>
                    )
                  ) : c.session === 'جلسة جارية' ? (
                    <>
                      <Badge text="جلسة جارية" tone="b-amber" />
                      {c.channel === 'مرئية' && (
                        <button className="btn soft sm" onClick={() => enterRoom(c.ref)} type="button">
                          <Icon name="video" /> دخول الغرفة
                        </button>
                      )}
                      <button className="btn sm" onClick={() => end(c.ref)} type="button">
                        <Icon name="doc" /> إنهاء وكتابة الملخص
                      </button>
                    </>
                  ) : (
                    <>
                      <Badge text="منتهية" tone="b-green" />
                      <button className="btn soft sm" onClick={() => toast('فتح ملخص الاستشارة')} type="button">
                        <Icon name="out" /> الملخص
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="video" /><b>لا استشارات في هذه القناة</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default LawyerConsultRecv;

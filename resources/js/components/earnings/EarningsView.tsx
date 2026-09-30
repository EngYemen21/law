import { Link } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow from '@/components/babylon/StatRow';
import Icon from '@/lib/icons';
import type { PayoutRow, ShareRow, StaffEarnings } from '@/types';

/**
 * **عرض مستحقّات الموظّف — مكوّنٌ واحد** لصفحة «مستحقاتي» ودرج الإدارة في تبويب الموظّفين.
 *
 * كلّ رقمٍ هنا من `App\Support\Finance\StaffEarnings` كما هو — لا جمعَ ولا طرحَ في المتصفّح، فلا
 * يختلف ما يراه الموظّف عمّا تراه الإدارة. والفرق بين الموضعين يُمرَّر: وجهة روابط الملفّات
 * (`base`)، وزرّ إلغاء القيد للإدارة (`payoutAction`)، ورابط الكشف (`statementHref`).
 */
interface Props {
    earnings: StaffEarnings;
    /** بادئة لوحة القارئ لروابط الملفّات: `/lawyer` · `/employee` · `/admin` */
    base: string;
    /**
     * الإدارة تفتح أيّ ملفّ. صاحب «مستحقاتي» يفتح ملفّاته الجارية فقط: ما أُسند لغيره (`current: false`)
     * يُعرض رقمه نصّاً، لأنّ رابطه كان يفتح «لا تملك صلاحية».
     */
    opensAnyFile?: boolean;
    onMonth: (month: string) => void;
    statementHref?: string;
    payoutAction?: (p: PayoutRow) => React.ReactNode;
    /** رابط سند صرف القيد — الإدارة من مسار الموظّف، والموظّف من «مستحقاتي». */
    voucherHref?: (p: PayoutRow) => string;
}

export const sar = (n: number | null | undefined): string =>
    n == null ? '—' : `${Number(n).toLocaleString('en-US')} ر.س`;

const fileHref = (base: string, r: ShareRow): string =>
    r.kind === 'case'
        ? `${base}/cases/${encodeURIComponent(r.ref)}`
        : `${base}/execs?id=${encodeURIComponent(r.ref)}`;

const Section: React.FC<{
    title: string;
    sub?: string;
    children: React.ReactNode;
}> = ({ title, sub, children }) => (
    <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
            <div>
                <h3>{title}</h3>
                {sub && <span className="sub">{sub}</span>}
            </div>
        </div>
        <div className="card-b">{children}</div>
    </div>
);

const Empty: React.FC<{ text: string }> = ({ text }) => (
    <p style={{ color: 'var(--muted)', fontSize: 13, margin: 0 }}>{text}</p>
);

const EarningsView: React.FC<Props> = ({
    earnings: e,
    base,
    opensAnyFile = false,
    onMonth,
    statementHref,
    payoutAction,
    voucherHref,
}) => {
    const t = e.totals;

    return (
        <>
            <div className="card" style={{ marginBottom: 16 }}>
                <div
                    className="card-b"
                    style={{
                        display: 'flex',
                        gap: 12,
                        alignItems: 'center',
                        flexWrap: 'wrap',
                        justifyContent: 'space-between',
                    }}
                >
                    <div>
                        <div style={{ fontWeight: 800, color: 'var(--ink)' }}>
                            نوع الأجر: {e.payLabel}
                        </div>
                        <div
                            style={{
                                fontSize: 12,
                                color: 'var(--muted)',
                                marginTop: 4,
                            }}
                        >
                            الرصيد يُحسب من {e.ledgerStartLabel} — وما قبلها
                            سُوّي خارج النظام.
                        </div>
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            gap: 8,
                            alignItems: 'center',
                            flexWrap: 'wrap',
                        }}
                    >
                        <label
                            htmlFor="earnings-month"
                            style={{ fontSize: 13, fontWeight: 700 }}
                        >
                            الشهر
                        </label>
                        <input
                            id="earnings-month"
                            className="input"
                            type="month"
                            value={e.month}
                            onChange={(ev) =>
                                ev.target.value && onMonth(ev.target.value)
                            }
                            style={{ width: 170 }}
                        />
                        {statementHref && (
                            <a
                                className="btn soft"
                                href={statementHref}
                                download
                            >
                                <Icon name="download" /> كشف {e.monthLabel}
                            </a>
                        )}
                    </div>
                </div>
            </div>

            <StatRow
                items={[
                    [
                        't-blue',
                        'card',
                        sar(t.monthEarned),
                        `مستحقّ ${e.monthLabel}`,
                    ],
                    [
                        't-green',
                        'check',
                        sar(t.monthPaid),
                        `المصروف عن ${e.monthLabel}`,
                    ],
                    ['t-cyan', 'out', sar(t.paid), 'إجمالي المصروف'],
                    [
                        t.balance > 0 ? 't-amber' : 't-green',
                        'clock',
                        sar(t.balance),
                        'الرصيد المتبقّي لك',
                    ],
                ]}
            />

            <Section
                title="ملخّص البنود"
                sub="المستحقّ منذ بداية السجلّ، والمصروف، والمتبقّي لكلّ بند"
            >
                <div className="t-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>البند</th>
                                <th className="n">المستحقّ</th>
                                <th className="n">المصروف</th>
                                <th className="n">المتبقّي</th>
                                <th className="n">هذا الشهر</th>
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(t.byKind)
                                .filter(([, k]) => k.earned || k.paid)
                                .map(([id, k]) => (
                                    <tr key={id}>
                                        <td>{k.label}</td>
                                        <td className="n">{sar(k.earned)}</td>
                                        <td className="n">{sar(k.paid)}</td>
                                        <td className="n">
                                            <b>{sar(k.balance)}</b>
                                        </td>
                                        <td className="n">
                                            {sar(k.monthEarned)}
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
            </Section>

            {e.salary.applies && (
                <Section
                    title="الراتب الشهري"
                    sub={`${sar(e.salary.monthly)} شهرياً`}
                >
                    <div className="t-wrap">
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>الشهر</th>
                                    <th className="n">الراتب</th>
                                    <th className="n">المصروف</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                {e.salary.months.map((m) => (
                                    <tr key={m.period}>
                                        <td>
                                            {m.label}
                                            {m.suspendedDays > 0 && (
                                                <div
                                                    style={{
                                                        fontSize: 11,
                                                        color: 'var(--muted)',
                                                    }}
                                                >
                                                    منه {m.suspendedDays} يوم
                                                    إيقاف بلا راتب
                                                </div>
                                            )}
                                        </td>
                                        <td className="n">{sar(m.amount)}</td>
                                        <td className="n">{sar(m.paid)}</td>
                                        <td>
                                            {m.remaining > 0 ? (
                                                <Badge
                                                    text={`متبقٍّ ${sar(m.remaining)}`}
                                                    tone="b-amber"
                                                />
                                            ) : (
                                                <Badge
                                                    text="مصروف"
                                                    tone="b-green"
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Section>
            )}

            {(e.shares.length > 0 ||
                e.payType === 'pct' ||
                e.payType === 'both') && (
                <Section
                    title="نصيبك من أتعاب القضايا والتنفيذ"
                    sub="يُستحقّ بقدر ما سدّده العميل فعلاً، قبل الضريبة"
                >
                    {e.shares.length ? (
                        <div className="t-wrap">
                            <table className="tbl">
                                <thead>
                                    <tr>
                                        <th>الملفّ</th>
                                        <th>العميل</th>
                                        <th className="n">الأتعاب</th>
                                        <th className="n">النسبة</th>
                                        <th className="n">النصيب</th>
                                        <th className="n">المحصَّل</th>
                                        <th className="n">المستحقّ</th>
                                        <th className="n">
                                            متوقَّع بعد التحصيل
                                        </th>
                                        <th className="n">المصروف</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {e.shares.map((r) => (
                                        <tr key={`${r.kind}-${r.id}`}>
                                            <td>
                                                {opensAnyFile || r.current ? (
                                                    <Link
                                                        href={fileHref(base, r)}
                                                    >
                                                        {r.ref}
                                                    </Link>
                                                ) : (
                                                    <span>{r.ref}</span>
                                                )}
                                                <div
                                                    style={{
                                                        fontSize: 11,
                                                        color: 'var(--muted)',
                                                    }}
                                                >
                                                    {r.kind === 'case'
                                                        ? 'قضيّة'
                                                        : 'تنفيذ'}
                                                    {!r.current &&
                                                        ' · أُسندت لغيرك — ما حُصّل في عهدك'}
                                                </div>
                                            </td>
                                            <td>{r.client}</td>
                                            <td className="n">
                                                {r.share == null
                                                    ? 'نسبة من المحصَّل'
                                                    : sar(r.fee)}
                                            </td>
                                            <td className="n">{r.pct}%</td>
                                            <td className="n">
                                                {sar(r.share)}
                                            </td>
                                            <td className="n">
                                                {sar(r.collected)}
                                            </td>
                                            <td className="n">
                                                <b>{sar(r.earned)}</b>
                                                {r.beforeLedger > 0 && (
                                                    <div
                                                        style={{
                                                            fontSize: 11,
                                                            color: 'var(--muted)',
                                                        }}
                                                    >
                                                        منها{' '}
                                                        {sar(r.beforeLedger)}{' '}
                                                        قبل السجلّ
                                                    </div>
                                                )}
                                            </td>
                                            <td className="n">
                                                {sar(r.expected)}
                                            </td>
                                            <td className="n">{sar(r.paid)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Empty text="لا ملفّات بنسبةٍ مسندة إليك بعد — تظهر هنا حين تعتمد الإدارة أتعاب قضيّةٍ أو تنفيذٍ مسندٍ إليك." />
                    )}
                </Section>
            )}

            {e.sessions.applies && (
                <Section
                    title="أجر الجلسات"
                    sub={`${sar(e.sessions.fee)} للجلسة المنتهية`}
                >
                    {e.sessions.rows.length ? (
                        <div className="t-wrap">
                            <table className="tbl">
                                <thead>
                                    <tr>
                                        <th>الاستشارة</th>
                                        <th>تاريخ الجلسة</th>
                                        <th className="n">الأجر</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {e.sessions.rows.map((s) => (
                                        <tr
                                            key={s.ref}
                                            style={
                                                s.inLedger
                                                    ? undefined
                                                    : { opacity: 0.6 }
                                            }
                                        >
                                            <td>{s.ref}</td>
                                            <td>
                                                {s.date}
                                                {!s.inLedger && ' (قبل السجلّ)'}
                                            </td>
                                            <td className="n">
                                                {sar(s.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Empty text="لا جلسات منتهية بعد." />
                    )}
                </Section>
            )}

            <Section
                title="سجلّ الصرف"
                sub="ما صرفه المكتب لك — والقيد الملغى يبقى ظاهراً بسببه"
            >
                {e.payouts.length ? (
                    <div className="t-wrap">
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>تاريخ الصرف</th>
                                    <th>البند</th>
                                    <th>عن شهر</th>
                                    <th>الملفّ</th>
                                    <th className="n">المبلغ</th>
                                    <th>ملاحظة</th>
                                    {voucherHref && <th>سند الصرف</th>}
                                    {payoutAction && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {e.payouts.map((p) => (
                                    <tr
                                        key={p.id}
                                        style={
                                            p.voided
                                                ? {
                                                      textDecoration:
                                                          'line-through',
                                                      color: 'var(--muted)',
                                                  }
                                                : undefined
                                        }
                                    >
                                        <td>{p.paidAt}</td>
                                        <td>{p.kindLabel}</td>
                                        <td>{p.periodLabel}</td>
                                        <td>{p.ref ?? '—'}</td>
                                        <td className="n">{sar(p.amount)}</td>
                                        <td style={{ textDecoration: 'none' }}>
                                            {p.note ?? '—'}
                                            {p.voided && (
                                                <div style={{ fontSize: 11 }}>
                                                    <Badge
                                                        text={`ملغى: ${p.voidReason ?? ''}`}
                                                        tone="b-red"
                                                    />
                                                </div>
                                            )}
                                        </td>
                                        {voucherHref && (
                                            <td style={{ textDecoration: 'none' }}>
                                                {p.voucherNo && (
                                                    <a
                                                        className="btn soft sm"
                                                        href={voucherHref(p)}
                                                        download
                                                    >
                                                        <Icon name="download" />{' '}
                                                        {p.voucherNo}
                                                    </a>
                                                )}
                                            </td>
                                        )}
                                        {payoutAction && (
                                            <td>
                                                {p.voided
                                                    ? null
                                                    : payoutAction(p)}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty text="لم يُسجَّل صرفٌ بعد." />
                )}
            </Section>
        </>
    );
};

export default EarningsView;

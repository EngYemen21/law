import { Head, router } from '@inertiajs/react';
import React from 'react';
import EarningsView from '@/components/earnings/EarningsView';
import type { StaffEarnings } from '@/types';

/**
 * **«مستحقاتي»** — صفحةٌ واحدة للمحامي والموظّف (`Staff\EarningsController`). الأرقام من الخادم كما هي
 * (`EarningsView` نفسه الذي يعرضه درج الإدارة)، وتبديل الشهر زيارةٌ بمعاملة `month`.
 */
interface Props {
    earnings: StaffEarnings;
    /** بادئة لوحة الدور: `/lawyer` أو `/employee` */
    base: string;
}

const Earnings: React.FC<Props> = ({ earnings, base }) => (
    <>
        <Head title="مستحقاتي" />
        <EarningsView
            earnings={earnings}
            base={base}
            onMonth={(month) =>
                router.get(
                    `${base}/earnings`,
                    { month },
                    { preserveScroll: true, preserveState: true },
                )
            }
            statementHref={`${base}/earnings/statement.pdf?month=${earnings.month}`}
        />
    </>
);

export default Earnings;

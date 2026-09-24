<?php

use App\Domain\Journey\Enums\TicketStatus;
use App\Support\TicketJourney;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **نقل التذاكر المحوَّلة لتنفيذٍ إلى حالتها الصحيحة** (2026-09-24).
 *
 * أُضيفت `TicketStatus::ConvertedToExecution` لأنّ مسار التنفيذ كان يكتب حالةَ القضية حرفاً،
 * فيقرأ عميل التنفيذ «تم تحويل الطلب إلى قضية رسمية» وهو في ملفّ تنفيذ. لكنّ الحالة الجديدة
 * **تُصلح ما يأتي لا ما مضى**: كلّ تذكرةٍ حُوِّلت قبل اليوم بقيت بحالة القضية.
 *
 * والمعيار قاطعٌ لا تخمين: التذكرة حالتها حالةُ القضية **ولها صفٌّ في `executions`**. فمن
 * حُوِّل لقضيةٍ حقيقيّة لا ملفَّ تنفيذ له، ولا يلتبس بها.
 *
 * واللون يُشتقّ من `TicketJourney::toneFor` — المصدر الواحد — لا يُكتب يدويّاً، كي لا تنشأ
 * صفوفٌ لونُها يخالف حالتها.
 *
 * ولا يمرّ هذا بمحرّك الحالات عن عمد: المحرّك ينقل رحلةً حيّة بفاعلٍ وسبب، وهذا **تصحيحُ
 * بياناتٍ تاريخيّ** لواقعةٍ وقعت فعلاً وسُجّلت بحالةٍ خاطئة — لا انتقالٌ جديد يُسجَّل.
 *
 * و`down()` يعكسه إلى حالة القضية بالمعيار نفسه، فالرجوع تامّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(
            TicketStatus::ConvertedToCase->value,
            TicketStatus::ConvertedToExecution->value
        );
    }

    public function down(): void
    {
        $this->move(
            TicketStatus::ConvertedToExecution->value,
            TicketStatus::ConvertedToCase->value
        );
    }

    /** نقل كلّ تذكرةٍ بحالة `$from` ولها ملفّ تنفيذ إلى `$to`، مع اشتقاق لونها. */
    private function move(string $from, string $to): void
    {
        $ids = DB::table('tickets')
            ->where('tickets.status', $from)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('executions')
                ->whereColumn('executions.ticket_id', 'tickets.id'))
            ->pluck('tickets.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('tickets')->whereIn('id', $ids)->update([
            'status' => $to,
            'tone' => TicketJourney::toneFor($to),
        ]);
    }
};

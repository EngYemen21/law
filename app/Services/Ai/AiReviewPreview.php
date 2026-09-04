<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;

/**
 * معاينة مخرج النداء في صندوق المراجعة — **للقراءة فقط**.
 *
 * كان الصندوق يعرض بيانات النداء وحدها: المصدر والنموذج والثقة وتدقيق الحمولة
 * ورمز التعثّر — ولا يعرض **النصّ** الذي سيصل الإنسان. فكان «اعتماد» قراراً على
 * بياناتٍ وصفيّة عن مخرجٍ لم يُقرأ، ووسمُه «اعتماد بشريّ» أوسعُ ممّا وقع.
 *
 * **ولماذا لا يُحرَّر من هنا:** `ai_runs` بلا عمود مخرج، والصندوق عامّ لكل المهامّ —
 * ملخّص التذكرة أربعة حقول، ولائحة القضية مسودّة وادّعاءات وحكم إسناد. `textarea`
 * واحدة لا تُمثّل أياً منها، وتحريرٌ يجهل بنية ما يحرّره يُفسد. فالتحرير في شاشة
 * الملفّ حيث الموضوع والمستندات والقرارات، والصندوق يقرأ ولا يكتب: اتّجاهٌ واحد.
 *
 * والاقتطاع مُعلَن: المراجع الذي يحتاج النصّ كاملاً يفتح ملفّه.
 */
class AiReviewPreview
{
    /** حدّ المعاينة — يليه سطرٌ صريح، فلا يُظنّ المقتطع كاملاً. */
    private const LIMIT = 1200;

    /**
     * @return array{text:string, truncated:bool, label:string, href:?string}|null
     *                                                                             `null` حين لا مخرج نصّيّ محفوظ لهذه المهمّة — ولا يُختلق نصٌّ ليُملأ الفراغ.
     */
    public static function for(AiRun $run, ?User $viewer = null): ?array
    {
        // **المفاتيح هي `task_type` كما يُكتب فعلاً** لا معرّف التعليمة: ثلاثة مسارات
        // تكتب اسماً قصيراً (`consult` · `execution` · `triage`) وتمرّر معرّف التعليمة
        // في `policyTask`. والمطابقة على المعرّف تُخرج صندوقاً بلا معاينةٍ واحدة.
        [$text, $label, $href] = match ($run->task_type) {
            'consult.summary' => [self::consult($run)?->summary, 'ملخّص الاستشارة كما سيصل العميل', self::consultHref($run, $viewer)],
            'consult' => [self::consult($run)?->ai_summary, 'تحليل الاستشارة (ما قبل الجلسة)', self::consultHref($run, $viewer)],
            'ticket.summary' => [self::ticketSummary($run), 'ملخّص ملفّ التذكرة', null],
            'document.analyze' => [self::documentAnalysis($run), 'حصيلة فحص المستند', null],
            'execution' => [self::execution($run)?->ai_summary, 'تحليل طلب التنفيذ', null],
            'case.pleading' => [self::pleading($run), 'مسودّة اللائحة', null],
            'meeting.decisions' => [self::decisions($run), 'القرارات المستخرجة', null],
            'case.classify' => [self::caseClassification($run), 'تصنيف القضيّة كما حكم به النموذج', null],
            'triage' => [self::triageVerdict($run), 'فرز التذكرة كما حكم به النموذج', null],
            // `najiz.statement` وحدها بلا مخرجٍ محفوظ: تعود JSON للمتصفّح ولا تُخزَّن،
            // فلا يُختلق لها نصّ — غيابُ المعاينة أصدق من نصٍّ مُلفَّق.
            default => [null, '', null],
        };

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $truncated = mb_strlen($text) > self::LIMIT;

        return [
            'text' => $truncated ? mb_substr($text, 0, self::LIMIT).'…' : $text,
            'fullText' => $text,
            'truncated' => $truncated,
            'label' => $label,
            'href' => $href,
        ];
    }

    private static function consult(AiRun $run): ?Consult
    {
        return $run->entity instanceof Consult ? $run->entity : Consult::where('ref', $run->entity_ref)->first();
    }

    /**
     * **وجهةٌ يفتحها قاصدها.** كانت البادئة `/lawyer` مثبَّتة، ومسارها محروسٌ بـ
     * `role:lawyer` — و`role:` يفحص الدور لا الصلاحيّة فلا يتجاوزه استثناء الإدارة.
     * ومهمّة `consult` من مهامّ **الموظّف** في الصندوق، فكان يرى «فتح الملفّ» ثمّ
     * يُصدّ عن وجهته: دعوةٌ إلى بابٍ مغلق.
     */
    private static function consultHref(AiRun $run, ?User $viewer = null): ?string
    {
        $consult = self::consult($run);

        if ($consult === null) {
            return null;
        }

        // بلا مُشاهدٍ معلوم تبقى البادئة على حالها — الاستدعاءات القديمة لا تنكسر.
        $prefix = $viewer === null ? '/lawyer' : rtrim($viewer->role->prefix(), '/');

        return "{$prefix}/consult?ref={$consult->ref}";
    }

    private static function ticketSummary(AiRun $run): ?string
    {
        $ticket = $run->entity instanceof Ticket ? $run->entity : Ticket::where('number', $run->entity_ref)->first();
        $summary = $ticket?->summary;

        if ($summary === null) {
            return null;
        }

        // أربعة حقول لا واحد — تُعرض معنونةً كي لا يُقرأ أحدها على أنه الملخّص كلّه
        return collect([
            'الملخّص' => $summary->case_summary,
            'الوقائع' => $summary->facts,
            'التوصيات' => $summary->key_points,
            'المرفقات' => $summary->attachments_summary,
        ])->filter(fn ($v) => filled($v))->map(fn ($v, $k) => "【{$k}】\n{$v}")->implode("\n\n");
    }

    private static function execution(AiRun $run): ?Execution
    {
        return $run->entity instanceof Execution ? $run->entity : Execution::where('number', $run->entity_ref)->first();
    }

    /** حصيلة فحص آخر مستند جرى فحصه — حكمه يوجّه الملفّ فيُقرأ قبل الاعتماد. */
    /**
     * **حكمُ التصنيف معروضاً لا موصوفاً.**
     *
     * كانت ثمانية قيود `case.classify` تصل الصندوق بلا معاينة، فيُضغط «اعتماد» على
     * بياناتٍ وصفيّة عن مخرجٍ لم يُقرأ — ووسمُه «اعتماد بشريّ» أوسعُ ممّا وقع. والمخرج
     * حقلان يُكتبان في القضيّة ويوجّهان الملفّ كلّه: نوعُها وقسمُها.
     */
    private static function caseClassification(AiRun $run): ?string
    {
        $case = $run->entity instanceof LegalCase
            ? $run->entity
            : LegalCase::where('number', $run->entity_ref)->first();

        if ($case === null) {
            return null;
        }

        return collect([
            'القضيّة' => $case->number,
            'النوع' => $case->type,
            'القسم' => $case->department,
        ])->filter(fn ($v) => filled($v))->map(fn ($v, $k) => "【{$k}】 {$v}")->implode('
');
    }

    /** ونظيرُه للتذاكر — القسم والأولويّة والقصد حكمٌ يوجّه الطلب. */
    private static function triageVerdict(AiRun $run): ?string
    {
        $ticket = $run->entity instanceof Ticket
            ? $run->entity
            : Ticket::where('number', $run->entity_ref)->first();

        if ($ticket === null) {
            return null;
        }

        return collect([
            'التذكرة' => $ticket->number,
            'القسم' => $ticket->department,
            'الأولويّة' => $ticket->priority,
            'الحالة بعد الفرز' => $ticket->status,
        ])->filter(fn ($v) => filled($v))->map(fn ($v, $k) => "【{$k}】 {$v}")->implode('
');
    }

    private static function documentAnalysis(AiRun $run): ?string
    {
        $ticket = $run->entity instanceof Ticket ? $run->entity : Ticket::where('number', $run->entity_ref)->first();
        $doc = $ticket?->documents()->whereNotNull('summary')->reorder('id', 'desc')->first();

        if ($doc === null) {
            return null;
        }

        return collect([
            'المستند' => $doc->name,
            'النوع' => $doc->doc_type,
            'الحكم' => $doc->status,
            'الخلاصة' => $doc->summary,
            'التعليل' => $doc->reason,
        ])->filter(fn ($v) => filled($v))->map(fn ($v, $k) => "【{$k}】 {$v}")->implode('
');
    }

    /** القرارات المستخرجة — منها تُنشَأ مهامّ على بشر، فتُقرأ قبل الاعتماد. */
    private static function decisions(AiRun $run): ?string
    {
        $entity = $run->entity;
        $list = is_array($entity?->decisions ?? null) ? $entity->decisions : [];

        return $list === []
            ? null
            : collect($list)->map(fn ($d) => '• '.(is_array($d) ? ($d['title'] ?? json_encode($d, JSON_UNESCAPED_UNICODE)) : $d))->implode('
');
    }

    private static function pleading(AiRun $run): ?string
    {
        $case = $run->entity instanceof LegalCase ? $run->entity : LegalCase::where('number', $run->entity_ref)->first();

        // `reorder` قبل `latest`: العلاقة مرتّبة تصاعدياً في تعريفها، و`latest` تُلحق ترتيباً
        // ثانياً لا تستبدل الأوّل — فتعود **أقدم** رسالة لا أحدثها. وقد أوقعني هذا في قراءة
        // مسودّة بائتة والحكم عليها بأنّها لم تتغيّر.
        $body = $case?->messages()->where('role', 'مسودة اللائحة')->reorder('id', 'desc')->first()?->body;
        if ($body === null) {
            return null;
        }

        // إزالة وسوم HTML إذا كانت المسودة مغلفة بـ <div class="draft"> وفك تشفير الكيانات
        return html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

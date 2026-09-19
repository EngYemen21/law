<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **اعتماد التحليل ⇐ «جاهزة للمحامي».** يلي `AnalyzeConsult` ويسبق `ReferConsult`.
 *
 * كان `Staff\ConsultController::approveAnalysis` يكتب الحالة مباشرةً. والمنادي يتجاوز بصمتٍ ما
 * ليس «بانتظار اعتماد الموظف» (كما كان: الزرّ لا يُخطئ على ملفٍّ اعتُمد قبل ثوانٍ)، فيسأل
 * `accepts()` قبل النداء. والبثّ عند المنادي عبر `Live::push` (يبتلع تعثّر البثّ) كما كان.
 *
 * @extends Transition<Consult>
 */
final class ApproveConsultAnalysis extends Transition
{
    public function name(): string
    {
        return 'consult.approve_analysis';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingEmployeeApproval->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::ReadyForLawyer->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        $entity->logAudit($actor->name ?? 'النظام', 'اعتماد التحليل', (string) $entity->status, ConsultStatus::ReadyForLawyer->value);
    }
}

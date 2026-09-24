# Claude AI — Project Guidelines & Master Instructions

This repository is governed by strict Domain-Driven Design (DDD) principles and an automated multi-agent handover protocol.

> **CRITICAL MANDATE FOR CLAUDE AI:**  
> Before reading or writing any code, you MUST review:
> 1. [`AGENTS.md`](./AGENTS.md) — Master governance rules and agent constraints.
> 2. [`PROJECT_STATUS.md`](./PROJECT_STATUS.md) — Real-time project status and handover log (shows what is completed and what is next).
> 3. [`.agents/skills/ticket-domain-lifecycle/SKILL.md`](./.agents/skills/ticket-domain-lifecycle/SKILL.md) — Comprehensive DDD architecture reference across all platform domains.

---

## 🛠️ Quick Operational Rules for Claude

1. **Domain Layer Independence:**
   - Pure PHP only in `app/Domain/<Domain>/Entities/` and `ValueObjects/`. No Eloquent, no Laravel dependencies.
2. **Logic Cleanliness:**
   - Never use Arabic status strings in conditionals or business logic (no `if ($status === 'مكتملة')`).
   - Use typed Enums, boolean properties (`isTerminal`, `needsDoc`), or server-calculated `actions.can_*`.
3. **Frontend Rules:**
   - Use Inertia `<Link>` instead of raw `<a>` tags.
   - Deep link directly to entity resources (`/cases/${caseNumber}`).
4. **Testing Protocol:**
   - Always run `npx tsc --noEmit` and `php artisan test --filter=<Domain>` before concluding any task.
5. **Mandatory Handover Update:**
   - After completing any task, update [`PROJECT_STATUS.md`](./PROJECT_STATUS.md) with your changes, test results, and the exact next step for the next agent session.
6. **Single Source of Truth & Zero Bypass:**
   - Never duplicate endpoints or UI actions (no shortcuts that bypass domain governance). All ticket outcome transitions (consultation, case, execution, close) must flow exclusively through the 4-track decision card and `ApproveOutcomeTrack` transition.
7. **Pre-Execution Baseline & Safe Isolation:**
   - Always run on a dedicated branch with a clean checkpoint. Never mix P0 fixes with broad refactoring in one commit.
   - Record pre-phase baseline: TypeScript check (`tsc`), targeted domain tests, active HTTP routes, server/UI statuses, DB test state, and impacted file list.
   - Run post-phase tests and the entire domain test suite. A phase is only complete when all acceptance criteria are met (100% test pass, exit code 0).

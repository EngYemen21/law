# تقرير المرحلة صفر — جرد وخط أساس

**التاريخ:** 2026-08-28 · **الفرع:** `main` عند `47aae94` · **الشجرة:** نظيفة قبل هذا التقرير
**النطاق:** قراءة وقياس فقط — لم تُعدَّل شيفرة تطبيق ولا إعدادات ولا بيانات. لم يُرسَل أي طلب إلى Gemini أو GLM أو Zoom أو Moyasar.

---

## 1. `AGENTS.md`

**غير موجود.** ولا يوجد `CLAUDE.md` في الجذر ولا في `.claude/`. مرجع المستودع الفعلي هو `HANDOVER.md` (763 سطراً) و`DEVELOPER_GUIDE.md` و`DEPLOYMENT_AR.md`.

## 2. جرد منظومة الذكاء الاصطناعي

### 2.1 المزوّدون والتهيئة

| العنصر | القيمة الفعلية | الموضع |
|---|---|---|
| المزوّد الأساسي | Gemini · `gemini-2.5-flash` | `config/services.php:34` |
| الاحتياطي النصّي | GLM (z.ai) · `glm-4.6` · متوافق OpenAI | `config/services.php:39` |
| مفتاح تعطيل الوكيل | `AI_TICKET_AGENT` (افتراضه `true`) | `config/services.php:47` |
| تهدئة المزوّد | `AI_COOLDOWN_MINUTES` افتراضه 30 | `config/services.php:52` |
| هوية الوكيل المعروضة | `AGENT_NAME='خدمة العملاء'` · `AGENT_ROLE='الدعم الفني'` | `LegalAiService.php:31` |

مفاتيح البيئة الحاضرة محلياً (الأسماء فقط، بلا قيم): `GEMINI_API_KEY` · `GEMINI_MODEL` · `GLM_API_KEY` · `GLM_MODEL` · `GLM_BASE_URL` · `AI_TICKET_AGENT` — كلّها مضبوطة.

### 2.2 نقطة الدخول الوحيدة

`app/Services/LegalAiService.php` — **86 KB، 26 دالة عامّة**. كل استدعاء ذكاء في المشروع يمرّ عبرها. مستهلكوها: **9 متحكّمات · 11 Job · 8 أصناف Support**، إضافة إلى 8 ملفات اختبار.

| الدالة | السطر | المستهلك | نمط التنفيذ |
|---|---:|---|---|
| `triageTicket` | 230 | `TicketTriage::onOpened` ← `TriageTicketOnOpenJob` | Job |
| `greet` / `acknowledgeDocs` | 482 / 512 | `TicketTriage` | Job |
| `analyzeDocument` | 263 | `TicketTriage::classifyDoc` · `TriageDocumentJob` | Job |
| `summarize` / `fallbackSummary` | 136 / 454 | `GenerateTicketSummaryJob` | Job |
| `reply` | 471 | `GenerateTicketReplyJob` | Job |
| `classifyCase` / `fallbackClassification` | 181 / 219 | `CaseConversion` · `ClassifyConvertedCaseJob` | Job |
| `analyzeCaseDocument` | 322 | `AnalyzeCaseDocumentJob` | Job |
| `caseReply` | 546 | `GenerateCaseReplyJob` | Job |
| `draftPleading` | 388 | `DraftCasePleadingJob` | Job |
| `analyzeConsult` | 679 | `Staff\ConsultController::analyze` | **متزامن (طلب ويب)** |
| `consultSummary` / `extractDecisions` | 561 / 652 | `FinalizeConsultJob` · `ConsultSummary` | Job |
| `meetingSummary` | 590 | `GenerateMeetingSummaryJob` · `MeetingSummary` | Job |
| `analyzeExecution` | 725 | `AnalyzeExecutionJob` ← `ExecService::applyAnalysis` | Job |
| `analyzeExecutionDocument` | 797 | `AnalyzeExecutionDocumentJob` | Job |
| `assist` | 931 | `Lawyer\AssistantController::generate` | **متزامن (طلب ويب)** |
| `generateNajizDraft` | 1005 | `Lawyer\TicketController` | **متزامن (طلب ويب)** |
| `chooseLawyer` / `rankLawyers` | 846 / 884 | **لا مستهلك** — موثّقتان كميتتين | — |

### 2.3 حقول حالة الذكاء في قاعدة البيانات

لا يوجد جدول موحّد لمخرجات الذكاء. الحالة مبعثرة على أعمدة في أربعة نماذج:

| النموذج | الحقول | الدلالة الحالية |
|---|---|---|
| `Execution` | `ai_done` (bool) · `ai_summary` · `ai_missing` · `ai_procedures` | `ai_done=true` تُضبط للنجاح **وللاحتياطي معاً** |
| `Consult` | `ai_done` · `ai_class` · `ai_summary` · `ai_lawyer` · `missing` | — |
| `TicketSummary` | `ai_generated` (bool) · `result` · `result_status` | **الحقل الوحيد الأمين**: يميّز القالبي من المولَّد |
| `Meeting` / `Consult` | `zoom_ai_next_steps` · `tasks_created` | مصدر خارجي (Zoom) + حارس تكرار |

**غائب تماماً:** `source` · `confidence` · `model` / `model_version` · `prompt_version` · `trace_id` · `failure_code` · `reviewed_by` / `reviewed_at`.

### 2.4 الاختبارات القائمة ذات الصلة

`AiResilienceTest` (قاطع الدائرة، تهدئة GLM، التصعيد عند الاستنفاد، عدم التصعيد بعد نجاح AI) · `TicketTriageTest` · `TicketOpenTriageTest` · `EmployeeTicketTriageTest` · `DecisionTasksTest` · `MeetingSummaryIntegrityTest` · `LawyerSummaryFlowTest` · `LawyerRerunSummaryTest` · `AssistantAndSummaryFeaturesTest` · `ZoomMeetingSummaryTest` · `CaseDocumentTest` · `ExecFlowFixesTest`.

**لا يوجد اختبار واحد يثبت أن الاحتياطي ≠ نجاح AI.** هذه هي الفجوة التي تفرض P0.

---

## 3. خط الأساس المقيس

| الفحص | الأمر | النتيجة |
|---|---|---|
| حزمة الاختبارات | `php83 artisan test` — 164 ملفاً على 17 دفعة | **930 اختباراً · 923 ناجح · 7 ساقطة · 5252 تأكيداً** |
| TypeScript | `npx tsc --noEmit` | ✅ **نظيف** (خروج 0) |
| Pint | `pint --test` | ❌ **11 ملفاً** خارج التنسيق |
| ESLint (`resources/`) | `npx eslint resources` | ⚠️ **884 مشكلة** (876 خطأ · 8 تحذيرات) في 119 ملفاً |
| البناء الإنتاجي | `npm run build` | ✅ **نجح** — 256 وحدة، 2م 7ث، خروج 0 (تحذير حجم chunk فقط) |

> الحزمة تُشغَّل دفعات ~10 ملفات إلزاماً: الحزمة كاملة تسقط بـ`set_time_limit(150)`.

### 3.1 الاختبارات السبعة الساقطة — **كلّها سقطت بمعزل أيضاً**، فهي أعطال حقيقية لا أثر تشغيل دفعات

| الاختبار | الأثر المرصود | التشخيص |
|---|---|---|
| `CaseArchiveTest::test_admin_archives_closed_case` | `user_notifications` فارغ | **انحدار مؤكّد:** الكوميت `8d1c088` حذف `Notify::send(...'أُرشفت قضيتك...')` من `Admin\CaseController::archiveCase`. النظير `closeCase:185` ما زال يُشعِر. العميل لا يُخطَر بأرشفة قضيته. |
| `MeetingLifecycleTest::test_reschedule_without_time_marks_postponed_and_skips_zoom` | طلبات HTTP سُجّلت والمتوقَّع صفر | إعادة جدولة بلا وقت تنادي مزوّداً خارجياً رغم أن العقد يمنع ذلك — مرشّح قويّ: مزامنة Google المضافة في `8d1c088`. |
| `MeetingZoomParityTest::test_webhook_ended_completes_lifecycle_like_manual_end` | المدّة 0 بدل 90 | مسار الويبهوك لا يحسب مدّة الاجتماع كما يفعل الإنهاء اليدوي. |
| `RegressionGuardTest::test_appointment_card_gives_employee_a_room_it_may_actually_open` | الرابط `''` بدل `/employee/videoroom` | بطاقة الموعد لم تعد تعطي الموظف غرفة جلسة. |
| `SchedulerAutoCloseTest::test_auto_lapse_hearings_marks_and_notifies_lawyer` | `'فائتة — بانتظار النتيجة'` بدل `'بانتظار تسجيل النتيجة'` | تغيّرت صياغة الحالة ولم يُحدَّث عقد الاختبار (أو العكس). |
| `StaffManagementTest::test_catalog_exposes_role_permissions` | كتالوج الإدارة 24 والمتوقَّع 23 | صلاحية «سجل التدقيق الأمني» أُضيفت في `8d1c088` ولم يُحدَّث الاختبار. |
| `TechnicalDebtTest::test_every_declared_permission_guards_something` | «إجراء الجلسات المرئية» تبدو بلا حارس | الصلاحية **مستعملة فعلاً** في `routes/web.php:242` و`:409` بصيغة `permission:أ,ب`. الأرجح أن ماسح الاختبار لا يفهم الصيغة متعدّدة الصلاحيات ⇒ إيجابية كاذبة في الاختبار لا عطل في الشيفرة. |

**لا علاقة لأيٍّ من السبعة بالذكاء الاصطناعي.** ستّة منها انحدارات أو عقود بائتة من الكوميتين الأخيرين (`8d1c088` / `640e3b6`)، والسابع عيب في الاختبار نفسه.

### 3.2 قراءة أرقام ESLint

الفحص غير المقصور يعطي 923 مشكلة، لكن 915 منها من `storage/app/browsershot-tmp/**/command.js` — مخلّفات Browsershot مولَّدة يفحصها eslint لأنّ `eslint.config.js` لا يستثني `storage/`. **عيب تهيئة، لا عيب شيفرة.**

الأرقام الحقيقية على `resources/`: من 884 مشكلة، **845 إصلاح آليّ وأسلوبية بحتة** — `padding-line-between-statements` 335 · `curly` 205 · `brace-style` 180 · `import/*` 125. الإشارة الحقيقية صغيرة ومحصورة:

| القاعدة | العدد | الطبيعة |
|---|---:|---|
| `react-hooks/set-state-in-effect` | 22 | أرضية موثّقة سابقاً |
| `react-hooks/exhaustive-deps` | 7 | تحذيرات |
| `@typescript-eslint/no-unused-vars` | 5 | كود ميت |
| `react-hooks/preserve-manual-memoization` | 2 | — |
| `no-constant-binary-expression` | 2 | يستحقّ نظرة |

---

## 4. التعارضات المؤكَّدة بين الخطة والشيفرة

فحصت الفجوتين اللتين يدّعيهما `04-current-ai-audit.md` بقراءة الشيفرة مباشرة. **كلتاهما مؤكَّدة.**

### 4.1 R-04 — الاحتياطي يُعرض كنجاح ذكاء اصطناعي (التنفيذ)

`LegalAiService::analyzeExecution` ([`:781`](../../../app/Services/LegalAiService.php)) يعيد عند الفشل **نفس شكل** نجاح AI حرفياً: `['summary','missing','procedures']` بلا أي علامة مصدر. ثم `ExecService::applyAnalysis` ([`:91`](../../../app/Support/ExecService.php)) يطبّقه بلا تمييز:

- `ai_done => true` (السطر 99)
- رسالة محادثة باسم **«المساعد القانوني» بدور «تحليل»** (السطر 104)
- إشعار نصّه **«تم تحليل طلب التنفيذ … بالذكاء الاصطناعي»** (السطر 109)
- رفع المرحلة إلى 2 بعنوان **«اكتمل التحليل الذكيّ»** إذا خلت `missing` (السطر 101)

والاحتياطي لا يفحص مستنداً واحداً؛ منطقه كلّه: `if (المنفَّذ ضده فارغ) نواقص=[بياناته]` + ثلاث إجراءات ثابتة. فحين يعود الاحتياطي بمنفَّذٍ ضده مذكور، **يقفز الطلب إلى «اكتمل التحليل» وقد أخبر النظام العميل والموظف كذباً أن الذكاء الاصطناعي حلّل السند والمستندات.** هذا أخطر ما في التقرير.

### 4.2 فرز التذكرة — احتياطي غير مميَّز + مخرجات مهدرة

`triageTicket` ([`:253`](../../../app/Services/LegalAiService.php)) يعيد عند الفشل `['department'=>القسم القديم,'priority'=>'عادية','intent'=>'عادي']` — شكل مطابق للنجاح بلا علامة مصدر. وفي `TicketTriage::onOpened` ([`:38`](../../../app/Support/TicketTriage.php)): `department` وحده يُحفظ (وفقط إن كان فارغاً)، و`priority` تذهب إلى نصّ ملاحظة تدقيق، و`intent` **لا تُستعمل إطلاقاً**. فالأولوية والنية اقتراحٌ متبخّر لا قرار تشغيليّ.

### 4.3 تعارض في وصف الحزمة يلزم تصحيحه

`04-current-ai-audit.md` §2.3 يقول إن الملخّص القالبي يُعلَّم `ai_generated=false` — **وهذا صحيح ودقيق**؛ `TicketSummary.ai_generated` هو الحقل الوحيد في المشروع الذي يميّز القالبي من المولَّد. أي أن النمط الصحيح **موجود ومطبَّق في التذاكر**، والمطلوب في P0 هو تعميمه لا اختراعه.

---

## 5. ما لم يُنفَّذ ولماذا

- **لم يُجرَّب أي مزوّد ذكاء حيّ.** التعليمات تمنع إرسال بيانات، والمفاتيح إنتاجية.
- **لم تُعدَّل أي شيفرة.** المرحلة صفر قياس فقط.
- **لم تُصلَح الاختبارات السبعة.** خارج نطاق الذكاء الاصطناعي، وإصلاحها قرار مالك المنتج.

## 6. أسئلة تحتاج قرارك قبل P0

1. **الأعطال الستّة غير المتعلقة بالذكاء:** تُصلَح أولاً كدفعة مستقلّة (فيصير خط الأساس أخضر ويمكن إثبات أن أي احمرار لاحق سببه عملي)، أم تُترك موثّقة ونمضي في P0 فوق خط أساس أحمر؟ **التوصية: تُصلَح أولاً** — أخطرها انحدار إشعار الأرشفة، وهو سطر واحد محذوف.
2. **`ai_done` في `Execution` و`Consult`:** الخطة تسمح بإبقائها للتوافق الخلفي على ألّا تعني النجاح إلا بعد تحقّق. هل نُبقيها ونضيف `ai_source` بجوارها (أقل خطراً على الواجهة)، أم نغيّر دلالتها مباشرة؟ **التوصية: الإبقاء + إضافة.**
3. **نصّ ما يراه المستخدم عند الاحتياطي:** الخطة تقترح «تقييم أولي آلي محدود — يتطلب مراجعة المستندات». هل هذه الصياغة معتمدة نهائياً؟ تظهر للعميل وللموظف.

## 7. نطاق P0 المقترح (دفعة واحدة قابلة للمراجعة)

1. جدول `ai_runs` بالحقول التي تفرضها التعليمات (`task_type` · `entity_*` · `source` · `status` · `confidence` · `model` · `prompt_version` · `trace_id` · `failure_code` · `reviewed_*`) بهجرة قابلة للعكس.
2. تمييز الاحتياطي في `analyzeExecution` عبر علامة مصدر صريحة، وامتناع `applyAnalysis` عن `ai_done=true` وعن رفع المرحلة وعن إشعار «حُلّل بالذكاء الاصطناعي» حين تكون النتيجة احتياطية.
3. تمييز الاحتياطي في `triageTicket` بالمثل.
4. عرض أمين في واجهة التنفيذ لحالة «تقييم أوّلي — يتطلب مراجعة».
5. اختبارات جديدة تثبت: احتياطي ⇒ `source=fallback` وليس `ai_success` · لا `ai_done` · لا قفز مرحلة · لا إشعار مضلِّل.

كلّه خلف حدّ أدنى من التغيير، مع إبقاء كل مسار قائم يعمل.

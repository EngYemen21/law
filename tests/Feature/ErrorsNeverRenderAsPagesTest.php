<?php

namespace Tests\Feature;

use App\Domain\Journey\TransitionDenied;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Consult;
use App\Models\User;
use App\Support\ErrorResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **الخطأ رسالةٌ عربيّة لا صفحة** — حارس `App\Support\ErrorResponse`.
 *
 * رُصد في المتصفّح 2026-09-26: فتحُ رابط غرفة جلسةٍ انتهت أسقط الموظّف على صفحة لارافل الخام
 * «HttpException — Unprocessable Content». وكان المُحوِّل لا يعرف إلّا زيارات Inertia بثلاثة رموز،
 * فالتنزيل المرفوض «Forbidden» والسجلّ المحذوف «Not Found» والجلسة المنتهية «Page Expired».
 *
 * الحارس يقيس **ما يصل صاحب الطلب** بحسب نوعه — فعلُ Inertia، فتحُ صفحة، نداء JSON — ويقيس
 * النصّ لا الرمز وحده: تحويلٌ بلا سببٍ عربيّ هو العطل نفسه بوجهٍ ألطف.
 */
class ErrorsNeverRenderAsPagesTest extends TestCase
{
    use RefreshDatabase;

    /** كلماتُ صفحات الإطار الإنجليزيّة — لا تصل مستخدماً. */
    private const ENGLISH_ERROR_WORDS = '/Forbidden|Unprocessable|Not Found|Server Error|Page Expired|Too Many|unauthorized|No query results|Whoops|Unauthenticated/i';

    protected function setUp(): void
    {
        parent::setUp();

        // مساراتٌ للاختبار وحده — تقيس المُحوِّل على استثناءاتٍ يصعب بلوغها من مسارٍ حقيقيّ
        Route::middleware(['web', 'auth'])->group(function () {
            Route::post('/__errors/transition', fn () => throw TransitionDenied::state('مغلقة'));
            Route::post('/__errors/csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/__errors/throttled', fn () => abort(429));
            Route::get('/__errors/crash', fn () => throw new RuntimeException('SQLSTATE secret detail'));
            Route::post('/__errors/crash', fn () => throw new RuntimeException('SQLSTATE secret detail'));
        });
    }

    // ─── فعلُ Inertia (زرّ) ─────────────────────────────────────────────

    public function test_an_inertia_action_refused_by_a_business_rule_comes_back_with_its_reason(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $response = $this->actingAs($lawyer)->withHeader('X-Inertia', 'true')
            ->post("/lawyer/consults/{$consult->id}/start");

        $this->assertActionRefused($response, 'ربع ساعة');
        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session, 'والحارس ما زال يمنع');
    }

    public function test_a_bare_abort_on_an_inertia_action_says_the_arabic_default(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)->withHeader('X-Inertia', 'true')
            ->post("/consults/{$consult->id}/schedule");

        $this->assertSame(ErrorResponse::MESSAGES[403], $this->assertActionRefused($response));
    }

    public function test_an_inertia_action_on_a_deleted_record_comes_back_instead_of_a_404_page(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($client)->withHeader('X-Inertia', 'true')
            ->post('/consults/999999/schedule');

        $this->assertSame(ErrorResponse::MESSAGES[404], $this->assertActionRefused($response));
    }

    public function test_a_journey_transition_denial_reaches_the_screen(): void
    {
        $response = $this->actingAs($this->staff())->withHeader('X-Inertia', 'true')->post('/__errors/transition');

        $this->assertActionRefused($response, '«مغلقة»');
    }

    public function test_an_expired_page_is_a_message_not_page_expired(): void
    {
        $response = $this->actingAs($this->staff())->withHeader('X-Inertia', 'true')->post('/__errors/csrf');

        $this->assertSame(ErrorResponse::MESSAGES[419], $this->assertActionRefused($response));
    }

    public function test_a_crash_on_an_inertia_action_in_production_is_a_message_without_the_trace(): void
    {
        config(['app.debug' => false]);

        $response = $this->actingAs($this->staff())->withHeader('X-Inertia', 'true')->post('/__errors/crash');

        $message = $this->assertActionRefused($response);
        $this->assertSame(ErrorResponse::MESSAGES[500], $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
    }

    // ─── فتحُ صفحة (رابط، تبويب، إعادة تحميل، نقرة Inertia) ─────────────

    public function test_opening_a_refused_link_goes_home_with_the_reason(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)->get("/consults/{$consult->id}/documents");

        $this->assertPageRefused($response);
        $response->assertRedirect(Role::Client->home());
    }

    public function test_a_refused_link_returns_to_the_page_it_was_clicked_from(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)
            ->withHeaders(['Referer' => url('/myconsults')])
            ->get("/consults/{$consult->id}/documents");

        $this->assertPageRefused($response);
        $response->assertRedirect(url('/myconsults'));
    }

    public function test_an_inertia_link_to_a_refused_page_stays_where_it_was(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)
            ->withHeaders($this->inertiaGet() + ['Referer' => url('/myconsults')])
            ->get("/consults/{$consult->id}/documents");

        $this->assertPageRefused($response);
        $response->assertRedirect(url('/myconsults'));
    }

    /** **لا حلقة**: إعادة تحميل الرابط المرفوض (المُحيل هو نفسه) لا تعود إليه. */
    public function test_reloading_a_refused_link_never_loops_back_to_it(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);
        $url = url("/consults/{$consult->id}/documents");

        $response = $this->actingAs($intruder)->withHeaders(['Referer' => $url])->get($url);

        $this->assertPageRefused($response);
        $response->assertRedirect(Role::Client->home());
    }

    /** مُحيلٌ من موقعٍ آخر (رابطٌ في بريد) ليس «عودة» — لا يُحوَّل المستخدم خارج النظام. */
    public function test_a_foreign_referer_is_never_a_redirect_target(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)
            ->withHeaders(['Referer' => 'https://evil.example/phish'])
            ->get("/consults/{$consult->id}/documents");

        $response->assertRedirect(Role::Client->home());
    }

    public function test_a_refused_page_without_a_reason_gets_the_arabic_default(): void
    {
        $response = $this->actingAs($this->staff())->get('/__errors/throttled');

        $this->assertPageRefused($response);
        $this->assertSame(ErrorResponse::MESSAGES[429], session('error'));
    }

    public function test_an_unknown_record_is_the_arabic_error_page_inside_the_app(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($client)->get('/consults/999999/report.pdf');

        $response->assertNotFound();
        $this->assertErrorPage($response, 404);
        // رفضُ ربط السجلّ يقع قبل وسيط Inertia — والصفحة تحمل المستخدم فيبقى الشريط الجانبيّ
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $client->id));
    }

    public function test_an_unknown_url_is_the_arabic_error_page_for_members_and_guests(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $member = $this->actingAs($client)->get('/no-such-page-anywhere');
        $member->assertNotFound();
        $this->assertErrorPage($member, 404);
        $member->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $client->id));

        auth()->logout();
        $guest = $this->get('/another/missing/page');
        $guest->assertNotFound();
        $this->assertErrorPage($guest, 404);
    }

    public function test_an_inertia_link_to_an_unknown_record_renders_the_error_component(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($client)->withHeaders($this->inertiaGet())->get('/consults/999999/report.pdf');

        // ردّ Inertia (JSON الصفحة) لا صفحة HTML — فتعرضه الواجهة مكوّناً لا نافذة خطأ
        $response->assertNotFound();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('component', 'error')
            ->assertJsonPath('props.status', 404)
            ->assertJsonPath('props.message', ErrorResponse::MESSAGES[404])
            ->assertJsonPath('props.auth.user.id', $client->id);
    }

    public function test_a_crash_in_production_is_the_arabic_page_without_the_trace(): void
    {
        config(['app.debug' => false]);

        $response = $this->actingAs($this->staff())->get('/__errors/crash');

        $response->assertStatus(500);
        $this->assertErrorPage($response, 500);
        $response->assertDontSee('SQLSTATE');
    }

    // ─── نداء JSON / fetch ─────────────────────────────────────────────

    public function test_a_json_refusal_keeps_its_status_and_arabic_reason(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $this->actingAs($lawyer)->postJson("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'ربع ساعة'));
    }

    public function test_json_framework_defaults_are_translated(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)->getJson("/consults/{$consult->id}/documents")
            ->assertForbidden()->assertExactJson(['message' => ErrorResponse::MESSAGES[403]]);

        $this->actingAs($intruder)->getJson('/consults/999999/report.pdf')
            ->assertNotFound()->assertExactJson(['message' => ErrorResponse::MESSAGES[404]]);

        auth()->logout();
        $this->getJson('/myconsults')->assertUnauthorized()->assertExactJson(['message' => ErrorResponse::MESSAGES[401]]);
    }

    public function test_a_json_crash_in_production_hides_the_detail(): void
    {
        config(['app.debug' => false]);

        $this->actingAs($this->staff())->postJson('/__errors/crash')
            ->assertStatus(500)
            ->assertExactJson(['message' => ErrorResponse::MESSAGES[500]]);
    }

    /** `fetch()` بلا ترويسة JSON (نصّ التفريغ) وعنصر `<video>` يأخذان الرمز — التحويل يُقرأ ٢٠٠. */
    public function test_a_fetch_or_media_request_keeps_its_status(): void
    {
        [$consult] = $this->futureConsult();
        $intruder = User::factory()->create(['role' => Role::Client]);

        foreach ([['Sec-Fetch-Mode' => 'cors', 'Accept' => '*/*'], ['Sec-Fetch-Mode' => 'no-cors', 'Accept' => 'video/*']] as $headers) {
            $this->actingAs($intruder)->withHeaders($headers)->get("/consults/{$consult->id}/documents")->assertForbidden();
        }
    }

    /** ونشرٌ بلا Inertia (webhook، نموذج خام) يبقى برمزه — وصفحته عربيّة لا صفحة الإطار. */
    public function test_a_plain_post_keeps_its_status_with_an_arabic_page(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $response = $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/start");

        $response->assertStatus(422);
        $response->assertSee('ربع ساعة');
        $this->assertDoesNotMatchRegularExpression(self::ENGLISH_ERROR_WORDS, strip_tags((string) $response->getContent()));
    }

    // ─── الخريطة والمسح ────────────────────────────────────────────────

    public function test_every_default_message_is_arabic_and_names_no_english_error(): void
    {
        foreach (ErrorResponse::MESSAGES + ErrorResponse::TITLES as $status => $text) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, "الرمز {$status}");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', $text, "الرمز {$status} بلا إنجليزيّة");
        }

        // نصّ الحارس العربيّ مقدَّم، ونصّ الإطار الإنجليزيّ يُستبدل
        $this->assertSame('سببٌ حقيقيّ', ErrorResponse::message(422, 'سببٌ حقيقيّ'));
        $this->assertSame(ErrorResponse::MESSAGES[404], ErrorResponse::message(404, 'No query results for model [App\\Models\\Consult] 5'));
        $this->assertSame(ErrorResponse::MESSAGES[403], ErrorResponse::message(403, 'This action is unauthorized.'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', ErrorResponse::message(418));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', ErrorResponse::message(507));
    }

    /**
     * **المسح**: كلّ رمزٍ في `abort` بالشيفرة له رسالةٌ عربيّة في الخريطة، وكلّ نصٍّ يُمرَّر لـ`abort`
     * عربيّ، ولا متحكّم يبني صفحة خطأٍ بنفسه أو يوقف التنفيذ بـ`dd`/`die`.
     */
    public function test_the_codebase_never_bypasses_the_mapping(): void
    {
        $files = Finder::create()->files()->name('*.php')
            ->in([app_path('Http'), app_path('Support'), app_path('Domain'), app_path('Services')]);

        $statuses = [];
        $offenders = [];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file->getRealPath());
            $where = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());

            // الرمز: abort(403…) وabort_if/unless(…, 403…) وnew HttpException(422…) وnew self(422…) في TransitionDenied
            preg_match_all('/\babort(?:_if|_unless)?\((?:[^;]*?,\s*)?(\d{3})\b/', $code, $m);
            preg_match_all('/new (?:self|\w*HttpException)\((\d{3})\b/', $code, $n);
            foreach ([...$m[1], ...$n[1]] as $status) {
                $statuses[(int) $status][] = $where;
            }

            // نصٌّ حرفيّ يُمرَّر لـabort بلا حرفٍ عربيّ
            if (preg_match_all('/\babort(?:_if|_unless)?\([^;]*?\d{3}\s*,\s*\'([^\']*)\'/', $code, $texts)) {
                foreach ($texts[1] as $text) {
                    if (preg_match('/\p{Arabic}/u', $text) !== 1) {
                        $offenders[] = "{$where}: abort بنصٍّ غير عربيّ «{$text}»";
                    }
                }
            }

            if (preg_match('/(response\(\)->view|view)\(\s*[\'"]errors[.:]/', $code) === 1) {
                $offenders[] = "{$where}: يبني صفحة خطأ بنفسه";
            }

            if (preg_match('/^\s*(dd|dump|die|exit)\s*\(/m', $code) === 1) {
                $offenders[] = "{$where}: dd/dump/die";
            }
        }

        $this->assertNotEmpty($statuses, 'المسح يجد الحرّاس فعلاً — لا يمرّ فراغاً');

        foreach ($statuses as $status => $where) {
            $this->assertArrayHasKey($status, ErrorResponse::MESSAGES, "الرمز {$status} بلا رسالةٍ عربيّة — ".implode('، ', array_unique($where)));
        }

        $this->assertSame([], $offenders);
    }

    // ─── مساعدات ──────────────────────────────────────────────────────

    /**
     * فعلٌ مرفوض ⇒ عودةٌ (٣٠٣) بالسبب في `errors.message` — لا صفحة خطأ. يعيد النصّ.
     */
    private function assertActionRefused(TestResponse $response, ?string $reason = null): string
    {
        $response->assertStatus(303);
        $response->assertSessionHasErrors('message');

        $errors = session('errors');
        $message = $errors instanceof ViewErrorBag ? (string) $errors->getBag('default')->first('message') : '';

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message, 'السبب بالعربيّة');
        $this->assertDoesNotMatchRegularExpression(self::ENGLISH_ERROR_WORDS, $message);

        if ($reason !== null) {
            $this->assertStringContainsString($reason, $message);
        }

        return $message;
    }

    /** صفحة الخطأ العربيّة (`pages/error.tsx`) بالرمز ونصٍّ من الخريطة. */
    private function assertErrorPage(TestResponse $response, int $status): void
    {
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('error', false)
            ->where('status', $status)
            ->where('title', ErrorResponse::title($status))
            ->where('message', ErrorResponse::MESSAGES[$status]));
    }

    /** ترويسات نقرة Inertia على رابط — بالنسخة الحاليّة وإلّا ردّ الوسيط ٤٠٩ «تغيّرت النسخة». */
    private function inertiaGet(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    /** @return array{0:Consult,1:User} */
    private function futureConsult(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ERR-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'مؤكد',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => now()->addWeeks(3),
        ]);

        return [$consult, $lawyer];
    }

    /**
     * **دالّة التخطيط لا تقرأ خصائص الصفحة من وسيطها.** Inertia v3 تناديها أوّلاً بخصائص الصفحة لا
     * بعنصرها، فـ`page.props.auth` ينهار — وكانت صفحة الخطأ (٤٠٤) تُعرض بيضاء لكلّ من فتح رابطاً غير
     * موجود (رُصد في المتصفّح 2026-09-28). ما يحتاجه التخطيط يُقرأ بـ`usePage()` داخل مكوّن.
     */
    public function test_page_layouts_never_read_props_from_their_argument(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(resource_path('js/pages'))->name('*.tsx') as $file) {
            if (preg_match('/\.layout\s*=\s*\(?\s*(\w+)[^\n]*=>[^\n]*\b\1\.props\b/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'دالّة تخطيطٍ تقرأ `.props` من وسيطها — اقرأ الخصائص بـusePage() داخل مكوّن.');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', 'pf_test_secret_key');
        Config::set('services.pixelforge.requests_enabled', true);

        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_and_non_admins_receive_forbidden(): void
    {
        $this->get('/admin/reports')->assertRedirect('/login');

        $regularUser = User::factory()->create(['is_admin' => false]);

        $this->actingAs($regularUser)
            ->get('/admin/reports')
            ->assertForbidden();
    }

    public function test_admin_receives_combined_local_and_pixelforge_usage_report_with_exact_match(): void
    {
        $admin = User::factory()->admin()->withBalance(0)->create(['name' => 'Operator Admin']);
        $alice = User::factory()->withBalance(500_000_000)->create(['name' => 'Alice']);
        $bob = User::factory()->withBalance(250_000_000)->create(['name' => 'Bob']);

        // Day 1 UTC: 2026-11-01 (Alice + Bob)
        Generation::factory()->for($alice)->create([
            'pixelforge_id' => 'gen_day1_alice',
            'status' => GenerationStatus::Succeeded,
            'actual_provider_cost_nano' => 80_000_000,
            'actual_user_cost_nano' => 100_000_000,
            'completed_at' => '2026-11-01T12:00:00Z',
        ]);

        Generation::factory()->for($bob)->create([
            'pixelforge_id' => 'gen_day1_bob',
            'status' => GenerationStatus::Succeeded,
            'actual_provider_cost_nano' => 89_080_000,
            'actual_user_cost_nano' => 111_350_000,
            'completed_at' => '2026-11-01T23:30:00Z',
        ]);

        // Failed generation on Day 2 (must cost $0 and not affect provider cost / profit)
        Generation::factory()->for($alice)->create([
            'pixelforge_id' => 'gen_day2_failed',
            'status' => GenerationStatus::Failed,
            'actual_provider_cost_nano' => 0,
            'actual_user_cost_nano' => 0,
            'error_code' => 'generation_failed',
            'completed_at' => '2026-11-02T09:00:00Z',
        ]);

        // Unfinished processing generation for Bob
        Generation::factory()->for($bob)->create([
            'pixelforge_id' => 'gen_bob_processing',
            'status' => GenerationStatus::Processing,
            'actual_provider_cost_nano' => null,
            'actual_user_cost_nano' => null,
            'completed_at' => null,
        ]);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-02',
                'data' => [
                    [
                        'date' => '2026-11-01',
                        'generations' => 2,
                        'input_text_tokens' => 41,
                        'input_image_tokens' => 0,
                        'output_tokens' => 3300,
                        'reasoning_tokens' => 2950,
                        'cost_usd' => '0.169080000',
                    ],
                    [
                        'date' => '2026-11-02',
                        'generations' => 0,
                        'input_text_tokens' => 0,
                        'input_image_tokens' => 0,
                        'output_tokens' => 0,
                        'reasoning_tokens' => 0,
                        'cost_usd' => '0.000000000',
                    ],
                ],
                'total' => [
                    'generations' => 2,
                    'input_text_tokens' => 41,
                    'input_image_tokens' => 0,
                    'output_tokens' => 3300,
                    'reasoning_tokens' => 2950,
                    'cost_usd' => '0.169080000',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports')
            ->missing('auth.user.is_admin')
            ->where('report.available', true)
            ->where('report.error', null)
            ->where('report.filters.effective_start_date', '2026-11-01')
            ->where('report.filters.effective_end_date', '2026-11-02')
            // Combined Totals
            ->where('report.total.succeeded_count', 2)
            ->where('report.total.failed_count', 1)
            ->where('report.total.active_count', 1)
            ->where('report.total.credit_spend_nano', 211_350_000)
            ->where('report.total.credit_spend_usd', '0.211350000')
            ->where('report.total.provider_cost_nano', 169_080_000)
            ->where('report.total.provider_cost_usd', '0.169080000')
            ->where('report.total.profit_nano', 42_270_000)
            ->where('report.total.profit_usd', '0.042270000')
            ->where('report.total.remote_generations', 2)
            ->where('report.total.remote_cost_nano', 169_080_000)
            ->where('report.total.remote_cost_usd', '0.169080000')
            ->where('report.total.delta_nano', 0)
            ->where('report.total.matches_remote', true)
            // Daily rows (newest UTC date first)
            ->has('report.days', 2)
            ->where('report.days.0.date', '2026-11-02')
            ->where('report.days.0.succeeded_count', 0)
            ->where('report.days.0.failed_count', 1)
            ->where('report.days.0.credit_spend_nano', 0)
            ->where('report.days.0.provider_cost_nano', 0)
            ->where('report.days.0.remote_cost_nano', 0)
            ->where('report.days.0.matches_remote', true)
            ->where('report.days.1.date', '2026-11-01')
            ->where('report.days.1.succeeded_count', 2)
            ->where('report.days.1.credit_spend_nano', 211_350_000)
            ->where('report.days.1.provider_cost_nano', 169_080_000)
            ->where('report.days.1.profit_nano', 42_270_000)
            ->where('report.days.1.remote_generations', 2)
            ->where('report.days.1.remote_cost_usd', '0.169080000')
            ->where('report.days.1.matches_remote', true)
            // By User
            ->has('report.users', 2)
            ->where('report.users.0.id', $bob->id)
            ->where('report.users.0.succeeded_count', 1)
            ->where('report.users.0.active_count', 1)
            ->where('report.users.0.credit_spend_nano', 111_350_000)
            ->where('report.users.0.provider_cost_nano', 89_080_000)
            ->where('report.users.0.profit_nano', 22_270_000)
            ->where('report.users.0.balance_usd', '0.250000000')
            ->where('report.users.1.id', $alice->id)
            ->where('report.users.1.succeeded_count', 1)
            ->where('report.users.1.failed_count', 1)
            ->where('report.users.1.credit_spend_nano', 100_000_000)
            ->where('report.users.1.provider_cost_nano', 80_000_000)
            ->where('report.users.1.profit_nano', 20_000_000)
            // By Day & User
            ->has('report.daily_by_user', 3)
            ->where('report.daily_by_user.0.date', '2026-11-02')
            ->where('report.daily_by_user.0.user_id', $alice->id)
            ->where('report.daily_by_user.0.failed_count', 1)
            ->where('report.daily_by_user.1.date', '2026-11-01')
            ->where('report.daily_by_user.1.user_id', $bob->id)
            ->where('report.daily_by_user.1.credit_spend_nano', 111_350_000)
            ->where('report.daily_by_user.2.date', '2026-11-01')
            ->where('report.daily_by_user.2.user_id', $alice->id)
            ->where('report.daily_by_user.2.credit_spend_nano', 100_000_000)
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/usage');
    }

    public function test_admin_report_detects_mismatch_between_local_and_pixelforge_usage(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();

        Generation::factory()->for($alice)->create([
            'pixelforge_id' => 'gen_mismatch',
            'status' => GenerationStatus::Succeeded,
            'actual_provider_cost_nano' => 50_000_000,
            'actual_user_cost_nano' => 62_500_000,
            'completed_at' => '2026-11-01T12:00:00Z',
        ]);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-01',
                'data' => [
                    [
                        'date' => '2026-11-01',
                        'generations' => 2,
                        'input_text_tokens' => 20,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 900,
                        'cost_usd' => '0.085000000',
                    ],
                ],
                'total' => [
                    'generations' => 2,
                    'input_text_tokens' => 20,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 900,
                    'cost_usd' => '0.085000000',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->get('/admin/reports?start_date=2026-11-01&end_date=2026-11-01');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports')
            ->where('report.total.provider_cost_nano', 50_000_000)
            ->where('report.total.remote_cost_nano', 85_000_000)
            ->where('report.total.delta_nano', -35_000_000)
            ->where('report.total.matches_remote', false)
            ->where('report.days.0.matches_remote', false)
        );
    }

    public function test_admin_report_resolves_single_bound_filters_with_30_day_default_and_today_utc(): void
    {
        Carbon::setTestNow('2026-11-22T18:00:00Z');

        $admin = User::factory()->admin()->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'data' => [],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get('/admin/reports?start_date=2026-11-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.filters.effective_start_date', '2026-11-10')
                ->where('report.filters.effective_end_date', '2026-11-22')
            );

        $this->actingAs($admin)
            ->get('/admin/reports?end_date=2026-11-20')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.filters.effective_start_date', '2026-10-22')
                ->where('report.filters.effective_end_date', '2026-11-20')
            );

        $recorded = Http::recorded();
        $this->assertSame(
            'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-10&end_date=2026-11-22',
            $recorded[0][0]->url(),
        );
        $this->assertSame(
            'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-10-22&end_date=2026-11-20',
            $recorded[1][0]->url(),
        );
    }

    public function test_admin_report_validates_date_order_and_max_92_day_span(): void
    {
        Carbon::setTestNow('2026-11-22T18:00:00Z');

        $admin = User::factory()->admin()->create();

        Http::fake();

        $this->actingAs($admin)
            ->from('/admin/reports')
            ->get('/admin/reports?start_date=2026-11-20&end_date=2026-11-10')
            ->assertRedirect('/admin/reports')
            ->assertSessionHasErrors(['end_date']);

        $this->actingAs($admin)
            ->from('/admin/reports')
            ->get('/admin/reports?start_date=2026-01-01&end_date=2026-04-03')
            ->assertRedirect('/admin/reports')
            ->assertSessionHasErrors(['end_date']);

        $this->actingAs($admin)
            ->from('/admin/reports')
            ->get('/admin/reports?start_date=2026-08-01')
            ->assertRedirect('/admin/reports')
            ->assertSessionHasErrors(['start_date']);

        $this->actingAs($admin)
            ->from('/admin/reports')
            ->get('/admin/reports?start_date=2026-11-25')
            ->assertRedirect('/admin/reports')
            ->assertSessionHasErrors(['start_date']);

        Http::assertNothingSent();
    }

    public function test_admin_report_still_serves_local_metrics_when_pixelforge_usage_api_is_unavailable(): void
    {
        Carbon::setTestNow('2026-11-22T18:00:00Z');

        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create(['name' => 'Alice']);

        Generation::factory()->for($alice)->create([
            'pixelforge_id' => 'gen_local_when_api_down',
            'status' => GenerationStatus::Succeeded,
            'actual_provider_cost_nano' => 40_000_000,
            'actual_user_cost_nano' => 50_000_000,
            'completed_at' => '2026-11-20T12:00:00Z',
        ]);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Service unavailable'],
            ], 503),
        ]);

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports')
            ->where('report.available', false)
            ->where('report.filters.effective_start_date', '2026-10-24')
            ->where('report.filters.effective_end_date', '2026-11-22')
            ->where('report.total.succeeded_count', 1)
            ->where('report.total.credit_spend_nano', 50_000_000)
            ->where('report.total.provider_cost_nano', 40_000_000)
            ->where('report.total.profit_nano', 10_000_000)
            ->where('report.total.matches_remote', null)
            ->has('report.days', 30)
            ->where('report.days.0.date', '2026-11-22')
            ->where('report.days.0.succeeded_count', 0)
            ->where('report.days.0.credit_spend_nano', 0)
            ->where('report.days.2.date', '2026-11-20')
            ->where('report.days.2.succeeded_count', 1)
            ->where('report.days.2.credit_spend_nano', 50_000_000)
            ->where('report.days.2.matches_remote', null)
            ->where('report.days.29.date', '2026-10-24')
            ->where('report.days.29.succeeded_count', 0)
            ->has('report.users', 1)
            ->where('report.users.0.credit_spend_nano', 50_000_000)
            ->has('report.daily_by_user', 1)
        );
    }
}

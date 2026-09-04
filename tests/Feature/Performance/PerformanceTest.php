<?php

namespace Tests\Feature\Performance;

use App\Models\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

/**
 * These are smoke-level performance sanity checks, not authoritative
 * benchmarks. They run against SQLite :memory: on whatever shared CI/dev
 * hardware happens to run the suite, so timings here are inherently
 * noisy (PHP process startup, no OPcache warm-up, no real disk I/O).
 *
 * The thresholds below are intentionally generous (seconds, not the
 * production targets of <200ms / <500ms) so this suite stays stable
 * across machines and never becomes a source of CI flakiness. They only
 * assert that listing, filtering and export do not scale catastrophically
 * (e.g. an accidental N+1 query) against a representative dataset.
 *
 * Authoritative <200ms (listing/filtering) and <500ms (export) benchmarks
 * must be measured in a production-like environment: real MySQL (with the
 * `content` FULLTEXT index in use), PHP with OPcache enabled, and without
 * the overhead of the testing framework's transaction wrapping.
 *
 * This class is tagged #[Group('performance')] so it can be run in
 * isolation (`php artisan test --group=performance`) or excluded from a
 * fast local loop (`php artisan test --exclude-group=performance`). It is
 * included in the default `php artisan test` run because it stays fast
 * and deterministic at this dataset size (~100 records).
 */
#[Group('performance')]
class PerformanceTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    private const RECORD_COUNT = 100;

    /**
     * Generous smoke-test ceiling in milliseconds. Not a production SLA.
     */
    private const SANITY_CEILING_MS = 5000;

    private function generateDataset(): void
    {
        Locale::query()->insert([
            ['name' => 'English', 'code' => 'en', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'French', 'code' => 'fr', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Spanish', 'code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('translations:generate', ['count' => self::RECORD_COUNT])
            ->assertSuccessful();
    }

    private function timeMs(callable $callback): float
    {
        $start = microtime(true);
        $callback();

        return (microtime(true) - $start) * 1000;
    }

    public function test_listing_one_hundred_records_completes_within_a_sane_bound(): void
    {
        $this->generateDataset();
        $this->actingAsUser();

        $elapsed = $this->timeMs(function (): void {
            $this->getJson('/api/translations?per_page=100')->assertOk();
        });

        $this->assertLessThan(
            self::SANITY_CEILING_MS,
            $elapsed,
            'Listing 100 records took far longer than expected: '.round($elapsed).'ms. '
            .'This checks for catastrophic regressions only; it is not the '
            .'authoritative <200ms production benchmark (see class docblock).'
        );
    }

    public function test_content_and_key_filtering_completes_within_a_sane_bound(): void
    {
        $this->generateDataset();
        $this->actingAsUser();

        $elapsed = $this->timeMs(function (): void {
            $this->getJson('/api/translations?content=Generated+translation')->assertOk();
            $this->getJson('/api/translations?key=generated.key')->assertOk();
        });

        $this->assertLessThan(
            self::SANITY_CEILING_MS,
            $elapsed,
            'Filtering 100 records took far longer than expected: '.round($elapsed).'ms.'
        );
    }

    public function test_json_export_completes_within_a_sane_bound(): void
    {
        $this->generateDataset();
        $this->actingAsUser();

        $elapsed = $this->timeMs(function (): void {
            $this->getJson('/api/locales/en/translations/export')->assertOk();
        });

        $this->assertLessThan(
            self::SANITY_CEILING_MS,
            $elapsed,
            'Exporting a representative dataset took far longer than expected: '.round($elapsed).'ms.'
        );
    }
}

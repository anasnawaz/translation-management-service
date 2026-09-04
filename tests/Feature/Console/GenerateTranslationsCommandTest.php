<?php

namespace Tests\Feature\Console;

use App\Models\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateTranslationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveLocales(): void
    {
        Locale::query()->insert([
            ['name' => 'English', 'code' => 'en', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'French', 'code' => 'fr', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Spanish', 'code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_command_fails_cleanly_when_no_active_locales_exist(): void
    {
        $this->artisan('translations:generate', ['count' => 20])
            ->assertFailed();

        $this->assertDatabaseCount('translations', 0);
    }

    public function test_command_generates_the_requested_number_of_records(): void
    {
        $this->seedActiveLocales();

        $this->artisan('translations:generate', ['count' => 30])
            ->assertSuccessful();

        $this->assertDatabaseCount('translations', 30);
    }

    public function test_command_creates_the_expected_tags(): void
    {
        $this->seedActiveLocales();

        $this->artisan('translations:generate', ['count' => 20])
            ->assertSuccessful();

        $tagNames = DB::table('tags')->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['authentication', 'dashboard', 'marketing', 'mobile', 'web'],
            $tagNames
        );
    }

    public function test_generated_translations_are_associated_with_active_locales(): void
    {
        $this->seedActiveLocales();
        // An inactive locale should never receive generated translations.
        Locale::query()->create(['name' => 'German', 'code' => 'de', 'is_active' => false]);

        $this->artisan('translations:generate', ['count' => 30])
            ->assertSuccessful();

        $activeLocaleIds = Locale::query()->where('is_active', true)->pluck('id');
        $usedLocaleIds = DB::table('translations')->distinct()->pluck('locale_id');

        foreach ($usedLocaleIds as $localeId) {
            $this->assertContains($localeId, $activeLocaleIds);
        }
    }

    public function test_fresh_option_removes_previously_generated_records(): void
    {
        $this->seedActiveLocales();

        $this->artisan('translations:generate', ['count' => 20])->assertSuccessful();
        $this->assertDatabaseCount('translations', 20);

        $this->artisan('translations:generate', ['count' => 10, '--fresh' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('translations', 10);
    }

    public function test_rerunning_the_command_does_not_create_duplicate_key_locale_combinations(): void
    {
        $this->seedActiveLocales();

        $this->artisan('translations:generate', ['count' => 30])->assertSuccessful();
        $this->artisan('translations:generate', ['count' => 30])->assertSuccessful();

        $this->assertDatabaseCount('translations', 30);

        $duplicates = DB::table('translations')
            ->select('translation_key_id', 'locale_id')
            ->groupBy('translation_key_id', 'locale_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);
    }
}

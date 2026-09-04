<?php

namespace Tests\Feature\Translation;

use App\Models\TranslationKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class CreateTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_translation(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('translation_keys', ['key' => 'welcome.title']);
        $this->assertDatabaseHas('translations', ['content' => 'Welcome!']);
    }

    public function test_guest_cannot_create_a_translation(): void
    {
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('translations', 0);
    }

    public function test_translation_key_is_normalized_to_lowercase_and_trimmed(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => '  Welcome.TITLE  ',
            'locale' => 'en',
            'content' => 'Welcome!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.key', 'welcome.title');

        $this->assertDatabaseHas('translation_keys', ['key' => 'welcome.title']);
    }

    public function test_tags_are_normalized_and_duplicates_removed(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
            'tags' => ['  Web  ', 'web', 'MOBILE', 'mobile'],
        ]);

        $response->assertCreated();

        $tags = $response->json('data.tags');
        sort($tags);

        $this->assertSame(['mobile', 'web'], $tags);
    }

    public function test_key_locale_content_and_tags_are_stored_correctly(): void
    {
        $this->actingAsUser();
        $this->activeLocale('fr', 'French');

        $response = $this->postJson('/api/translations', [
            'key' => 'homepage.hero',
            'locale' => 'fr',
            'content' => 'Bienvenue',
            'description' => 'Hero title',
            'tags' => ['web', 'marketing'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.key', 'homepage.hero')
            ->assertJsonPath('data.content', 'Bienvenue')
            ->assertJsonPath('data.description', 'Hero title')
            ->assertJsonPath('data.locale.code', 'fr');

        $tags = $response->json('data.tags');
        sort($tags);
        $this->assertSame(['marketing', 'web'], $tags);
    }

    public function test_same_key_can_be_created_for_different_locales(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->activeLocale('fr', 'French');

        $this->postJson('/api/translations', [
            'key' => 'shared.key',
            'locale' => 'en',
            'content' => 'Hello',
        ])->assertCreated();

        $this->postJson('/api/translations', [
            'key' => 'shared.key',
            'locale' => 'fr',
            'content' => 'Bonjour',
        ])->assertCreated();

        $this->assertDatabaseCount('translation_keys', 1);
        $this->assertDatabaseCount('translations', 2);
    }

    public function test_same_key_and_locale_combination_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $this->postJson('/api/translations', [
            'key' => 'shared.key',
            'locale' => 'en',
            'content' => 'Hello',
        ])->assertCreated();

        $response = $this->postJson('/api/translations', [
            'key' => 'shared.key',
            'locale' => 'en',
            'content' => 'Hello again',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);

        $this->assertDatabaseCount('translations', 1);
    }

    /**
     * TranslationService::create() has two layers of duplicate protection:
     * a cheap exists() pre-check, and the `translations_key_locale_unique`
     * database constraint as the real guarantee. This test forces the
     * *second* layer to be the one that catches the duplicate, by
     * inserting a conflicting row directly in between the pre-check query
     * and the service's own insert — exactly what a truly concurrent
     * request could do in that same window. It exercises the exact code
     * path a real race would trigger: catching
     * Illuminate\Database\UniqueConstraintViolationException from the
     * insert and converting it into the same clean 422 the pre-check
     * produces.
     *
     * Documented limitation: a genuine two-process race — where the
     * "other" request's transaction commits independently of this one —
     * cannot be reproduced against SQLite's single-connection :memory:
     * database inside a synchronous PHPUnit run. The injected write here
     * happens on the *same* connection and therefore the *same*
     * transaction as the request under test, so it is rolled back along
     * with everything else once the constraint violation is converted to
     * a ValidationException. That is actually useful to assert in its
     * own right: it shows the whole create() operation still rolls back
     * atomically (no orphaned rows) even when the duplicate is only
     * caught at the database layer. On MySQL, two real concurrent
     * connections would behave the same way from each request's own
     * point of view — the losing request gets a clean 422 and nothing it
     * wrote survives — but that cross-connection behavior itself is not
     * covered by this SQLite-only suite.
     */
    public function test_a_duplicate_that_only_the_database_constraint_catches_still_returns_a_clean_422(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        DB::listen(function (QueryExecuted $event): void {
            if (! str_contains($event->sql, 'select exists')) {
                return;
            }

            $translationKey = TranslationKey::query()
                ->where('key', 'race.key')
                ->first();

            if (! $translationKey) {
                return;
            }

            $locale = $this->activeLocale('en', 'English');

            // Simulates a concurrent request's insert landing in the gap
            // between this request's pre-check and its own insert.
            DB::table('translations')->insert([
                'translation_key_id' => $translationKey->id,
                'locale_id' => $locale->id,
                'content' => 'Inserted concurrently',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->postJson('/api/translations', [
                'key' => 'race.key',
                'locale' => 'en',
                'content' => 'Original request',
            ]);
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);

        // The database-level catch fired (proven by the 422 above,
        // instead of a 500 from an uncaught QueryException). Because the
        // simulated concurrent write shares this request's transaction on
        // SQLite, the rollback also undoes it, leaving zero rows — which
        // confirms create() still rolls back atomically on this path.
        $this->assertDatabaseCount('translations', 0);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'zz',
            'content' => 'Welcome!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);
    }

    public function test_inactive_locale_is_rejected(): void
    {
        $this->actingAsUser();
        $this->inactiveLocale('de', 'German');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'de',
            'content' => 'Willkommen!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);
    }

    public function test_invalid_translation_key_format_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'invalid key with spaces!',
            'locale' => 'en',
            'content' => 'Welcome!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    public function test_empty_content_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_missing_content_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_too_many_tags_are_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
            'tags' => array_map(fn (int $i): string => "tag{$i}", range(1, 21)),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags']);
    }

    public function test_invalid_tag_format_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
            'tags' => ['invalid tag!'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags.0']);
    }

    public function test_a_500_character_description_is_accepted_and_stored_without_truncation(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $description = str_repeat('a', 500);

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
            'description' => $description,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.description', $description);

        // Round-trip through the database, not just the response payload:
        // this guards against the `translation_keys.description` column
        // being narrower than the 500 characters validation allows, which
        // would truncate (or, in MySQL strict mode, reject) the value.
        $this->assertSame(
            $description,
            $this->getJson('/api/translations')->json('data.0.description')
        );
        $this->assertDatabaseHas('translation_keys', [
            'key' => 'welcome.title',
            'description' => $description,
        ]);
    }

    public function test_a_501_character_description_is_rejected(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->postJson('/api/translations', [
            'key' => 'welcome.title',
            'locale' => 'en',
            'content' => 'Welcome!',
            'description' => str_repeat('a', 501),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);

        $this->assertDatabaseMissing('translation_keys', ['key' => 'welcome.title']);
    }
}

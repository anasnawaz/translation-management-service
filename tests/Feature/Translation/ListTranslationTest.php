<?php

namespace Tests\Feature\Translation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class ListTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_translation_listing_requires_authentication(): void
    {
        $this->getJson('/api/translations')->assertUnauthorized();
    }

    public function test_listing_returns_the_expected_resource_structure(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'Hello', tags: ['web']);

        $response = $this->getJson('/api/translations');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['id', 'key', 'description', 'locale' => ['code', 'name'], 'content', 'tags', 'created_at', 'updated_at'],
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['path', 'per_page', 'next_cursor', 'prev_cursor'],
        ]);
    }

    public function test_cursor_pagination_paginates_through_all_results_without_duplicates_or_gaps(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 5; $i++) {
            $this->createTranslation(content: "content {$i}");
        }

        $seenIds = [];
        $cursor = null;

        do {
            $url = '/api/translations?per_page=2'.($cursor ? '&cursor='.$cursor : '');
            $response = $this->getJson($url)->assertOk();

            foreach ($response->json('data') as $item) {
                $seenIds[] = $item['id'];
            }

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(5, $seenIds);
        $this->assertCount(5, array_unique($seenIds));
    }

    public function test_per_page_is_validated_and_capped_at_the_maximum(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/translations?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/translations?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/translations?per_page=100')->assertOk();
    }

    public function test_sorting_by_allowed_fields_works(): void
    {
        $this->actingAsUser();
        $first = $this->createTranslation(content: 'first');
        $second = $this->createTranslation(content: 'second');

        $response = $this->getJson('/api/translations?sort_by=id&sort_direction=asc');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_invalid_sort_field_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/translations?sort_by=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_invalid_sort_direction_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/translations?sort_direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_direction']);
    }

    public function test_key_filtering_works(): void
    {
        $this->actingAsUser();
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'homepage.title')
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'footer.title')
        );

        $response = $this->getJson('/api/translations?key=homepage');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('homepage.title', $response->json('data.0.key'));
    }

    public function test_locale_filtering_works(): void
    {
        $this->actingAsUser();
        $en = $this->activeLocale('en', 'English');
        $fr = $this->activeLocale('fr', 'French');
        $key = $this->createTranslationKey(key: 'shared.key');

        $this->createTranslation(translationKey: $key, locale: $en, content: 'Hello');
        $this->createTranslation(translationKey: $key, locale: $fr, content: 'Bonjour');

        $response = $this->getJson('/api/translations?locale=fr');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('fr', $response->json('data.0.locale.code'));
    }

    public function test_single_tag_filtering_works(): void
    {
        $this->actingAsUser();
        $this->createTranslation(tags: ['web']);
        $this->createTranslation(tags: ['mobile']);

        $response = $this->getJson('/api/translations?tags[]=web');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(['web'], $response->json('data.0.tags'));
    }

    public function test_multiple_tag_filtering_follows_or_behavior(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'web only', tags: ['web']);
        $this->createTranslation(content: 'mobile only', tags: ['mobile']);
        $this->createTranslation(content: 'desktop only', tags: ['desktop']);

        $response = $this->getJson('/api/translations?tags[]=web&tags[]=mobile');

        $response->assertOk();
        $contents = array_column($response->json('data'), 'content');
        sort($contents);

        $this->assertSame(['mobile only', 'web only'], $contents);
    }

    public function test_content_search_works_using_sqlites_like_fallback(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'The quick brown fox');
        $this->createTranslation(content: 'Something else entirely');

        $response = $this->getJson('/api/translations?content=quick+brown');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('The quick brown fox', $response->json('data.0.content'));
    }

    public function test_general_search_finds_matching_keys(): void
    {
        $this->actingAsUser();
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'dashboard.title'),
            content: 'Dashboard'
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'footer.copyright'),
            content: 'Copyright'
        );

        $response = $this->getJson('/api/translations?search=dashboard');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('dashboard.title', $response->json('data.0.key'));
    }

    public function test_general_search_finds_matching_content(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'A very unique phrase here');
        $this->createTranslation(content: 'Nothing related');

        $response = $this->getJson('/api/translations?search=unique+phrase');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A very unique phrase here', $response->json('data.0.content'));
    }

    public function test_multiple_filters_can_be_combined(): void
    {
        $this->actingAsUser();
        $en = $this->activeLocale('en', 'English');
        $fr = $this->activeLocale('fr', 'French');

        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'homepage.hero'),
            locale: $en,
            content: 'Welcome',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'homepage.hero.fr'),
            locale: $fr,
            content: 'Bienvenue',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'homepage.footer'),
            locale: $en,
            content: 'Footer',
            tags: ['mobile']
        );

        $response = $this->getJson('/api/translations?key=homepage&locale=en&tags[]=web');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('homepage.hero', $response->json('data.0.key'));
    }

    public function test_listing_eager_loads_relations_to_avoid_n_plus_1_queries(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 5; $i++) {
            $this->createTranslation(content: "content {$i}", tags: ['web', 'mobile']);
        }

        DB::enableQueryLog();

        $this->getJson('/api/translations')->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Regardless of the number of translations returned, the query
        // count should stay small and bounded (one query per relation,
        // not one per row): translations + translationKey + locale + tags.
        $this->assertLessThanOrEqual(6, count($queries));
    }
}

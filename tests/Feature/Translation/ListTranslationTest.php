<?php

namespace Tests\Feature\Translation;

use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * `created_at`/`updated_at` are not unique - several translations can
     * share the exact same timestamp (e.g. ones created moments apart, or
     * batch-generated). Without a tie-breaker, cursorPaginate() would have
     * no deterministic way to resume from the last row of a page whenever
     * timestamps tie, and rows could be skipped or repeated across pages.
     * This forces every translation in the set to share one identical
     * timestamp on the sorted column, so the *only* thing that can produce
     * a stable order is the `id` tie-breaker.
     */
    #[DataProvider('deterministicSortColumnProvider')]
    public function test_cursor_pagination_is_deterministic_when_the_sort_column_has_duplicate_values(
        string $sortBy,
        string $sortDirection
    ): void {
        $this->actingAsUser();

        $tiedTimestamp = now()->startOfSecond();
        $expectedIds = [];

        for ($i = 0; $i < 5; $i++) {
            $translation = $this->createTranslation(content: "tied content {$i}");

            // Bypass Eloquent's automatic touch-on-save so every row ends
            // up with the *exact* same timestamp, on both MySQL and
            // SQLite, regardless of how quickly the loop runs.
            Translation::query()
                ->whereKey($translation->id)
                ->update([$sortBy => $tiedTimestamp]);

            $expectedIds[] = $translation->id;
        }

        $seenIds = [];
        $cursor = null;

        do {
            $url = "/api/translations?per_page=2&sort_by={$sortBy}&sort_direction={$sortDirection}"
                .($cursor ? '&cursor='.$cursor : '');

            $response = $this->getJson($url)->assertOk();

            foreach ($response->json('data') as $item) {
                $seenIds[] = $item['id'];
            }

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        // Every expected id was returned, none skipped, none repeated.
        $this->assertCount(count($expectedIds), $seenIds);
        $this->assertCount(count($expectedIds), array_unique($seenIds));
        $this->assertEqualsCanonicalizing($expectedIds, $seenIds);

        // Since every row ties on the primary sort column, ordering can
        // only have come from the `id` tie-breaker, applied in the same
        // direction as the primary sort.
        $expectedOrder = $sortDirection === 'asc'
            ? $expectedIds
            : array_reverse($expectedIds);

        $this->assertSame($expectedOrder, $seenIds);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deterministicSortColumnProvider(): array
    {
        return [
            'created_at asc' => ['created_at', 'asc'],
            'created_at desc' => ['created_at', 'desc'],
            'updated_at asc' => ['updated_at', 'asc'],
            'updated_at desc' => ['updated_at', 'desc'],
        ];
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

    public function test_repeated_array_tag_filter_matches_case_insensitively(): void
    {
        $this->actingAsUser();
        $this->createTranslation(tags: ['web']);
        $this->createTranslation(tags: ['mobile']);

        $response = $this->getJson('/api/translations?tags[]=Web');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(['web'], $response->json('data.0.tags'));
    }

    public function test_repeated_array_tags_are_trimmed_and_deduplicated_before_the_max_count_check(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'web content', tags: ['web']);

        // 25 raw `tags[]` entries - all case/whitespace variants of the
        // same tag. If they were not trimmed, lowercased, and
        // de-duplicated *before* the `max:20` rule runs, this request
        // would be rejected as carrying too many tags, even though it
        // represents exactly one real tag.
        $variants = array_map(
            fn (int $i): string => $i % 2 === 0 ? ' Web ' : 'WEB',
            range(1, 25)
        );

        $query = collect($variants)
            ->map(fn (string $tag): string => 'tags[]='.urlencode($tag))
            ->implode('&');

        $response = $this->getJson('/api/translations?'.$query);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('web content', $response->json('data.0.content'));
    }

    public function test_repeated_array_tag_values_with_surrounding_whitespace_are_trimmed(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'web content', tags: ['web']);

        $response = $this->getJson('/api/translations?tags[]='.urlencode('  Web  '));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('web content', $response->json('data.0.content'));
    }

    public function test_comma_separated_tag_filter_still_works_case_insensitively(): void
    {
        $this->actingAsUser();
        $this->createTranslation(content: 'web content', tags: ['web']);
        $this->createTranslation(content: 'mobile content', tags: ['mobile']);
        $this->createTranslation(content: 'desktop content', tags: ['desktop']);

        $response = $this->getJson('/api/translations?tags=Web,MOBILE');

        $response->assertOk();
        $contents = array_column($response->json('data'), 'content');
        sort($contents);

        $this->assertSame(['mobile content', 'web content'], $contents);
    }

    public function test_nested_array_tag_values_are_rejected_with_a_422(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/translations?tags[0]=web&tags[1][]=x&tags[1][]=y');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags.1']);
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

<?php

namespace Tests\Feature\Translation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class ExportTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $this->activeLocale('en', 'English');

        $this->getJson('/api/locales/en/translations/export')
            ->assertUnauthorized();
    }

    public function test_valid_locale_returns_a_flat_frontend_friendly_json_object(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'welcome.title'),
            content: 'Welcome'
        );

        $response = $this->getJson('/api/locales/en/translations/export');

        $response->assertOk();
        $this->assertSame(['welcome.title' => 'Welcome'], $response->json());
    }

    public function test_export_contains_key_content_pairs(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'a.b'),
            content: 'First'
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'c.d'),
            content: 'Second'
        );

        $response = $this->getJson('/api/locales/en/translations/export');

        $response->assertOk()
            ->assertJson([
                'a.b' => 'First',
                'c.d' => 'Second',
            ]);
    }

    public function test_export_only_contains_translations_for_the_requested_locale(): void
    {
        $this->actingAsUser();
        $en = $this->activeLocale('en', 'English');
        $fr = $this->activeLocale('fr', 'French');
        $key = $this->createTranslationKey(key: 'shared.key');

        $this->createTranslation(translationKey: $key, locale: $en, content: 'Hello');
        $this->createTranslation(translationKey: $key, locale: $fr, content: 'Bonjour');

        $response = $this->getJson('/api/locales/en/translations/export');

        $response->assertOk();
        $this->assertSame(['shared.key' => 'Hello'], $response->json());
    }

    public function test_tag_filtered_export_works(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'web.only'),
            content: 'Web content',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'mobile.only'),
            content: 'Mobile content',
            tags: ['mobile']
        );

        $response = $this->getJson('/api/locales/en/translations/export?tags[]=web');

        $response->assertOk();
        $this->assertSame(['web.only' => 'Web content'], $response->json());
    }

    public function test_multiple_tags_follow_or_behavior_in_export(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'web.only'),
            content: 'Web content',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'mobile.only'),
            content: 'Mobile content',
            tags: ['mobile']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'desktop.only'),
            content: 'Desktop content',
            tags: ['desktop']
        );

        $response = $this->getJson('/api/locales/en/translations/export?tags[]=web&tags[]=mobile');

        $response->assertOk();
        $this->assertSame([
            'web.only' => 'Web content',
            'mobile.only' => 'Mobile content',
        ], $response->json());
    }

    public function test_mixed_case_repeated_array_tag_filter_works_in_export(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'web.only'),
            content: 'Web content',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'mobile.only'),
            content: 'Mobile content',
            tags: ['mobile']
        );

        $response = $this->getJson('/api/locales/en/translations/export?tags[]=Web');

        $response->assertOk();
        $this->assertSame(['web.only' => 'Web content'], $response->json());
    }

    public function test_repeated_array_export_tags_are_trimmed_and_deduplicated_before_the_max_count_check(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'web.only'),
            content: 'Web content',
            tags: ['web']
        );

        // 25 raw `tags[]` entries - all case/whitespace variants of the
        // same tag. If they were not trimmed, lowercased, and
        // de-duplicated *before* the `max:20` rule runs, this request
        // would be rejected as carrying too many tags.
        $variants = array_map(
            fn (int $i): string => $i % 2 === 0 ? ' Web ' : 'WEB',
            range(1, 25)
        );

        $query = collect($variants)
            ->map(fn (string $tag): string => 'tags[]='.urlencode($tag))
            ->implode('&');

        $response = $this->getJson('/api/locales/en/translations/export?'.$query);

        $response->assertOk();
        $this->assertSame(['web.only' => 'Web content'], $response->json());
    }

    public function test_comma_separated_export_tag_filter_still_works_case_insensitively(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'web.only'),
            content: 'Web content',
            tags: ['web']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'mobile.only'),
            content: 'Mobile content',
            tags: ['mobile']
        );
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'desktop.only'),
            content: 'Desktop content',
            tags: ['desktop']
        );

        $response = $this->getJson('/api/locales/en/translations/export?tags=Web,MOBILE');

        $response->assertOk();
        $this->assertSame([
            'web.only' => 'Web content',
            'mobile.only' => 'Mobile content',
        ], $response->json());
    }

    public function test_nested_array_export_tag_values_are_rejected_with_a_422(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->getJson('/api/locales/en/translations/export?tags[0]=web&tags[1][]=x&tags[1][]=y');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags.1']);
    }

    public function test_invalid_locale_returns_a_clean_404_response(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/locales/zz/translations/export');

        $response->assertNotFound()
            ->assertJsonStructure(['message', 'errors' => ['locale']]);
    }

    public function test_inactive_locale_returns_404(): void
    {
        $this->actingAsUser();
        $this->inactiveLocale('de', 'German');

        $response = $this->getJson('/api/locales/de/translations/export');

        $response->assertNotFound();
    }

    public function test_empty_locale_export_returns_an_empty_json_object_not_an_array(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $response = $this->getJson('/api/locales/en/translations/export');

        $response->assertOk();
        $this->assertSame('{}', $response->streamedContent());
    }

    public function test_updating_content_is_immediately_reflected_in_the_next_export_request(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $translation = $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'welcome.title'),
            content: 'Original'
        );

        $this->getJson('/api/locales/en/translations/export')
            ->assertJson(['welcome.title' => 'Original']);

        $this->putJson("/api/translations/{$translation->id}", [
            'content' => 'Updated',
        ])->assertOk();

        $this->getJson('/api/locales/en/translations/export')
            ->assertJson(['welcome.title' => 'Updated']);
    }

    public function test_creating_a_translation_is_immediately_reflected_in_export(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');

        $initial = $this->getJson('/api/locales/en/translations/export');
        $initial->assertOk();
        $this->assertSame('{}', $initial->streamedContent());

        $this->postJson('/api/translations', [
            'key' => 'new.key',
            'locale' => 'en',
            'content' => 'Brand new',
        ])->assertCreated();

        $this->getJson('/api/locales/en/translations/export')
            ->assertJson(['new.key' => 'Brand new']);
    }

    public function test_deleting_a_translation_removes_it_from_export(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $translation = $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'to.delete'),
            content: 'Will be removed'
        );

        $this->getJson('/api/locales/en/translations/export')
            ->assertJson(['to.delete' => 'Will be removed']);

        $this->deleteJson("/api/translations/{$translation->id}")
            ->assertNoContent();

        $response = $this->getJson('/api/locales/en/translations/export');
        $response->assertOk();
        $this->assertArrayNotHasKey('to.delete', $response->json());
    }

    public function test_unicode_translation_content_is_returned_correctly(): void
    {
        $this->actingAsUser();
        $this->activeLocale('ja', 'Japanese');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'greeting'),
            locale: $this->activeLocale('ja', 'Japanese'),
            content: 'こんにちは世界 🌏'
        );

        $response = $this->getJson('/api/locales/ja/translations/export');

        $response->assertOk();
        $this->assertSame(['greeting' => 'こんにちは世界 🌏'], $response->json());
    }

    public function test_urls_and_special_characters_remain_valid_json(): void
    {
        $this->actingAsUser();
        $this->activeLocale('en', 'English');
        $this->createTranslation(
            translationKey: $this->createTranslationKey(key: 'link'),
            content: 'Visit "https://example.com?a=1&b=2" & <enjoy>'
        );

        $response = $this->getJson('/api/locales/en/translations/export');

        $response->assertOk();
        $this->assertSame(
            'Visit "https://example.com?a=1&b=2" & <enjoy>',
            $response->json('link')
        );
    }

    public function test_streamed_export_remains_complete_after_the_flush_boundary(): void
    {
        $this->actingAsUser();

        $locale = $this->activeLocale('en', 'English');

        for ($number = 1; $number <= 501; $number++) {
            $this->createTranslation(
                translationKey: $this->createTranslationKey(
                    key: sprintf('stream.key.%03d', $number)
                ),
                locale: $locale,
                content: "Content {$number}"
            );
        }

        $response = $this->getJson(
            '/api/locales/en/translations/export'
        );

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/json; charset=utf-8'
            )
            ->assertHeader('X-Accel-Buffering', 'no');

        $content = $response->streamedContent();

        $translations = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertCount(501, $translations);

        $this->assertSame(
            'Content 1',
            $translations['stream.key.001']
        );

        $this->assertSame(
            'Content 500',
            $translations['stream.key.500']
        );

        $this->assertSame(
            'Content 501',
            $translations['stream.key.501']
        );
    }
}

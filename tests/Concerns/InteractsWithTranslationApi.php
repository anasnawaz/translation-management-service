<?php

namespace Tests\Concerns;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\TranslationKey;
use App\Models\User;
use Database\Factories\LocaleFactory;
use Database\Factories\TranslationFactory;
use Database\Factories\TranslationKeyFactory;
use Laravel\Sanctum\Sanctum;

/**
 * Shared helpers for authenticating and seeding translation data in
 * feature tests, kept out of the base TestCase so it stays opt-in.
 */
trait InteractsWithTranslationApi
{
    protected function actingAsUser(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function activeLocale(
        string $code = 'en',
        string $name = 'English'
    ): Locale {
        return Locale::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_active' => true]
        );
    }

    protected function inactiveLocale(
        string $code = 'de',
        string $name = 'German'
    ): Locale {
        return LocaleFactory::new()->inactive()->create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    protected function createTag(string $name): Tag
    {
        return Tag::query()->firstOrCreate(['name' => $name]);
    }

    protected function createTranslationKey(
        ?string $key = null,
        ?string $description = null
    ): TranslationKey {
        return TranslationKeyFactory::new()->create(array_filter([
            'key' => $key,
            'description' => $description,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<int, string>  $tags
     */
    protected function createTranslation(
        ?TranslationKey $translationKey = null,
        ?Locale $locale = null,
        ?string $content = null,
        array $tags = []
    ): Translation {
        $translation = TranslationFactory::new()
            ->forKey($translationKey ?? $this->createTranslationKey())
            ->forLocale($locale ?? $this->activeLocale())
            ->create(array_filter([
                'content' => $content,
            ], fn ($value) => $value !== null));

        if ($tags !== []) {
            $translation->tags()->sync(
                collect($tags)->map(
                    fn (string $tag) => $this->createTag($tag)->id
                )
            );
        }

        return $translation->fresh(['translationKey', 'locale', 'tags']);
    }
}

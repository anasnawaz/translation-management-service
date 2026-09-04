<?php

namespace Database\Factories;

use App\Models\Locale;
use App\Models\Translation;
use App\Models\TranslationKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Translation>
 */
class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'translation_key_id' => TranslationKeyFactory::new(),
            'locale_id' => LocaleFactory::new(),
            'content' => fake()->sentence(),
        ];
    }

    public function forLocale(Locale $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'locale_id' => $locale->id,
        ]);
    }

    public function forKey(TranslationKey $translationKey): static
    {
        return $this->state(fn (array $attributes): array => [
            'translation_key_id' => $translationKey->id,
        ]);
    }
}

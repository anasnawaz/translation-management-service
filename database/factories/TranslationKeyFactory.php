<?php

namespace Database\Factories;

use App\Models\TranslationKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TranslationKey>
 */
class TranslationKeyFactory extends Factory
{
    protected $model = TranslationKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.str_replace('-', '.', fake()->unique()->slug(3)),
            'description' => fake()->sentence(),
        ];
    }
}

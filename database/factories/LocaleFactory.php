<?php

namespace Database\Factories;

use App\Models\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locale>
 */
class LocaleFactory extends Factory
{
    protected $model = Locale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->languageCode().'-'.fake()->word(),
            'code' => fake()->unique()->lexify('??').fake()->randomNumber(4),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}

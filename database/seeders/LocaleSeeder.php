<?php

namespace Database\Seeders;

use App\Models\Locale;
use Illuminate\Database\Seeder;

class LocaleSeeder extends Seeder
{
    public function run(): void
    {
        Locale::query()->upsert(
            [
                [
                    'name' => 'English',
                    'code' => 'en',
                    'is_active' => true,
                ],
                [
                    'name' => 'French',
                    'code' => 'fr',
                    'is_active' => true,
                ],
                [
                    'name' => 'Spanish',
                    'code' => 'es',
                    'is_active' => true,
                ],
            ],
            ['code'],
            ['name', 'is_active'],
        );
    }
}

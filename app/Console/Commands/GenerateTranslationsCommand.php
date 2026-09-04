<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature(
    'translations:generate
    {count=100000 : Number of translations}
    {--fresh : Remove previously generated records}'
)]
#[Description('Generate translations for performance testing')]
class GenerateTranslationsCommand extends Command
{
    public function handle(): int
    {
        $count = max((int) $this->argument('count'), 1);

        DB::disableQueryLog();

        if ($this->option('fresh')) {
            $this->components->info(
                'Removing previously generated translations...'
            );

            DB::table('translation_keys')
                ->where('key', 'like', 'generated.key.%')
                ->delete();
        }

        $localeIds = DB::table('locales')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($localeIds === []) {
            $this->components->error(
                'No active locales found. Run the locale seeder first.'
            );

            return self::FAILURE;
        }

        $this->createTags();

        $numberOfKeys = (int) ceil(
            $count / count($localeIds)
        );

        $this->components->info(
            "Generating {$numberOfKeys} translation keys..."
        );

        $this->createTranslationKeys($numberOfKeys);

        $keyIds = DB::table('translation_keys')
            ->where('key', 'like', 'generated.key.%')
            ->orderBy('id')
            ->limit($numberOfKeys)
            ->pluck('id')
            ->all();

        $this->components->info(
            "Generating {$count} translations..."
        );

        $this->createTranslations(
            $keyIds,
            $localeIds,
            $count
        );

        $this->components->info('Assigning tags...');

        $this->assignTags();

        $generatedCount = DB::table('translations')
            ->join(
                'translation_keys',
                'translation_keys.id',
                '=',
                'translations.translation_key_id'
            )
            ->where(
                'translation_keys.key',
                'like',
                'generated.key.%'
            )
            ->count();

        $this->newLine();

        $this->components->info(
            "{$generatedCount} translations generated successfully."
        );

        return self::SUCCESS;
    }

    private function createTranslationKeys(int $numberOfKeys): void
    {
        $timestamp = now();
        $rows = [];

        for ($number = 1; $number <= $numberOfKeys; $number++) {
            $rows[] = [
                'key' => sprintf(
                    'generated.key.%06d',
                    $number
                ),
                'description' => "Generated translation key {$number}",
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($rows) === 1000) {
                DB::table('translation_keys')
                    ->insertOrIgnore($rows);

                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('translation_keys')
                ->insertOrIgnore($rows);
        }
    }

    private function createTranslations(
        array $keyIds,
        array $localeIds,
        int $requiredCount
    ): void {
        $timestamp = now();
        $rows = [];
        $created = 0;

        foreach ($keyIds as $keyIndex => $keyId) {
            foreach ($localeIds as $localeId) {
                if ($created >= $requiredCount) {
                    break 2;
                }

                $number = $created + 1;

                $rows[] = [
                    'translation_key_id' => $keyId,
                    'locale_id' => $localeId,
                    'content' => "Generated translation {$number} "
                        ."for key {$keyIndex} and locale {$localeId}",
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                $created++;

                if (count($rows) === 1000) {
                    DB::table('translations')
                        ->insertOrIgnore($rows);

                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('translations')
                ->insertOrIgnore($rows);
        }
    }

    private function createTags(): void
    {
        $timestamp = now();

        $tags = collect([
            'web',
            'mobile',
            'dashboard',
            'authentication',
            'marketing',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all();

        DB::table('tags')->insertOrIgnore($tags);
    }

    private function assignTags(): void
    {
        $tagIds = DB::table('tags')
            ->whereIn('name', [
                'web',
                'mobile',
                'dashboard',
                'authentication',
                'marketing',
            ])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($tagIds === []) {
            return;
        }

        $tagCount = count($tagIds);
        $pivotRows = [];

        $translations = DB::table('translations')
            ->join(
                'translation_keys',
                'translation_keys.id',
                '=',
                'translations.translation_key_id'
            )
            ->where(
                'translation_keys.key',
                'like',
                'generated.key.%'
            )
            ->select('translations.id')
            ->orderBy('translations.id')
            ->cursor();

        foreach ($translations as $translation) {
            $firstTagIndex = $translation->id % $tagCount;
            $secondTagIndex = ($translation->id + 1) % $tagCount;

            $pivotRows[] = [
                'translation_id' => $translation->id,
                'tag_id' => $tagIds[$firstTagIndex],
            ];

            $pivotRows[] = [
                'translation_id' => $translation->id,
                'tag_id' => $tagIds[$secondTagIndex],
            ];

            if (count($pivotRows) >= 1000) {
                DB::table('tag_translation')
                    ->insertOrIgnore($pivotRows);

                $pivotRows = [];
            }
        }

        if ($pivotRows !== []) {
            DB::table('tag_translation')
                ->insertOrIgnore($pivotRows);
        }
    }
}

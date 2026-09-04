<?php

namespace App\Services;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\TranslationKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TranslationService
{
    /**
     * @throws \Throwable
     */
    public function create(array $data): Translation
    {
        return DB::transaction(function () use ($data): Translation {
            $locale = Locale::query()
                ->where('code', $data['locale'])
                ->where('is_active', true)
                ->firstOrFail();

            $translationKey = TranslationKey::query()->firstOrCreate(
                ['key' => $data['key']],
                ['description' => $data['description'] ?? null]
            );

            // Friendly pre-check: catches the common case cheaply and with
            // a normal query, before anyone has started writing.
            $alreadyExists = Translation::query()
                ->where('translation_key_id', $translationKey->id)
                ->where('locale_id', $locale->id)
                ->exists();

            if ($alreadyExists) {
                throw $this->duplicateTranslationException();
            }

            if (
                array_key_exists('description', $data)
                && $translationKey->description !== $data['description']
            ) {
                $translationKey->update([
                    'description' => $data['description'],
                ]);
            }

            // Race-condition fallback: two concurrent requests can both
            // pass the exists() check above before either has inserted.
            // The `translations_key_locale_unique` database constraint is
            // the real guarantee against duplicates; if it rejects this
            // insert, convert that into the same clean validation
            // response rather than letting a raw database exception
            // surface. Any other query failure (e.g. a connection error,
            // or a violation of some other constraint) is intentionally
            // left to propagate untouched.
            try {
                $translation = Translation::query()->create([
                    'translation_key_id' => $translationKey->id,
                    'locale_id' => $locale->id,
                    'content' => $data['content'],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateTranslationException();
            }

            $this->syncTags($translation, $data['tags'] ?? []);

            return $translation->load([
                'translationKey',
                'locale',
                'tags',
            ]);
        });
    }

    public function update(
        Translation $translation,
        array $data
    ): Translation {
        return DB::transaction(function () use (
            $translation,
            $data
        ): Translation {
            if (array_key_exists('content', $data)) {
                $translation->update([
                    'content' => $data['content'],
                ]);
            }

            if (array_key_exists('description', $data)) {
                $translation->translationKey()->update([
                    'description' => $data['description'],
                ]);
            }

            if (array_key_exists('tags', $data)) {
                $this->syncTags($translation, $data['tags']);
            }

            return $translation->load([
                'translationKey',
                'locale',
                'tags',
            ]);
        });
    }

    private function duplicateTranslationException(): ValidationException
    {
        return ValidationException::withMessages([
            'key' => [
                'A translation for this key and locale already exists.',
            ],
        ]);
    }

    private function syncTags(
        Translation $translation,
        array $tagNames
    ): void {
        $tagIds = collect($tagNames)
            ->map(function (string $name): int {
                return Tag::query()
                    ->firstOrCreate(['name' => $name])
                    ->id;
            })
            ->all();

        $translation->tags()->sync($tagIds);
    }
}

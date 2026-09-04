<?php

namespace Tests\Feature\Translation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class DeleteTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_user_can_delete_a_translation(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation();

        $response = $this->deleteJson("/api/translations/{$translation->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('translations', ['id' => $translation->id]);
    }

    public function test_guest_cannot_delete_a_translation(): void
    {
        $translation = $this->createTranslation();

        $this->deleteJson("/api/translations/{$translation->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('translations', ['id' => $translation->id]);
    }

    public function test_deleting_a_translation_removes_its_pivot_relationships(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(tags: ['web', 'mobile']);

        $this->assertDatabaseCount('tag_translation', 2);

        $this->deleteJson("/api/translations/{$translation->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('tag_translation', 0);

        // Tags themselves are not deleted, only the pivot rows.
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_deleting_a_translation_does_not_delete_a_shared_translation_key_used_by_another_locale(): void
    {
        $this->actingAsUser();
        $key = $this->createTranslationKey(key: 'shared.key');
        $en = $this->activeLocale('en', 'English');
        $fr = $this->activeLocale('fr', 'French');

        $enTranslation = $this->createTranslation(
            translationKey: $key,
            locale: $en,
            content: 'Hello'
        );
        $frTranslation = $this->createTranslation(
            translationKey: $key,
            locale: $fr,
            content: 'Bonjour'
        );

        $this->deleteJson("/api/translations/{$enTranslation->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('translation_keys', ['id' => $key->id]);
        $this->assertDatabaseHas('translations', [
            'id' => $frTranslation->id,
            'content' => 'Bonjour',
        ]);
        $this->assertDatabaseMissing('translations', ['id' => $enTranslation->id]);
    }

    public function test_unknown_translation_returns_a_clean_404_on_delete(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/translations/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}

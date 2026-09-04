<?php

namespace Tests\Feature\Translation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class UpdateTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_user_can_update_content(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(content: 'Old content');

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'content' => 'New content',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.content', 'New content');

        $this->assertDatabaseHas('translations', [
            'id' => $translation->id,
            'content' => 'New content',
        ]);
    }

    public function test_user_can_update_description(): void
    {
        $this->actingAsUser();
        $key = $this->createTranslationKey(description: 'Old description');
        $translation = $this->createTranslation(translationKey: $key);

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'description' => 'New description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.description', 'New description');

        $this->assertDatabaseHas('translation_keys', [
            'id' => $key->id,
            'description' => 'New description',
        ]);
    }

    public function test_user_can_replace_tags(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(tags: ['web', 'mobile']);

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'tags' => ['desktop'],
        ]);

        $response->assertOk();

        $this->assertSame(['desktop'], $response->json('data.tags'));
        $this->assertDatabaseCount('tag_translation', 1);
    }

    public function test_partial_update_using_patch_works(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(content: 'Original');

        $response = $this->patchJson("/api/translations/{$translation->id}", [
            'content' => 'Patched content',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.content', 'Patched content');
    }

    public function test_update_without_fields_leaves_existing_data_untouched(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(content: 'Untouched', tags: ['web']);

        $response = $this->patchJson("/api/translations/{$translation->id}", []);

        $response->assertOk()
            ->assertJsonPath('data.content', 'Untouched');

        $this->assertSame(['web'], $response->json('data.tags'));
    }

    public function test_guest_cannot_update_a_translation(): void
    {
        $translation = $this->createTranslation();

        $this->putJson("/api/translations/{$translation->id}", [
            'content' => 'Hacked',
        ])->assertUnauthorized();
    }

    public function test_empty_content_is_rejected_on_update(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation();

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'content' => '',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_invalid_tag_format_is_rejected_on_update(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation();

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'tags' => ['Invalid Tag!'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tags.0']);
    }

    public function test_a_500_character_description_is_accepted_on_update_without_truncation(): void
    {
        $this->actingAsUser();
        $key = $this->createTranslationKey(description: 'Old description');
        $translation = $this->createTranslation(translationKey: $key);

        $description = str_repeat('b', 500);

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'description' => $description,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.description', $description);

        $this->assertDatabaseHas('translation_keys', [
            'id' => $key->id,
            'description' => $description,
        ]);
    }

    public function test_a_501_character_description_is_rejected_on_update(): void
    {
        $this->actingAsUser();
        $key = $this->createTranslationKey(description: 'Old description');
        $translation = $this->createTranslation(translationKey: $key);

        $response = $this->putJson("/api/translations/{$translation->id}", [
            'description' => str_repeat('b', 501),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);

        $this->assertDatabaseHas('translation_keys', [
            'id' => $key->id,
            'description' => 'Old description',
        ]);
    }
}

<?php

namespace Tests\Feature\Translation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTranslationApi;
use Tests\TestCase;

class ShowTranslationTest extends TestCase
{
    use InteractsWithTranslationApi;
    use RefreshDatabase;

    public function test_user_can_view_a_translation(): void
    {
        $this->actingAsUser();
        $translation = $this->createTranslation(
            content: 'Hello world',
            tags: ['web']
        );

        $response = $this->getJson("/api/translations/{$translation->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $translation->id)
            ->assertJsonPath('data.content', 'Hello world')
            ->assertJsonPath('data.key', $translation->translationKey->key);
    }

    public function test_guest_cannot_view_a_translation(): void
    {
        $translation = $this->createTranslation();

        $this->getJson("/api/translations/{$translation->id}")
            ->assertUnauthorized();
    }

    public function test_unknown_translation_returns_a_clean_404_json_response(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/translations/999999');

        $response->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->assertIsString($response->json('message'));
    }
}

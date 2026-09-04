<?php

namespace Tests\Unit\Models;

use Database\Factories\TranslationFactory;
use Database\Factories\TranslationKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_translation_key_has_many_translations(): void
    {
        $translationKey = TranslationKeyFactory::new()->create();
        TranslationFactory::new()->count(2)->forKey($translationKey)->create();

        $this->assertCount(2, $translationKey->translations);
    }
}

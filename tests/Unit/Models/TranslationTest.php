<?php

namespace Tests\Unit\Models;

use App\Models\Locale;
use App\Models\Tag;
use App\Models\TranslationKey;
use Database\Factories\LocaleFactory;
use Database\Factories\TagFactory;
use Database\Factories\TranslationFactory;
use Database\Factories\TranslationKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_translation_belongs_to_a_locale(): void
    {
        $locale = LocaleFactory::new()->create();
        $translation = TranslationFactory::new()->forLocale($locale)->create();

        $this->assertTrue($translation->locale->is($locale));
        $this->assertInstanceOf(Locale::class, $translation->locale);
    }

    public function test_translation_belongs_to_a_translation_key(): void
    {
        $translationKey = TranslationKeyFactory::new()->create();
        $translation = TranslationFactory::new()->forKey($translationKey)->create();

        $this->assertTrue($translation->translationKey->is($translationKey));
        $this->assertInstanceOf(TranslationKey::class, $translation->translationKey);
    }

    public function test_translation_belongs_to_many_tags(): void
    {
        $translation = TranslationFactory::new()->create();
        $tag = TagFactory::new()->create();

        $translation->tags()->attach($tag);

        $this->assertTrue($translation->tags->contains($tag));
        $this->assertInstanceOf(Tag::class, $translation->tags->first());
    }
}

<?php

namespace Tests\Unit\Models;

use Database\Factories\TagFactory;
use Database\Factories\TranslationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_belongs_to_many_translations(): void
    {
        $tag = TagFactory::new()->create();
        $translations = TranslationFactory::new()->count(2)->create();

        $tag->translations()->attach($translations->pluck('id'));

        $this->assertCount(2, $tag->translations);
    }
}

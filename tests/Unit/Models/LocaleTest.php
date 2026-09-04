<?php

namespace Tests\Unit\Models;

use App\Models\Locale;
use Database\Factories\LocaleFactory;
use Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_has_many_translations(): void
    {
        $locale = LocaleFactory::new()->create();
        TranslationFactory::new()->count(3)->forLocale($locale)->create();

        $this->assertCount(3, $locale->translations);
        $this->assertInstanceOf(
            HasMany::class,
            $locale->translations()
        );
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $locale = Locale::query()->create([
            'name' => 'English',
            'code' => 'en-test',
            'is_active' => 1,
        ]);

        $this->assertIsBool($locale->is_active);
        $this->assertTrue($locale->is_active);

        $locale->refresh();
        $this->assertIsBool($locale->is_active);
    }
}

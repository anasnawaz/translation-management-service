<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('translation_key_id')->constrained()->cascadeOnDelete();

            $table->foreignId('locale_id')->constrained()->cascadeOnDelete();

            $table->longText('content');
            $table->timestamps();

            $table->unique(['translation_key_id', 'locale_id'], 'translations_key_locale_unique');

            $table->index(['locale_id', 'translation_key_id'], 'translations_locale_key_index');

            $table->fullText('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};

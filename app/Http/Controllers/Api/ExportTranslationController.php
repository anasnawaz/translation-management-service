<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\ExportTranslationRequest;
use App\Models\Locale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTranslationController extends Controller
{
    public function __invoke(
        ExportTranslationRequest $request,
        string $locale
    ): StreamedResponse {
        $localeModel = Locale::query()
            ->select(['id', 'code'])
            ->where('code', strtolower($locale))
            ->where('is_active', true)
            ->firstOrFail();

        $tags = $request->validated('tags', []);

        return response()->stream(
            function () use ($localeModel, $tags): void {
                echo '{';

                $firstRecord = true;

                $translations = DB::table('translations')
                    ->join(
                        'translation_keys',
                        'translation_keys.id',
                        '=',
                        'translations.translation_key_id'
                    )
                    ->where(
                        'translations.locale_id',
                        $localeModel->id
                    )
                    ->when(
                        $tags !== [],
                        function (Builder $query) use ($tags): void {
                            $query->whereExists(
                                function (Builder $tagQuery) use ($tags): void {
                                    $tagQuery
                                        ->selectRaw('1')
                                        ->from('tag_translation')
                                        ->join(
                                            'tags',
                                            'tags.id',
                                            '=',
                                            'tag_translation.tag_id'
                                        )
                                        ->whereColumn(
                                            'tag_translation.translation_id',
                                            'translations.id'
                                        )
                                        ->whereIn('tags.name', $tags);
                                }
                            );
                        }
                    )
                    ->select([
                        'translations.id',
                        'translation_keys.key',
                        'translations.content',
                    ])
                    ->orderBy('translations.id')
                    ->cursor();

                foreach ($translations as $translation) {
                    if (! $firstRecord) {
                        echo ',';
                    }

                    echo json_encode(
                        $translation->key,
                        JSON_THROW_ON_ERROR
                    );

                    echo ':';

                    echo json_encode(
                        $translation->content,
                        JSON_THROW_ON_ERROR
                    );

                    $firstRecord = false;
                }

                echo '}';
            },
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-cache, private, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}

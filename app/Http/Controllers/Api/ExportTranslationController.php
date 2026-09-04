<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\ExportTranslationRequest;
use App\Models\Locale;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTranslationController extends Controller
{
    public function __invoke(
        ExportTranslationRequest $request,
        string $locale
    ): JsonResponse|StreamedResponse {
        $localeModel = Locale::query()
            ->select(['id', 'code'])
            ->where('code', strtolower($locale))
            ->where('is_active', true)
            ->first();

        if (! $localeModel) {
            return response()->json([
                'message' => 'Requested resource was not found.',
                'errors' => [
                    'locale' => [
                        'The requested locale does not exist or is inactive.',
                    ],
                ],
            ], 404);
        }

        $tags = $request->validated('tags', []);

        $query = DB::table('translations')
            ->join(
                'translation_keys',
                'translation_keys.id',
                '=',
                'translations.translation_key_id'
            )
            ->select([
                'translation_keys.key as k',
                'translations.content as c',
            ])
            ->where('translations.locale_id', $localeModel->id)
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
            );

        return response()->stream(
            function () use ($query): void {
                echo '{';

                $first = true;
                $rowCount = 0;

                foreach ($query->cursor() as $row) {
                    if (! $first) {
                        echo ',';
                    }

                    echo json_encode((string) $row->k, JSON_UNESCAPED_UNICODE),
                    ':',
                    json_encode((string) $row->c, JSON_UNESCAPED_UNICODE);

                    $first = false;

                    if (++$rowCount % 500 === 0) {
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }

                        flush();
                    }
                }

                echo '}';

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            },
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Accel-Buffering' => 'no',
            ]
        );
    }
}

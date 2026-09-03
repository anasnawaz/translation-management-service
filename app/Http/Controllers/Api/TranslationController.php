<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\StoreTranslationRequest;
use App\Http\Requests\Translation\UpdateTranslationRequest;
use App\Http\Resources\TranslationResource;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function __construct(
        private readonly TranslationService $translationService
    ) {}

    public function store(
        StoreTranslationRequest $request
    ): JsonResponse {
        $translation = $this->translationService->create(
            $request->validated()
        );

        return (new TranslationResource($translation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Translation $translation
    ): TranslationResource {
        $translation->load([
            'translationKey',
            'locale',
            'tags',
        ]);

        return new TranslationResource($translation);
    }

    public function update(
        UpdateTranslationRequest $request,
        Translation $translation
    ): TranslationResource {
        $translation = $this->translationService->update(
            $translation,
            $request->validated()
        );

        return new TranslationResource($translation);
    }

    public function destroy(
        Translation $translation
    ): JsonResponse {
        $translation->delete();

        return response()->json(null, 204);
    }
}

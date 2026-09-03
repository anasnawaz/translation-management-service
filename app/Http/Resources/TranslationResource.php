<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TranslationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->translationKey->key,
            'description' => $this->translationKey->description,
            'locale' => [
                'code' => $this->locale->code,
                'name' => $this->locale->name,
            ],
            'content' => $this->content,
            'tags' => $this->tags->pluck('name')->values(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

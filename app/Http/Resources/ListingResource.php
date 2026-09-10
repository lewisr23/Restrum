<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'location' => $this->location,
            'category' => $this->category,
            'condition' => $this->condition,
            'status' => $this->status,
            'seller' => new UserSummaryResource($this->whenLoaded('seller')),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'media_type' => $m->media_type,
                'url' => $m->url,
                'label' => $m->label,
            ])),
            // True only when the controller eager-loaded savedBy scoped to the
            // current viewer (see ListingController::scopeSavedForViewer) -
            // never computed here per-row, which would N+1 on an index page.
            'saved_by_viewer' => $this->when(
                $this->relationLoaded('savedBy'),
                fn () => $this->savedBy->isNotEmpty(),
            ),
            'created_at' => $this->created_at,
        ];
    }
}

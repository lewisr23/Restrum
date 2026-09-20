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
            'postage_price' => $this->postage_price,
            'collection_only' => $this->collection_only,
            // What the buyer will actually be charged. Computed here rather
            // than in the client so the figure on the card, the figure on
            // the listing and the figure Stripe charges cannot drift.
            'total_price' => $this->collection_only
                ? $this->price
                : bcadd((string) $this->price, (string) $this->postage_price, 2),
            'location' => $this->location,

            // An object rather than the single word this used to be. The
            // frontend needs the name to show, the slug to link to, and the
            // path to know where it sits in the tree, and deriving any of
            // those from the others on the client would put the taxonomy in
            // two places.
            'category' => $this->whenLoaded('category', fn () => [
                'slug' => $this->category->slug,
                'path' => $this->category->path,
                'name' => $this->category->name,
            ]),

            'brand' => $this->brand,
            'condition' => $this->condition,
            'status' => $this->status,

            // The answers to this category's filter questions, as a plain
            // name to value map. Labels are not repeated per listing: they
            // are the same for everything in a category and come back once,
            // alongside the listing, from the show endpoint.
            'attributes' => $this->whenLoaded('attributeValues', fn () => $this->attributeMap()),

            'seller' => new UserSummaryResource($this->whenLoaded('seller')),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'media_type' => $m->media_type,
                'url' => $m->url,
                'label' => $m->label,
            ])),
            // True only when the controller eager-loaded savedBy scoped to the
            // current viewer - never computed here per-row, which would N+1 on
            // an index page.
            'saved_by_viewer' => $this->when(
                $this->relationLoaded('savedBy'),
                fn () => $this->savedBy->isNotEmpty(),
            ),
            'created_at' => $this->created_at,
        ];
    }
}

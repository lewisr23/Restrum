<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Recommender\ListingRecommender;
use App\Services\Recommender\RecommenderUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The gear adviser endpoint.
 *
 * Public, because a buyer deciding what to buy has usually not signed up yet,
 * and that is exactly the moment the thing is useful. Public also means every
 * request costs real money and anyone can send one, so the route is rate
 * limited (see routes/api.php) and the history is capped here.
 */
class RecommendationController extends Controller
{
    public function __construct(private readonly ListingRecommender $recommender) {}

    /**
     * Whether to show the widget at all.
     *
     * Asked by the frontend on load. Without a key the feature does not
     * exist, and a chat button that always apologises is worse than no chat
     * button.
     */
    public function status(): JsonResponse
    {
        return response()->json(['available' => $this->recommender->isConfigured()]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:2000'],
        ]);

        if (! $this->recommender->isConfigured()) {
            return response()->json([
                'message' => 'The gear adviser is not switched on.',
            ], 503);
        }

        $history = $this->trim($data['messages']);

        // A conversation has to start and end with the person asking. Anything
        // else is a client bug or someone hand-rolling requests, and both
        // deserve a 422 rather than a bill.
        if (end($history)['role'] !== 'user') {
            return response()->json([
                'message' => 'The last message has to be a question.',
            ], 422);
        }

        try {
            $answer = $this->recommender->reply($history);
        } catch (RecommenderUnavailable $e) {
            return response()->json(['message' => 'The gear adviser is unavailable right now.'], 503);
        } catch (\Throwable $e) {
            // Nothing from the exception reaches the browser: it can carry
            // request ids, model names and prompt fragments.
            Log::error('Gear adviser failed.', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Something went wrong asking the adviser. Try again in a moment.',
            ], 502);
        }

        return response()->json([
            'reply' => $answer['reply'],
            // Loaded here rather than serialised by the recommender, so the
            // cards the widget renders are the same shape, and the same
            // freshness, as every other listing on the site.
            'listings' => ListingResource::collection($this->listingsFor($answer['listing_ids'])),
        ]);
    }

    /**
     * Keep the last few turns.
     *
     * Old turns are most of what a long chat costs, and they are the least
     * useful part of it: what someone asked ten messages ago rarely changes
     * the right answer now. The first message is not pinned, deliberately -
     * this is a shopping assistant, not a thread with a brief.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function trim(array $messages): array
    {
        $limit = (int) config('services.anthropic.max_history');
        $trimmed = array_slice($messages, -$limit);

        // A history that now opens on an assistant turn would be rejected by
        // the API, so drop it.
        while ($trimmed !== [] && $trimmed[0]['role'] !== 'user') {
            array_shift($trimmed);
        }

        return $trimmed;
    }

    /**
     * The cited listings, in the order they were cited.
     *
     * @param  array<int, int>  $ids
     */
    private function listingsFor(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        $listings = Listing::whereIn('id', $ids)
            ->with('seller', 'media', 'category')
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $listings->get($id))
            ->filter()
            ->values();
    }
}

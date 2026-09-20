<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * Order states in which the deal is done and a review is fair.
     *
     * CONFIRMED and RELEASED only. Not PAID: the gear may not have arrived
     * yet, and a review written before then is a review of nothing. Not
     * REFUNDED or DISPUTED either - those are arguments still in progress,
     * and feedback written mid-argument is a weapon rather than a record.
     */
    private const REVIEWABLE = [OrderStatus::CONFIRMED, OrderStatus::RELEASED];

    public function store(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! in_array($order->status, self::REVIEWABLE, true)) {
            throw ValidationException::withMessages([
                'order' => 'You can leave feedback once the order is complete.',
            ]);
        }

        $reviewer = $request->user();
        $isBuyer = $reviewer->id === $order->buyer_id;

        // Derived, never taken from the request: the subject is simply the
        // other side of this order.
        $subjectId = $isBuyer ? $order->seller_id : $order->buyer_id;

        if ($order->reviews()->where('reviewer_id', $reviewer->id)->exists()) {
            throw ValidationException::withMessages([
                'order' => 'You have already left feedback for this order.',
            ]);
        }

        $review = new Review($data);
        $review->order_id = $order->id;
        $review->reviewer_id = $reviewer->id;
        $review->subject_id = $subjectId;
        $review->reviewer_role = $isBuyer ? 'BUYER' : 'SELLER';
        $review->save();

        return response()->json([
            'data' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'reviewer_role' => $review->reviewer_role,
                'created_at' => $review->created_at,
            ],
        ], 201);
    }
}

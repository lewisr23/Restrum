<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Payments\EscrowService;
use Illuminate\Http\Request;

/**
 * What a buyer and a seller can see and do about a sale in progress.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly EscrowService $escrow) {}

    /**
     * The signed-in user's orders, as buyer or as seller.
     *
     * One endpoint rather than two because they are the same rows read from
     * opposite ends, and splitting them would mean two places to keep the
     * eager loading and the ordering in step.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'role' => ['nullable', 'in:BUYER,SELLER'],
        ]);

        $user = $request->user();
        $role = $data['role'] ?? null;

        $orders = Order::query()
            ->when($role === 'BUYER', fn ($q) => $q->where('buyer_id', $user->id))
            ->when($role === 'SELLER', fn ($q) => $q->where('seller_id', $user->id))
            ->when($role === null, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id)))
            // Eager loaded rather than resolved per row: an orders page is
            // exactly the shape of page that N+1s, one query per listing and
            // one per counterparty.
            ->with(['listing.media', 'buyer', 'seller'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('listing.media', 'buyer', 'seller'));
    }

    /**
     * The buyer says it arrived as described, which releases the money.
     *
     * Deliberately not automatic on delivery tracking. Whether an instrument
     * is as described is a judgement no courier can make, and it is the only
     * judgement buyer protection is actually about.
     */
    public function confirm(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $confirmed = $this->escrow->confirmReceipt($order, $request->user());

        return new OrderResource($confirmed->load('listing.media', 'buyer', 'seller'));
    }
}

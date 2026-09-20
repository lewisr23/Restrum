<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A sale, and the money held against it.
 *
 * Nothing here is fillable. Every field is either a server-side fact (who is
 * selling, what the price was) or a payment state that only Stripe's webhooks
 * are allowed to move, so there is no legitimate path from request input to
 * any column on this table.
 */
#[Fillable([])]
class Order extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount' => 'decimal:2',
            'postage' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'reserved_until' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Orders where the platform is holding the buyer's money.
     *
     * This is the set that makes a listing genuinely unavailable. A PENDING
     * order is excluded deliberately: an abandoned checkout has taken nothing
     * and must not keep an instrument off the market.
     */
    public function scopeHoldingFunds(Builder $query): Builder
    {
        return $query->whereIn('status', array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $s) => $s->holdsFunds(),
        ));
    }

    /**
     * Orders that make a listing unavailable to a new buyer.
     *
     * Two different things block a sale and only one of them involves money.
     * An order that holds funds is a sale in progress. An order that merely
     * holds a live reservation has taken nothing, but it has sent a buyer to
     * Stripe with a card in hand, and letting a second buyer through behind
     * them means one of the two gets refunded a purchase they believed they
     * had made.
     *
     * A lapsed reservation blocks nothing, which is what keeps an abandoned
     * checkout from stranding an instrument.
     */
    public function scopeBlockingListing(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->holdingFunds()
                ->orWhere(fn (Builder $reserved) => $reserved
                    ->where('status', OrderStatus::PENDING)
                    ->where('reserved_until', '>', now()));
        });
    }

    /** Whether this order still holds its listing off the market. */
    public function reservationIsLive(): bool
    {
        return $this->status === OrderStatus::PENDING
            && $this->reserved_until !== null
            && $this->reserved_until->isFuture();
    }

    /**
     * Groups the buyer's charge and the seller's eventual transfer together
     * in Stripe's own reporting.
     */
    public function transferGroup(): string
    {
        return "order_{$this->id}";
    }

    /** The sale price in pence, which is the only unit Stripe charges in. */
    public function amountInPence(): int
    {
        return $this->toPence((string) $this->amount);
    }

    /** What actually gets transferred to the seller, in pence. */
    public function sellerProceedsInPence(): int
    {
        return $this->toPence($this->sellerProceeds());
    }

    /**
     * Decimal pounds to integer pence.
     *
     * bcmul rather than multiplying by 100, because these are money columns
     * and floating point is how a 19.99 becomes a 1998. The scale of 0
     * truncates nothing real: the column is decimal(10,2), so there is never
     * a third decimal place to lose.
     */
    private function toPence(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    /**
     * What the seller is owed: the sale price less the platform's cut.
     *
     * Stripe's own processing fee comes out of the platform's side rather than
     * the seller's, so a seller is paid exactly what this says.
     */
    public function sellerProceeds(): string
    {
        return bcsub((string) $this->amount, (string) $this->platform_fee, 2);
    }

    /**
     * Move the order to a new state, refusing transitions the lifecycle does
     * not allow.
     *
     * Callers must already hold a lock on this row. Webhooks arrive
     * concurrently and out of order, so checking the current state and then
     * writing the next one is the same read-then-write race we fixed on
     * listing sales, and it is more expensive here because the thing being
     * raced is money.
     */
    public function transitionTo(OrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new LogicException(
                "Cannot move order {$this->id} from {$this->status->value} to {$next->value}."
            );
        }

        $this->status = $next;

        // Each state stamps its own timestamp, and only if not already set:
        // a redelivered webhook that legitimately re-enters a state should
        // not rewrite when it first happened.
        $stamp = match ($next) {
            OrderStatus::PAID => 'paid_at',
            OrderStatus::CONFIRMED => 'confirmed_at',
            OrderStatus::RELEASED => 'released_at',
            OrderStatus::REFUNDED => 'refunded_at',
            default => null,
        };

        if ($stamp !== null && $this->{$stamp} === null) {
            $this->{$stamp} = now();
        }

        $this->save();
    }
}

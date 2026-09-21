<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fillable, but only ever set by MessageController from values it has
// already validated/decided itself, never straight from raw request input
// (see the Conversation model for why "no #[Fillable] at all" is NOT the
// safe default it looks like - it blocks legitimate server-side creation
// too, since Eloquent's default is an empty allow-list, not unguarded).
#[Fillable(['sender_id', 'content', 'message_type', 'offer_amount', 'offer_status', 'safety_flags'])]
class Message extends Model
{
    // Only created_at exists on this table - messages are immutable once sent.
    // UPDATED_AT = null is how Eloquent is told to manage a single timestamp
    // rather than the usual pair. The column has a DB-level useCurrent()
    // default too, but letting Eloquent write it is what keeps it on the same
    // clock as every other table: MySQL's NOW() is the server's local time,
    // while Laravel reads and serializes all stored timestamps as UTC. With
    // the DB filling this column, a message sent at 20:30 BST was stored as
    // 20:30 and then sent to the browser labelled UTC, so it rendered as
    // 21:30 - an hour in the future.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'read_by_recipient' => 'boolean',
            'safety_flags' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No #[Fillable] - messages are written by MessagingService, which decides
// message_type/offer_amount/offer_status itself; never mass-assigned from
// raw request input.
class Message extends Model
{
    // Only created_at exists on this table - messages are immutable once sent.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'read_by_recipient' => 'boolean',
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

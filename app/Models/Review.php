<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// rating and comment are the only things a request supplies. Who is being
// reviewed, on which order, and from which side are all derived server-side
// from the order itself, because letting a request name its own subject is
// how you get people reviewing strangers.
#[Fillable(['rating', 'comment'])]
class Review extends Model
{
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }
}

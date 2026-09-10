<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['entry_type', 'description', 'event_date'])]
class PassportEntry extends Model
{
    // Only created_at exists on this table. Entries ARE editable in place
    // (PUT updates the row) - there is deliberately no updated_at to show
    // for it, since the gear-history timeline reads by created_at and a
    // typo fix shouldn't reorder or re-date an entry in the timeline.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function passport(): BelongsTo
    {
        return $this->belongsTo(InstrumentPassport::class, 'passport_id');
    }
}

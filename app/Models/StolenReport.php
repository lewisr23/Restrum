<?php

namespace App\Models;

use App\Services\Safety\SerialNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Who filed it, its status and the normalised serial are set by the server,
// never from request input.
#[Fillable(['serial_number', 'brand', 'description', 'stolen_on', 'location', 'police_reference'])]
class StolenReport extends Model
{
    protected function casts(): array
    {
        return [
            'stolen_on' => 'date',
            'recovered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StolenReport $report) {
            $report->serial_normalized = SerialNumber::normalize($report->serial_number);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}

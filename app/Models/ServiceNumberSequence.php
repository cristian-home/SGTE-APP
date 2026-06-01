<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable per-year counter that backs the user-facing service consecutive
 * (`SRV-NNNN-YYYY`). Unlike the invoice number (a COUNT()+1 preview computed
 * at save time), a service number is *reserved* when the create form opens —
 * before the Service row exists — so two concurrent openings cannot derive
 * the same MAX+1. The row is locked (`lockForUpdate`) inside the reservation
 * transaction; see Service::reserveNextNumber().
 */
class ServiceNumberSequence extends Model
{
    protected $fillable = [
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}

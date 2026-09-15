<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointAccount extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'balance',
        'total_earned',
        'total_redeemed',
    ];

    protected $casts = [
        'balance' => 'integer',
        'total_earned' => 'integer',
        'total_redeemed' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }
}

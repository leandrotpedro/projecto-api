<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CashSession extends Model
{
    protected $fillable = [
        'cash_register_id',
        'opened_by',
        'closed_by',
        'opening_amount',
        'expected_amount',
        'counted_amount',
        'discrepancy',
        'status',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'discrepancy' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    /**
     * @return HasMany<CashSessionMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashSessionMovement::class);
    }

    /**
     * @return array{cash_sales: float, cash_refunds: float, cash_expenses: float, manual_in: float, manual_out: float, expected_amount: float}
     */
    public function summary(): array
    {
        $cashSales = (float) DB::table('sales')
            ->where('cash_session_id', $this->id)
            ->where('payment_method', 'dinheiro')
            ->whereNotNull('paid_at')
            ->whereIn('payment_status', ['paid', 'refund_pending'])
            ->sum('total');

        $cashRefunds = (float) DB::table('sales')
            ->where('cash_session_id', $this->id)
            ->where('payment_method', 'dinheiro')
            ->where('payment_status', 'refunded')
            ->sum('total');

        $cashRefunds += (float) DB::table('sale_returns')
            ->where('cash_session_id', $this->id)
            ->where('refund_method', 'dinheiro')
            ->sum('total');

        $cashExpenses = (float) DB::table('expenses')
            ->where('cash_session_id', $this->id)
            ->where('payment_method', 'dinheiro')
            ->sum('amount');

        $manualIn = (float) $this->movements()->where('direction', 'in')->sum('amount');
        $manualOut = (float) $this->movements()->where('direction', 'out')->sum('amount');
        $expectedAmount = round(
            (float) $this->opening_amount + $cashSales - $cashRefunds - $cashExpenses + $manualIn - $manualOut,
            2
        );

        return [
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'cash_expenses' => $cashExpenses,
            'manual_in' => $manualIn,
            'manual_out' => $manualOut,
            'expected_amount' => $expectedAmount,
        ];
    }
}

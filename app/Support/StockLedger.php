<?php

namespace App\Support;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

class StockLedger
{
    public static function record(
        int $productId,
        ?int $userId,
        string $type,
        string $direction,
        int $quantity,
        int $stockBefore,
        int $stockAfter,
        string $reason,
        ?Model $reference = null,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $productId,
            'recorded_by' => $userId,
            'type' => $type,
            'direction' => $direction,
            'quantity' => $quantity,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'reason' => $reason,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'stock')) {
            $productsWithoutStockRows = DB::table('products')
                ->leftJoin('stocks', 'products.id', '=', 'stocks.product_id')
                ->whereNull('stocks.id')
                ->select('products.id', 'products.stock', 'products.created_at', 'products.updated_at')
                ->get();

            foreach ($productsWithoutStockRows as $product) {
                DB::table('stocks')->insert([
                    'product_id' => $product->id,
                    'quantity' => $product->stock,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ]);
            }

            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('stock');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('stock')->default(0);
            });
        }

        foreach (DB::table('stocks')->get(['product_id', 'quantity']) as $stock) {
            DB::table('products')
                ->where('id', $stock->product_id)
                ->update(['stock' => $stock->quantity]);
        }
    }
};

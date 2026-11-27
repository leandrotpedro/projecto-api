<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->where('customer_type', 'individual')
            ->whereNotNull('customer_nif')
            ->update(['customer_nif' => null]);

        DB::table('sales')
            ->where('customer_type', 'individual')
            ->whereNotNull('customer_nif')
            ->update(['customer_nif' => null]);
    }

    public function down(): void
    {
        DB::table('clients')
            ->where('customer_type', 'individual')
            ->whereNull('customer_nif')
            ->update(['customer_nif' => '99999990']);

        DB::table('sales')
            ->where('customer_type', 'individual')
            ->whereNull('customer_nif')
            ->update(['customer_nif' => '99999990']);
    }
};

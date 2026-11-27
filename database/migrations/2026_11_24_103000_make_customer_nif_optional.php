<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('customer_nif', 30)->nullable()->default(null)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('customer_nif', 30)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('clients')->whereNull('customer_nif')->update(['customer_nif' => '99999990']);
        DB::table('sales')->whereNull('customer_nif')->update(['customer_nif' => '99999990']);

        Schema::table('clients', function (Blueprint $table) {
            $table->string('customer_nif', 30)->default('99999990')->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('customer_nif', 30)->default('99999990')->change();
        });
    }
};

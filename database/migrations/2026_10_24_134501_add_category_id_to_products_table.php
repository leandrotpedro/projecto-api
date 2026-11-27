<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The category_id column is created by the 2026_10_19 migration.
    }

    public function down(): void
    {
        // The 2026_10_19 migration owns the category_id column.
    }
};
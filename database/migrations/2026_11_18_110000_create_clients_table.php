<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        DB::table('users')
            ->where('role', 'cliente')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();
                DB::table('clients')->insert(
                    $users->map(fn (object $user): array => [
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};

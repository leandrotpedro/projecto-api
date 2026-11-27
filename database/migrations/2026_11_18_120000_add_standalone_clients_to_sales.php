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
            $table->string('name')->default('');
            $table->string('phone', 30)->nullable()->index();
            $table->foreignId('user_id')->nullable()->change();
        });

        DB::table('clients')
            ->join('users', 'users.id', '=', 'clients.user_id')
            ->select('clients.id', 'users.name', 'users.phone')
            ->orderBy('clients.id')
            ->chunk(500, function ($clients): void {
                foreach ($clients as $client) {
                    DB::table('clients')
                        ->where('id', $client->id)
                        ->update([
                            'name' => $client->name,
                            'phone' => $client->phone,
                        ]);
                }
            });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('sales')
            ->whereNotNull('user_id')
            ->update([
                'client_id' => DB::raw('(SELECT clients.id FROM clients WHERE clients.user_id = sales.user_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        DB::table('clients')->whereNull('user_id')->delete();

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn(['name', 'phone']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};

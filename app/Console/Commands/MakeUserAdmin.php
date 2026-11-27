<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

class MakeUserAdmin extends Command
{
    protected $signature = 'users:make-admin {email : E-mail da conta a promover}';

    protected $description = 'Promove uma conta existente ao perfil de administrador';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error('Não foi encontrada uma conta ativa com esse e-mail.');

            return self::FAILURE;
        }

        $previousRole = $user->role;
        if ($previousRole === 'administrador') {
            $this->info("A conta {$user->email} já é administradora.");

            return self::SUCCESS;
        }

        $user->role = 'administrador';
        $user->save();
        ActivityLog::create([
            'action' => 'user.role_changed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'changes' => ['role' => ['from' => $previousRole, 'to' => 'administrador']],
        ]);

        $this->info("A conta {$user->email} agora é administradora.");

        return self::SUCCESS;
    }
}

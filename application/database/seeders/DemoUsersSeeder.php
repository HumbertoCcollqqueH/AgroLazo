<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Los usuarios demo solo se crean en local o testing.');
        }

        if (User::query()->where('email', '!=', 'admin@agroconecta.test')
            ->whereHas('roles', fn ($query) => $query->where('code', 'admin'))->exists()) {
            return;
        }

        $password = config('agroconecta.demo_password');

        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Configura DEMO_PASSWORD con entre 12 y 72 caracteres.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function () use ($password): void {
            foreach ([
                ['admin@agroconecta.test', 'Administración demo', 'admin'],
                ['coordinador@agroconecta.test', 'Coordinación demo', 'coordinator'],
                ['comprador@agroconecta.test', 'Comprador demo', 'buyer'],
            ] as [$email, $name, $role]) {
                $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);

                if (! $user->wasRecentlyCreated) {
                    continue;
                }

                $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

                if ($role === 'buyer') {
                    $user->buyer()->create(['type' => 'restaurant']);
                }

                AuditLog::record(null, 'user.demo_created', $user, ['role' => $role]);
            }
        });
        $this->call(CommerceDemoSeeder::class);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class BootstrapAdministrator extends Command
{
    protected $signature = 'agroconecta:bootstrap-admin {--email=} {--name=} {--password-file= : Archivo privado con la contraseña inicial}';

    protected $description = 'Crea el primer administrador únicamente si todavía no existe uno.';

    public function handle(): int
    {
        $path = $this->option('password-file');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 512) {
            $this->error('Indica --email, --name y un --password-file privado y legible (máximo 512 bytes).');

            return self::FAILURE;
        }
        $input = ['email' => $this->option('email'), 'name' => $this->option('name'), 'password' => rtrim(file_get_contents($path), "\r\n")];
        $validator = Validator::make($input, [
            'email' => ['required', 'email:rfc', 'max:254'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'password' => ['required', 'string', 'max:128', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            $this->error('Revisa el correo, nombre y contraseña: mínimo 16 caracteres, mayúsculas, minúsculas, números y símbolos.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($input): void {
                $role = Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
                if ($role->users()->exists() || User::query()->where('email', mb_strtolower(trim($input['email'])))->exists()) {
                    throw new RuntimeException('Ya existe un administrador o una cuenta con ese correo.');
                }
                $user = User::query()->create($input);
                $user->forceFill(['active' => true, 'email_verified_at' => now()])->save();
                $user->roles()->attach($role->id);
                AuditLog::record($user, 'administrator.bootstrapped', $user, ['role' => 'admin'], 'console_bootstrap');
            });
            $this->info('Primer administrador creado. La contraseña no se imprime ni se modifica al repetir.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se creó el administrador. Comprueba migraciones/roles y que todavía no exista un administrador ni ese correo.');

            return self::FAILURE;
        }
    }
}

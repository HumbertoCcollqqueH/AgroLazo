<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProducerIdentity
{
    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if (preg_match('/\A9[0-9]{8}\z/', $phone) === 1) {
            return '+51'.$phone;
        }
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $phone) !== 1 || (str_starts_with($phone, '+51') && preg_match('/\A\+519[0-9]{8}\z/', $phone) !== 1)) {
            throw ValidationException::withMessages(['phone' => 'Escribe un celular peruano de 9 dígitos o un teléfono internacional en formato +código y número, sin espacios.']);
        }

        return $phone;
    }

    public function lookup(string $kind, string $value): string
    {
        if ($kind === 'dni') {
            if (preg_match('/\A[0-9]{8}\z/', $value) !== 1) {
                throw ValidationException::withMessages(['dni' => 'El DNI debe contener exactamente 8 dígitos.']);
            }
        } elseif ($kind === 'phone') {
            $value = $this->normalizePhone($value);
        } else {
            throw new \InvalidArgumentException('El índice privado solo admite DNI o teléfono.');
        }

        $this->assertKeyMatchesDatabase();

        return hash_hmac('sha256', 'agroconecta:producer:'.$kind.':v1:'.$value, $this->key());
    }

    public function assertKeyMatchesDatabase(): void
    {
        $fingerprint = hash_hmac('sha256', 'agroconecta:producer-identity:key:v1', $this->key());

        DB::transaction(function () use ($fingerprint): void {
            DB::statement('LOCK TABLE producer_identity_keys IN SHARE ROW EXCLUSIVE MODE');
            $stored = DB::table('producer_identity_keys')->where('id', 1)->first();
            if ($stored === null) {
                if (DB::table('producers')->exists()) {
                    throw ValidationException::withMessages(['producer' => 'Falta la huella de la clave de identidad para los productores existentes. Restaura la configuración original antes de continuar.']);
                }
                DB::table('producer_identity_keys')->insert(['id' => 1, 'fingerprint' => $fingerprint, 'created_at' => now()]);

                return;
            }
            if (! hash_equals($stored->fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['producer' => 'La clave de identidad no coincide con la base de datos. Restaura PRODUCER_LOOKUP_KEY; no generes otra clave para estos registros.']);
            }
        });
    }

    private function key(): string
    {
        $configured = config('producers.lookup_key');
        if (! is_string($configured) || preg_match('/\A[0-9a-fA-F]{64}\z/', $configured) !== 1) {
            throw ValidationException::withMessages(['producer' => 'Configura PRODUCER_LOOKUP_KEY con una clave hexadecimal de 64 caracteres antes de operar productores.']);
        }

        return hex2bin($configured);
    }
}

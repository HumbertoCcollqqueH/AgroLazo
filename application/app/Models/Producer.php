<?php

namespace App\Models;

use App\Services\ProducerIdentity;
use Database\Factories\ProducerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable(['full_name', 'dni', 'phone', 'type', 'status', 'registered_by', 'association', 'email', 'sex', 'birth_date', 'photo_path', 'seed_batch'])]
#[Hidden(['dni', 'dni_lookup', 'phone', 'phone_lookup', 'email', 'photo_path', 'seed_batch'])]
class Producer extends Model
{
    /** @use HasFactory<ProducerFactory> */
    use HasFactory;

    public const TYPES = [
        'individual' => 'Agricultor individual',
        'family' => 'Productor familiar',
        'association_member' => 'Miembro de asociación',
        'cooperative' => 'Cooperativa',
        'other' => 'Otro',
    ];

    public const STATUSES = [
        'pending_verification' => 'Pendiente de verificación',
        'verified' => 'Verificado',
        'rejected' => 'Rechazado',
        'suspended' => 'Suspendido',
        'archived' => 'Archivado',
    ];

    protected function casts(): array
    {
        return ['dni' => 'encrypted', 'phone' => 'encrypted', 'email' => 'encrypted', 'birth_date' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $producer): void {
            if ($producer->exists && $producer->isDirty(['dni', 'registered_by', 'seed_batch'])) {
                throw ValidationException::withMessages(['dni' => 'La identidad y el responsable del registro original se conservan. El DNI no se modifica.']);
            }

            $identity = app(ProducerIdentity::class);
            $producer->phone = $identity->normalizePhone((string) $producer->phone);
            $producer->dni_lookup = $identity->lookup('dni', (string) $producer->dni);
            $producer->phone_lookup = $identity->lookup('phone', (string) $producer->phone);
        });
        static::deleting(fn () => throw new LogicException('El productor se archiva para conservar su historial; no se elimina.'));
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ProducerVerification::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ProducerLocation::class);
    }

    public function currentLocation(): HasOne
    {
        return $this->hasOne(ProducerLocation::class)->whereNull('valid_until');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ProducerConsent::class);
    }

    public function phoneChanges(): HasMany
    {
        return $this->hasMany(ProducerPhoneChange::class);
    }

    public function profileChanges(): HasMany
    {
        return $this->hasMany(ProducerProfileChange::class);
    }

    public function canPublishOffers(): bool
    {
        return $this->exists && $this->status === 'verified';
    }
}

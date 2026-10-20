<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Producer;
use App\Models\ProducerPayoutProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProducerPayoutProfiles
{
    public function canView(User $actor, Producer $producer): bool
    {
        $actor = $actor->fresh();

        return $actor?->active && ((int) $producer->fresh()->user_id === (int) $actor->id || $actor->hasPermission('confirm-payments'));
    }

    public function current(User $actor, Producer $producer): ?ProducerPayoutProfile
    {
        abort_unless($this->canView($actor, $producer), 403);

        return ProducerPayoutProfile::query()->where('producer_id', $producer->id)->latest('version')->first();
    }

    /** @return array{profile_id: int, version: int, holder_name: string, yape_phone: string, qr_sha256: ?string}|null */
    public function snapshot(User $actor, Producer $producer): ?array
    {
        $profile = $this->current($actor, $producer);

        return $profile ? ['profile_id' => $profile->id, 'version' => $profile->version, 'holder_name' => $profile->holder_name, 'yape_phone' => $profile->yape_phone, 'qr_sha256' => $profile->qr_sha256] : null;
    }

    public function save(User $actor, array $data, ?UploadedFile $qr): ProducerPayoutProfile
    {
        $data['holder_name'] = is_string($data['holder_name'] ?? null) ? trim($data['holder_name']) : null;
        $phone = is_string($data['yape_phone'] ?? null) ? preg_replace('/[\s()+-]/', '', $data['yape_phone']) : '';
        $data['yape_phone'] = strlen($phone) === 11 && str_starts_with($phone, '51') ? substr($phone, 2) : $phone;
        $data = Validator::make([...$data, 'qr' => $qr], [
            'holder_name' => 'required|string|min:3|max:150',
            'yape_phone' => ['required', 'string', 'regex:/^9[0-9]{8}$/'],
            'current_version' => 'required|integer|min:0',
            'submission_key' => 'required|uuid',
            'account_confirmed' => 'accepted',
            'remove_qr' => 'sometimes|boolean',
            'qr' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=5000,max_height=5000',
        ], [
            'yape_phone.regex' => 'Ingresa los 9 dígitos de tu celular Yape.',
            'account_confirmed.accepted' => 'Confirma que el titular y el celular corresponden a tu cuenta de cobro.',
            'qr.mimes' => 'Sube tu QR como imagen JPG, PNG o WebP.',
            'qr.max' => 'La imagen del QR debe pesar como máximo 2 MB.',
        ])->validate();
        if ($qr && ($data['remove_qr'] ?? false)) {
            throw ValidationException::withMessages(['qr' => 'Elige subir un QR o retirarlo, no ambas acciones a la vez.']);
        }
        $requestHash = hash('sha256', json_encode([
            $data['holder_name'], $data['yape_phone'], (int) $data['current_version'], (bool) ($data['remove_qr'] ?? false),
            $qr ? hash_file('sha256', $qr->getRealPath()) : null,
        ], JSON_THROW_ON_ERROR));
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $data, $qr, $requestHash, &$storedPath): ProducerPayoutProfile {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->active && $actor->hasRole('producer'), 403);
                $producer = Producer::query()->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
                abort_if($producer->status === 'archived', 403, 'La ficha está archivada.');
                $previousRequest = ProducerPayoutProfile::query()->where('submission_key', $data['submission_key'])->first();
                if ($previousRequest) {
                    abort_unless($previousRequest->producer_id === $producer->id && hash_equals($previousRequest->request_hash, $requestHash), 409, 'Esta solicitud corresponde a otros datos de cobro. Vuelve a abrir tu ficha.');

                    return $previousRequest;
                }
                $current = ProducerPayoutProfile::query()->where('producer_id', $producer->id)->latest('version')->first();
                if ((int) $data['current_version'] !== ($current?->version ?? 0)) {
                    throw ValidationException::withMessages(['current_version' => 'Tus datos de cobro cambiaron en otra sesión. Vuelve a abrir tu ficha antes de guardarlos.']);
                }
                $accountChanged = $current && ($current->holder_name !== $data['holder_name'] || $current->yape_phone !== $data['yape_phone']);
                $path = ($data['remove_qr'] ?? false) || $accountChanged ? null : $current?->qr_path;
                $mime = $path ? $current->qr_mime : null;
                $hash = $path ? $current->qr_sha256 : null;
                if ($qr) {
                    $storedPath = $qr->store('producer-payout-qr/'.$producer->id, 'local');
                    if (! $storedPath) {
                        throw ValidationException::withMessages(['qr' => 'No pudimos guardar el QR. Vuelve a intentarlo; tus datos anteriores siguen disponibles.']);
                    }
                    $path = $storedPath;
                    $mime = $qr->getMimeType();
                    $hash = hash_file('sha256', $qr->getRealPath());
                }
                $profile = ProducerPayoutProfile::query()->create([
                    'producer_id' => $producer->id, 'recorded_by' => $actor->id, 'version' => ($current?->version ?? 0) + 1,
                    'submission_key' => $data['submission_key'], 'request_hash' => $requestHash,
                    'holder_name' => $data['holder_name'], 'yape_phone' => $data['yape_phone'],
                    'qr_path' => $path, 'qr_mime' => $mime, 'qr_sha256' => $hash, 'created_at' => now(),
                ]);
                AuditLog::record($actor, 'producer.payout_profile_saved', $producer, ['profile_id' => $profile->id, 'version' => $profile->version, 'has_qr' => $path !== null], 'Datos de cobro declarados por el titular.');

                return $profile;
            });
        } catch (Throwable $error) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }
    }
}

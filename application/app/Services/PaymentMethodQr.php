<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PaymentMethodQr
{
    public function __construct(private Commerce $commerce) {}

    public function store(User $actor, PaymentMethod $method, array $data, UploadedFile $file): PaymentMethod
    {
        $path = null;
        try {
            return $this->commerce->transaction($actor, 'confirm-payments', function (User $actor) use ($method, $data, $file, &$path): PaymentMethod {
                $validated = Validator::make([...$data, 'qr' => $file], [
                    'key' => 'required|uuid', 'version' => 'required|integer|min:1',
                    'recipient_verified' => 'required|accepted',
                    'qr' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096',
                ])->validate();
                unset($validated['qr']);
                $validated['sha256'] = hash_file('sha256', $file->getRealPath());
                $validated['method_id'] = $method->id;

                return $this->commerce->once($actor, 'payment-method-qr', $validated, function () use ($actor, $method, $validated, $file, &$path): PaymentMethod {
                    $method = PaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
                    Commerce::ensure($method->version === (int) $validated['version'], 'version', 'Los datos del receptor cambiaron. Recarga y verifica el QR con la cuenta actual.');
                    Commerce::ensure($method->live_ready, 'qr', 'Primero configura y verifica la cuenta receptora de AgroLazo.');
                    Commerce::ensure(in_array($method->code, ['yape', 'plin'], true), 'qr', 'Este medio no utiliza un QR de billetera.');
                    if ($method->hasCurrentQr() && hash_equals($method->qr_sha256, $validated['sha256'])) {
                        return $method;
                    }
                    $path ??= 'payment-methods/'.$method->id.'/'.Str::uuid().'.'.$file->extension();
                    Commerce::ensure(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 'qr', 'No se pudo guardar el QR. Inténtalo de nuevo.');
                    $method->update(['qr_path' => $path, 'qr_mime' => $file->getMimeType(), 'qr_sha256' => $validated['sha256'], 'version' => $method->version + 1, 'qr_recipient_version' => $method->version + 1]);
                    AuditLog::record($actor, 'payment_method.qr_verified', $method, ['version' => $method->version, 'sha256' => $validated['sha256']]);

                    return $method;
                });
            });
        } catch (Throwable $exception) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}

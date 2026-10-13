<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareWhatsAppConnection;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\EvolutionConnectionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WhatsAppConnectionController extends Controller
{
    public function index(EvolutionConnectionManager $manager): View
    {
        Gate::authorize('manage-users');

        return view('messaging.connection', ['connection' => $manager->current()]);
    }

    public function status(Request $request, EvolutionConnectionManager $manager): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-users');

        if (! $request->expectsJson()) {
            return redirect()->route('whatsapp.connection');
        }

        return response()->json($manager->status());
    }

    public function prepare(Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $this->validateInput($request, ['integration' => ['required', Rule::in(['WHATSAPP-BAILEYS', 'WHATSAPP-BUSINESS'])]]);
        if ($data['integration'] === 'WHATSAPP-BUSINESS') {
            foreach (['access_token', 'phone_number_id', 'business_account_id', 'app_secret', 'verify_token', 'public_url'] as $key) {
                if (! filled(config('messaging.whatsapp.'.$key))) {
                    throw ValidationException::withMessages(['integration' => 'Faltan las credenciales de Meta en la configuración del servidor. Para vincular desde el teléfono, selecciona la opción QR.'])->redirectTo(route('whatsapp.connection'));
                }
            }
        }
        DB::transaction(function () use ($request, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-users');
            $connection = WhatsAppConnection::query()->lockForUpdate()->find(1);
            if ($connection?->status === 'preparing' && $connection->updated_at->greaterThan(now()->subMinutes(2))) {
                throw ValidationException::withMessages(['integration' => 'La conexión se está preparando. Espera un momento.'])->redirectTo(route('whatsapp.connection'));
            }
            if ($connection && $connection->integration !== $data['integration']) {
                throw ValidationException::withMessages(['integration' => 'Ya existe una conexión de otro tipo. El cambio de cuenta requiere una migración para conservar las conversaciones.'])->redirectTo(route('whatsapp.connection'));
            }
            $connection ??= new WhatsAppConnection(['id' => 1, 'automation_enabled' => false,
                'notice_text' => config('messaging.notice_text'), 'notice_version' => (string) Str::uuid(), 'notice_approved' => false]);
            $connection->fill(['integration' => $data['integration'],
                'instance_name' => substr((string) config('messaging.evolution.instance'), 0, 90).($data['integration'] === 'WHATSAPP-BAILEYS' ? '-qr' : ''),
                'status' => 'preparing', 'last_error' => null, 'revision' => ($connection->revision ?? 0) + 1, 'updated_by' => $actor->id])->save();
            AuditLog::record($actor, 'whatsapp.prepare', $connection, ['integration' => $connection->integration], 'connection_setup');
            PrepareWhatsAppConnection::dispatch($connection->revision)->afterCommit();
        });

        return redirect()->route('whatsapp.connection')->with('status', 'Preparando la conexión. El estado y el QR se actualizarán aquí.');
    }

    public function automation(Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $this->validateInput($request, ['enabled' => ['required', 'boolean'], 'notice_text' => 'required_if:enabled,1|nullable|string|min:30|max:1600', 'notice_approved' => 'accepted_if:enabled,1'], [
            'notice_approved.accepted_if' => 'Revisa el aviso y marca la casilla de confirmación antes de activar las respuestas.',
            'notice_text.required_if' => 'Escribe el aviso que recibirá el productor antes de activar las respuestas.',
            'notice_text.min' => 'El aviso debe contener al menos 30 caracteres.',
            'notice_text.max' => 'El aviso no puede superar 1.600 caracteres.',
        ]);
        DB::transaction(function () use ($request, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-users');
            $connection = WhatsAppConnection::query()->lockForUpdate()->findOrFail(1);
            if ($data['enabled'] && ! in_array($connection->status, ['connected', 'configured'], true)) {
                throw ValidationException::withMessages(['enabled' => 'Completa la conexión antes de activar las respuestas.'])->redirectTo(route('whatsapp.connection'));
            }
            if ($data['enabled']) {
                if ($connection->notice_text !== $data['notice_text']) {
                    $connection->notice_version = (string) Str::uuid();
                }
                $connection->fill(['notice_text' => $data['notice_text'], 'notice_approved' => true]);
            }
            $connection->fill(['automation_enabled' => (bool) $data['enabled'], 'updated_by' => $actor->id])->save();
            AuditLog::record($actor, 'whatsapp.automation', $connection, ['enabled' => $connection->automation_enabled], 'connection_setup');
        });

        return redirect()->route('whatsapp.connection')->with('status', $data['enabled'] ? 'Respuestas activadas para los productores registrados que escriban al número conectado.' : 'Respuestas automáticas desactivadas.');
    }

    private function validateInput(Request $request, array $rules, array $messages = []): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('whatsapp.connection'));
        }

        return $validator->validated();
    }
}

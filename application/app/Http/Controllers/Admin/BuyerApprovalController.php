<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveBuyerRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Role;
use App\Models\User;
use App\Services\ProducerPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BuyerApprovalController extends Controller
{
    public function store(ApproveBuyerRequest $request, Buyer $buyer): RedirectResponse
    {
        DB::transaction(function () use ($request, $buyer): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $target = Buyer::query()->lockForUpdate()->findOrFail($buyer->id);
            Gate::forUser($actor)->authorize('approve', $target);

            if (! $target->user->active || ! $target->user->hasRole('buyer')) {
                throw ValidationException::withMessages(['buyer' => 'Solo se aprueban cuentas activas con rol comprador.']);
            }

            if ($target->status === 'approved') {
                return;
            }

            if ($target->status !== 'pending') {
                throw ValidationException::withMessages(['buyer' => 'Este perfil no está pendiente de aprobación.']);
            }

            $target->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            AuditLog::record($actor, 'buyer.approved', $target, ['before' => 'pending', 'after' => 'approved'], $request->validated('reason'));
            ProducerPortal::notify($target->user, 'Tu cuenta de comprador fue aprobada. Ya puedes cotizar y confirmar compras desde AgroLazo.', route('marketplace.index'), 'buyer-approved:'.$target->id);
        });

        return back()->with('status', 'Comprador autorizado. La aprobación quedó registrada.');
    }
}

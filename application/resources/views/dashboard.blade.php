@php
    $displayPermissions = auth()->user()->permissionCodes();
    $dashboardTitle = $displayPermissions->contains('view-operations')
        ? ($displayPermissions->contains('manage-users') ? 'Panel de administración' : 'Centro de coordinación')
        : 'Mi espacio';
@endphp
<x-layout :title="$dashboardTitle">
    @if($displayPermissions->contains('view-operations'))
        @include($displayPermissions->contains('manage-users') ? 'dashboards.admin' : 'dashboards.coordinator')
    @elseif($displayPermissions->contains('view-buyer-area'))
        @include('dashboards.buyer')
    @else
        <section class="rounded-3xl border border-[#dfe4da] bg-white p-8">
            <h1 class="text-3xl font-semibold">Hola, {{ auth()->user()->name }}</h1>
            <p class="mt-4">Consulta tus datos y mantén actualizada tu cuenta.</p>
            <a href="{{ route('account.edit') }}" class="mt-6 inline-flex font-semibold underline">Administrar mi cuenta</a>
        </section>
    @endif
</x-layout>

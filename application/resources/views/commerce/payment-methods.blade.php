<x-layout title="Medios de pago">
    <a class="text-sm underline" href="{{ route('commerce.index') }}">← Operaciones</a><h1 class="mb-8 mt-5 text-3xl font-semibold">Medios de pago manuales</h1>
    <p class="mb-6 text-sm leading-7">Configura el receptor y verifica su titularidad antes de habilitar un medio comercial. Yape y Plin se registran manualmente; no hay confirmación automática de una API. Las órdenes sintéticas siempre muestran instrucciones de prueba.</p>
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach($methods as $method)
            <x-commerce-panel :title="$method->name">
                <form method="POST" action="{{ route('payments.methods.configure', $method) }}" class="space-y-4">
                    <x-commerce-key /><input type="hidden" name="version" value="{{ $method->version }}">
                    <x-field name="recipient" label="Receptor y cuenta o número verificados" :value="$method->recipient" required minlength="5" />
                    <label class="block text-sm font-semibold">Instrucciones y evidencia de verificación<textarea name="instructions" class="mt-2 w-full rounded-xl border border-[#cdd8c7] p-4 font-normal" required minlength="20" rows="4">{{ $method->instructions }}</textarea></label>
                    <x-commerce-select name="active" label="Disponibilidad"><option value="1" @selected($method->active)>Activo</option><option value="0" @selected(! $method->active)>Desactivado</option></x-commerce-select>
                    <x-commerce-select name="live_ready" label="Receptor comercial"><option value="0" @selected(! $method->live_ready)>Pendiente de verificar; solo pruebas</option><option value="1" @selected($method->live_ready)>Verificado para operación comercial</option></x-commerce-select>
                    <button class="rounded-xl bg-[#22583e] px-5 py-3 font-semibold text-white">Guardar versión {{ $method->version + 1 }}</button>
                </form>
            </x-commerce-panel>
        @endforeach
    </div>
</x-layout>


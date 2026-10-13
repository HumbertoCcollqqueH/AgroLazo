@props(['action', 'version'])
<form method="POST" action="{{ $action }}" class="mt-4 space-y-3">
    <x-commerce-key /><input type="hidden" name="version" value="{{ $version }}">
    <x-commerce-select name="result" label="Resultado comprobado">
        <option value="confirmed">Confirmado con evidencia</option>
        <option value="uncertain">Incierto: mantener importe comprometido</option>
        <option value="failed">Fallido: se verificó que no salió dinero</option>
    </x-commerce-select>
    <x-field name="operation_reference" label="Referencia de la operación o de la comprobación" required minlength="5" maxlength="120" />
    <x-field name="notes" label="Evidencia revisada y constancia del resultado" required minlength="20" />
    <p class="text-xs leading-5 text-[#6f7d66]">Este registro no ejecuta una transferencia. Confirma solo el resultado que hayas comprobado. En pruebas, usa referencias sintéticas.</p>
    <button class="rounded-xl border border-[#cdd8c7] px-5 py-3 font-semibold">Registrar resultado</button>
</form>


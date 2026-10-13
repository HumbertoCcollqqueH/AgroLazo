@props(['name', 'label'])
<label class="block text-sm font-semibold">{{ $label }}
<select name="{{ $name }}" {{ $attributes->class(['mt-2 w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3 font-normal']) }}>{{ $slot }}</select>
</label>


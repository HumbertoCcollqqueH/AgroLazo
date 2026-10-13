@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->class(['w-full rounded-xl border border-[#cdd8c7] bg-white px-4 py-3 text-base text-[#243c32] outline-none transition focus:border-[#22583e] focus:ring-2 focus:ring-[#d8e6cf]']) }}
        @if($errors->has($name)) aria-invalid="true" @endif @if($hint) aria-describedby="{{ $name }}-hint" @endif>
    @if($hint)<p id="{{ $name }}-hint" class="mt-2 text-xs leading-5 text-[#6f7d66]">{{ $hint }}</p>@endif
</div>

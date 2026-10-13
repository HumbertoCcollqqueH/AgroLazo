@props(['title'])
<section {{ $attributes->class(['rounded-3xl border border-[#dfe4da] bg-white p-6 sm:p-8']) }}>
    <h2 class="mb-5 text-xl font-semibold">{{ $title }}</h2>
    {{ $slot }}
</section>


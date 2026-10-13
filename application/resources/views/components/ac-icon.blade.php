@props(['name' => 'leaf'])
<svg {{ $attributes->class(['ac-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('brand')<path d="M12 20C5 19 3 14 4 6c7 0 10 4 8 14Z"/><path d="M12 20c7-1 9-6 8-14-7 0-10 4-8 14Zm0 0L7 10m5 10 5-10M12 20v2"/>@break
        @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
        @case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
        @case('chevron')<path d="m9 5 7 7-7 7"/>@break
        @case('pin')<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.5"/>@break
        @case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
        @case('box')<path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm0 9 9-4M3 8l9 4v10M8 5l9 5"/>@break
        @case('truck')<path d="M3 5h11v12H3zm11 5h4l3 4v3h-7"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="18" r="2.5"/>@break
        @case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
        @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
        @case('heart')<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>@break
        @case('filter')<path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2" fill="currentColor"/><circle cx="16" cy="17" r="2" fill="currentColor"/>@break
        @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
        @case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
        @case('users')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v2"/>@break
        @case('chart')<path d="M4 3v18h17M8 16v-5m5 5V7m5 9V4"/>@break
        @case('wallet')<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 8V5a2 2 0 0 1 2-2h13v3m3 5h-6v5h6"/><circle cx="17" cy="13.5" r=".5" fill="currentColor"/>@break
        @case('check')<path d="m5 12 4 4L19 6"/>@break
        @case('plus')<path d="M12 4v16M4 12h16"/>@break
        @case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
        @case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h1m6 0h1m-8 3h1"/>@break
        @case('inbox')<path d="m3 14 3-10h12l3 10v6H3v-6Zm0 0h6l1 3h4l1-3h6"/>@break
        @case('settings')<path d="m9 3-.6 2.6-2.3 1.3L3.5 6l-2 3.5 2 1.8v2.5l-2 1.7 2 3.5 2.6-.9 2.3 1.3L9 22h4l.7-2.6 2.3-1.3 2.5.9 2-3.5-2-1.7v-2.5l2-1.8-2-3.5-2.5.9-2.3-1.3L13 3H9Z" transform="translate(1 -1) scale(.96)"/><circle cx="12" cy="12" r="3"/>@break
        @case('external')<path d="M14 3h7v7m0-7L10 14M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>@break
        @case('logout')<path d="M9 3H4v18h5m7-14 5 5-5 5m-9-5h14"/>@break
        @case('document')<path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8m-8 4h6"/>@break
        @case('whatsapp')<path d="M20.5 11.6a8.5 8.5 0 0 1-12.7 7.4L3 20.5l1.5-4.7A8.5 8.5 0 1 1 20.5 11.6Z"/><path d="m8 7 1.5 3-1 1c1 2 2 3 4 4l1-1 3 1.5c0 2-2 3-4 2-4-1.5-7-5-7-8C5.5 7.5 7 6 8 7Z"/>@break
        @default<path d="M5 18c0-8 4-12 14-12 0 10-5 14-12 12m0 0L17 8M6 19l-2 2"/>
    @endswitch
</svg>

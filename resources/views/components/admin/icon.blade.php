@props(['name' => 'overview', 'size' => 20])
<svg class="app-icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('overview')
  <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
  @break
@case('building')
  <rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-5h6v5M8 7h1m6 0h1M8 11h1m6 0h1"/>
  @break
@case('users')
  <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3.2a4 4 0 0 1 0 7.6M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>
  @break
@case('history')
  <path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>
  @break
@case('shield')
  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>
  @break
@case('lock')
  <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
  @break
@case('logout')
  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
  @break
@case('menu')
  <path d="M4 6h16M4 12h16M4 18h16"/>
  @break
@case('chevron-down')
  <path d="m6 9 6 6 6-6"/>
  @break
@case('arrow-right')
  <path d="M5 12h14m-6-6 6 6-6 6"/>
  @break
@case('arrow-left')
  <path d="M19 12H5m6-6-6 6 6 6"/>
  @break
@case('plus')
  <path d="M12 5v14M5 12h14"/>
  @break
@case('search')
  <circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>
  @break
@case('check-circle')
  <circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>
  @break
@case('alert-circle')
  <circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 4h.01"/>
  @break
@case('briefcase')
  <rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/>
  @break
@case('user-check')
  <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>
  @break
@case('external-link')
  <path d="M13 5h6v6M19 5l-9 9"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>
  @break
@endswitch
</svg>
{{-- Symbole M affiché uniquement quand la sidebar admin est réduite --}}
@php
    $homeUrl = filament()->getHomeUrl();
    $brandName = filament()->getBrandName();
@endphp

<div
    x-show="! $store.sidebar.isOpen"
    x-cloak
    class="fi-sidebar-header-collapsed-logo-ctn"
>
    @if ($homeUrl)
        <a {{ \Filament\Support\generate_href_html($homeUrl) }}>
            <img
                src="{{ asset('images/branding/logoM.png') }}"
                alt="{{ $brandName }}"
                class="fi-logo"
                style="height: 2rem; width: 2rem; object-fit: contain;"
            />
        </a>
    @else
        <img
            src="{{ asset('images/branding/logoM.png') }}"
            alt="{{ $brandName }}"
            class="fi-logo"
            style="height: 2rem; width: 2rem; object-fit: contain;"
        />
    @endif
</div>

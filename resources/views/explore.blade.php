@extends('layouts.app')

@section('content')
@php
    $activeQuery = $filters['q'] ?? '';
    $activeFacility = $filters['memilikiFasilitas'] ?? '';
    $hasActiveFilters = $activeQuery !== ''
        || ($filters['jenis'] ?? '') !== ''
        || ($filters['kabupaten'] ?? '') !== ''
        || ($filters['dapatMelakukan'] ?? '') !== ''
        || $activeFacility !== ''
        || (($filters['sort'] ?? '') !== '' && ($filters['sort'] ?? '') !== 'name');
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full overflow-x-hidden">

    <!-- Header -->
    <div class="mb-8">
        <div class="text-xs text-gray-500 font-medium mb-4 flex items-center gap-2">
            <a href="{{ route('home') }}" class="hover:text-brand-primary">Beranda</a> <span aria-hidden="true">›</span>
            <span class="text-brand-primary font-bold">Direktori Destinasi</span>
        </div>

        <span class="inline-flex items-center gap-2 text-brand-accent text-xs font-bold uppercase tracking-wider mb-2">
            🎯 ATLAS LENGKAP SUMATERA UTARA
        </span>
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-4xl md:text-5xl font-serif font-bold text-gray-900 mb-4" data-reveal-words>Jelajahi Destinasi Sumatera Utara</h1>
                <p class="text-gray-600 text-lg max-w-2xl" data-reveal-up>
                    Temukan {{ \App\Support\IndonesianFormat::number($facets['total']) }} destinasi pilihan di Sumatera Utara, dari pesona alam dan situs bersejarah hingga pengalaman budaya.
                </p>
            </div>
            <div class="flex gap-3" data-reveal-up>
                <span class="bg-white border border-gray-200 text-gray-600 text-xs font-bold px-4 py-2 rounded-full shadow-sm">
                    {{ \App\Support\IndonesianFormat::number($facets['with_coordinates']) }} memiliki koordinat
                </span>
                <a href="{{ route('map') }}" class="bg-white border border-gray-200 text-gray-800 text-xs font-bold px-4 py-2 rounded-full shadow-sm hover:bg-gray-50 flex items-center gap-2">
                    Buka Peta
                </a>
            </div>
        </div>
    </div>

    @php
        $activeType = $filters['jenis'] ?? '';
        $activeKabupaten = $filters['kabupaten'] ?? '';
        $activeSort = $filters['sort'] ?? '';
        $activeActivity = $filters['dapatMelakukan'] ?? '';

        $urlFor = function (array $overrides = []) use ($filters): string {
            $params = array_merge($filters, $overrides);

            return route('destinations', array_filter(
                $params,
                static fn ($value): bool => $value !== null && $value !== ''
            ));
        };
    @endphp

    <section class="mb-8 space-y-3" aria-label="Filter destinasi">
        {{-- Tab kategori --}}
        <div class="flex items-center gap-6 overflow-x-auto border-b border-slate-200"
             data-category-pills>
            <a href="{{ $urlFor(['jenis' => null, 'page' => null]) }}"
               data-jenis=""
               class="shrink-0 border-b-2 px-1 py-3 text-sm whitespace-nowrap
                   {{ $activeType === '' ? 'border-emerald-900 font-semibold text-emerald-950' : 'border-transparent font-medium text-slate-600 hover:text-slate-900' }}">
                Semua Kategori
            </a>

            @foreach ($types as $type)
                <a href="{{ $urlFor(['jenis' => $type, 'page' => null]) }}"
                   data-jenis="{{ $type }}"
                   class="shrink-0 border-b-2 px-1 py-3 text-sm whitespace-nowrap
                       {{ $activeType === $type ? 'border-emerald-900 font-semibold text-emerald-950' : 'border-transparent font-medium text-slate-600 hover:text-slate-900' }}">
                    {{ $type }}
                </a>
            @endforeach
        </div>

        {{-- Filter urutan, kabupaten/kota, dan aktivitas --}}
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 pb-3">
            <select name="sort" form="destination-search-form" aria-label="Urutan destinasi">
                <option value="" hidden @selected($activeSort === '')>Urutan</option>
                @foreach ($sorts as $value => $label)
                    <option value="{{ $value }}" @selected($activeSort === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            <select name="kabupaten" form="destination-search-form"
                    aria-label="Saring berdasarkan kabupaten atau kota">
                <option value="">Semua Kabupaten/Kota</option>
                @foreach (array_keys($facets['kabupaten']) as $kabupaten)
                    <option value="{{ $kabupaten }}" @selected($activeKabupaten === $kabupaten)>
                        {{ $kabupaten }}
                    </option>
                @endforeach
            </select>

            <select name="dapatMelakukan" form="destination-search-form"
                    aria-label="Saring berdasarkan aktivitas">
                <option value="">Semua Aktivitas</option>
                @foreach ($filterOptions['activities'] as $activity)
                    <option value="{{ $activity }}" @selected($activeActivity === $activity)>
                        {{ $activity }}
                    </option>
                @endforeach
            </select>
        </div>
    </section>

    <p id="destination-live-status" class="sr-only" role="status" aria-live="polite"></p>
    <a id="destination-reset" href="{{ route('destinations') }}" class="mb-8 inline-flex rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-brand-accent hover:border-brand-accent {{ $hasActiveFilters ? '' : 'hidden' }}">Hapus filter</a>

    <!-- Destination Grid -->
    <div id="destination-results" aria-busy="false">
        @include('partials.destination-results', ['destinations' => $destinations, 'searchPlan' => $searchPlan, 'routeName' => 'destinations'])
    </div>

    <!-- Banner CTA -->
    <div class="bg-brand-dark text-white rounded-3xl p-8 md:p-12 relative overflow-hidden flex flex-col md:flex-row justify-between items-center gap-8 shadow-lg" data-reveal-up>
        <div class="relative z-10">
            <p class="text-xs font-bold text-brand-accent tracking-widest uppercase mb-2">🗺️ RENCANAKAN PERJALANAN</p>
            <h3 class="text-3xl md:text-4xl font-serif font-bold mb-4" data-reveal-words>Lihat semua destinasi di peta interaktif</h3>
            <p class="text-gray-300 text-sm max-w-2xl">Jelajahi destinasi berdasarkan kategori dan kabupaten atau kota, lalu pilih penanda untuk membuka detail destinasi.</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-3 relative z-10 w-full md:w-auto">
            <a href="{{ route('map') }}" class="bg-brand-accent hover:bg-orange-800 text-white px-6 py-3 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2">
                Buka Peta
            </a>
            <a href="{{ route('home') }}" class="bg-white/10 hover:bg-white/20 border border-white/20 text-white px-6 py-3 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2">
                Kembali ke Beranda
            </a>
        </div>
    </div>

</div>
@endsection

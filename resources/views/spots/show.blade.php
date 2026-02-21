@extends('layouts.app')

@section('title', $spot->title . ' — SpotEngine')

@section('content')
<div class="grid grid-cols-1 gap-6 p-4 xl:grid-cols-[300px_minmax(0,1fr)]">

    {{-- Sidebar panel --}}
    <aside class="self-start rounded-xl border border-gray-200 bg-white shadow-sm p-4"
           x-data="{ lightbox: false }">

        {{-- Image --}}
        <button type="button"
                @click="lightbox = true"
                class="block w-full cursor-zoom-in rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <img src="{{ route('spots.image', $spot) }}"
                 alt="{{ $spot->title }}"
                 class="w-full rounded-md border border-gray-200">
        </button>

        {{-- Lightbox --}}
        <div x-show="lightbox"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="lightbox = false"
             @keydown.escape.window="lightbox = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
             style="display: none;">
            <div @click.stop class="relative max-h-full max-w-full">
                <img src="{{ route('spots.image', $spot) }}"
                     alt="{{ $spot->title }}"
                     class="max-h-[90vh] max-w-[90vw] rounded-md object-contain shadow-2xl">
                <button type="button"
                        @click="lightbox = false"
                        class="absolute -top-3 -right-3 flex h-7 w-7 items-center justify-center rounded-full bg-white text-gray-700 shadow-md hover:bg-gray-100 focus:outline-none">
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Metadata --}}
        <dl class="mt-4 space-y-2 text-sm">
            @if($badgeLabel)
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Category</dt>
                    <dd class="text-right font-medium text-gray-900">{{ $badgeLabel }}</dd>
                </div>
            @endif

            <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2">
                <dt class="text-gray-500">Posted</dt>
                <dd class="text-right font-medium text-gray-900">{{ $spot->spot_posted_at->format('d M Y H:i') }}</dd>
            </div>

            <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2">
                <dt class="text-gray-500">Poster</dt>
                <dd class="text-right text-gray-700 break-all">{{ $spot->poster ?: 'Unknown' }}</dd>
            </div>

            @if($spot->website)
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Website</dt>
                    <dd class="text-right">
                        <a href="{{ $spot->website }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="font-medium text-blue-600 hover:text-blue-800 break-all text-xs">
                            {{ $spot->website }}
                        </a>
                    </dd>
                </div>
            @endif

            @if($spot->file_size)
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Size</dt>
                    <dd class="text-right font-mono font-medium text-gray-900">{{ $spot->size_formatted }}</dd>
                </div>
            @endif

            @if($subcategoryNames->isNotEmpty())
                <div class="pt-1">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Tags</dt>
                    <dd class="flex flex-wrap gap-1.5">
                        @foreach($subcategoryNames as $name)
                            <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600">
                                {{ $name }}
                            </span>
                        @endforeach
                    </dd>
                </div>
            @endif
        </dl>

        {{-- Actions --}}
        <div class="mt-5 flex flex-wrap gap-2">
            @if(!empty($spot->nzb_segments))
                <a href="{{ route('spots.nzb', $spot) }}"
                   class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                    Download NZB
                </a>
            @endif
            <a href="{{ route('home') }}"
               class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                ← Back
            </a>
        </div>

        @if(empty($spot->nzb_segments))
            <div class="mt-4 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-700">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575ZM8 5a.75.75 0 0 0-.75.75v2.5a.75.75 0 0 0 1.5 0v-2.5A.75.75 0 0 0 8 5Zm1 6a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/>
                </svg>
                <span>This spot is old and no longer has NZB data available on Usenet. Download is not possible.</span>
            </div>
        @endif
    </aside>

    {{-- Main content --}}
    <article class="rounded-xl border border-gray-200 bg-white shadow-sm p-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Spot detail</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900">{{ $spot->title }}</h1>

        @if($genreLabel)
            <p class="mt-2 text-sm text-gray-500">
                Genre: <span class="font-medium text-gray-700">{{ $genreLabel }}</span>
            </p>
        @endif

        @if($spot->description)
            <h2 class="mt-6 text-xs font-semibold uppercase tracking-wide text-gray-400">Description</h2>
            <div class="spot-description mt-3 text-sm leading-relaxed">{!! $spot->description_html !!}</div>
        @endif
    </article>

</div>
@endsection

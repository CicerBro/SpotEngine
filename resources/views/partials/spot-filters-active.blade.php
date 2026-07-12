@php
$hasFilters = request()->hasAny(['cat', 'subcat', 'q', 'new']);
$compact = $compact ?? false;
@endphp

@if($hasFilters)
    <div class="{{ $compact ? 'flex flex-wrap gap-1' : 'px-3 mb-3 flex flex-wrap gap-1' }}">
        @if(request()->boolean('new'))
            <a href="{{ request()->fullUrlWithoutQuery('new') }}"
               class="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-100 px-2 py-0.5 text-xs text-blue-700 transition-colors hover:bg-blue-200 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900">
                New
                <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                </svg>
            </a>
        @endif

        @if(request()->filled('q'))
            <a href="{{ request()->fullUrlWithoutQuery('q') }}"
               class="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-100 px-2 py-0.5 text-xs text-blue-700 transition-colors hover:bg-blue-200 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900">
                <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/>
                </svg>
                "{{ Str::limit(request('q'), 20) }}"
                <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                </svg>
            </a>
        @endif

        @if(request()->filled('cat'))
            @php
                $catCode = request('cat');
                $catName = ($categoriesByCode ?? collect())->get($catCode)?->name ?? $catCode;
            @endphp
            <a href="{{ request()->fullUrlWithoutQuery(['cat', 'subcat']) }}"
               class="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-100 px-2 py-0.5 text-xs text-blue-700 transition-colors hover:bg-blue-200 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900">
                {{ $catName }}
                <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                </svg>
            </a>
        @endif

        @foreach((array) request('subcat', []) as $subcatCode)
            @php
                $subcatName = ($categoriesByCode ?? collect())->get($subcatCode)?->name ?? $subcatCode;
                $remaining = array_filter((array) request('subcat', []), fn ($c) => $c !== $subcatCode);
                $removeUrl = $remaining
                    ? request()->fullUrlWithQuery(['subcat' => array_values($remaining)])
                    : request()->fullUrlWithoutQuery('subcat');
            @endphp
            <a href="{{ $removeUrl }}"
               class="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-100 px-2 py-0.5 text-xs text-blue-700 transition-colors hover:bg-blue-200 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900">
                {{ $subcatName }}
                <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
                </svg>
            </a>
        @endforeach

    </div>
@endif

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <div class="flex items-center justify-between gap-4">
            {{-- Mobile: simple prev/next --}}
            <div class="flex flex-1 items-center justify-between sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-300 cursor-not-allowed">Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors">Previous</a>
                @endif

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors">Next</a>
                @else
                    <span class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-300 cursor-not-allowed">Next</span>
                @endif
            </div>

            {{-- Desktop: full pagination --}}
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-500">
                        Showing
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        to
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                        of
                        <span class="font-medium">{{ number_format($paginator->total()) }}</span>
                        results
                    </p>
                </div>

                <div>
                    <span class="relative z-0 inline-flex -space-x-px rounded-md shadow-sm">
                        {{-- Previous --}}
                        @if ($paginator->onFirstPage())
                            <span aria-disabled="true" class="relative inline-flex items-center rounded-l-md border border-gray-200 bg-white px-2 py-1.5 text-sm text-gray-300 cursor-not-allowed">
                                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M9.78 12.78a.75.75 0 0 1-1.06 0L4.47 8.53a.75.75 0 0 1 0-1.06l4.25-4.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042L6.06 8l3.72 3.72a.75.75 0 0 1 0 1.06Z"/>
                                </svg>
                            </span>
                        @else
                            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="relative inline-flex items-center rounded-l-md border border-gray-200 bg-white px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors focus:z-10 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M9.78 12.78a.75.75 0 0 1-1.06 0L4.47 8.53a.75.75 0 0 1 0-1.06l4.25-4.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042L6.06 8l3.72 3.72a.75.75 0 0 1 0 1.06Z"/>
                                </svg>
                            </a>
                        @endif

                        {{-- Pages --}}
                        @foreach ($elements as $element)
                            @if (is_string($element))
                                <span class="relative inline-flex items-center border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-400">{{ $element }}</span>
                            @endif

                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="relative z-10 inline-flex items-center border border-blue-600 bg-blue-600 px-3 py-1.5 text-sm font-medium text-white focus:z-20">{{ $page }}</span>
                                    @else
                                        <a href="{{ $url }}" class="relative inline-flex items-center border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors focus:z-10 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">{{ $page }}</a>
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next --}}
                        @if ($paginator->hasMorePages())
                            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="relative inline-flex items-center rounded-r-md border border-gray-200 bg-white px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-50 transition-colors focus:z-10 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"/>
                                </svg>
                            </a>
                        @else
                            <span aria-disabled="true" class="relative inline-flex items-center rounded-r-md border border-gray-200 bg-white px-2 py-1.5 text-sm text-gray-300 cursor-not-allowed">
                                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"/>
                                </svg>
                            </span>
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </nav>
@endif

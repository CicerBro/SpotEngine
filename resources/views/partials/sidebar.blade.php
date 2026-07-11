@php
$categoryColorMap = [
    'image'        => '--color-cat-image',
    'audio'        => '--color-cat-audio',
    'games'        => '--color-cat-games',
    'applications' => '--color-cat-applications',
];

$sidebarTree = $categoryTree ?? [];
@endphp

{{-- Mobile backdrop --}}
<div class="fixed inset-0 z-40 bg-black/50 lg:hidden"
     x-show="sidebarOpen"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="sidebarOpen = false"
     style="display: none;">
</div>

{{-- Sidebar panel --}}
<aside class="fixed top-[52px] left-0 z-40 flex h-[calc(100vh-52px)] w-64 min-h-0 flex-col border-r border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-950
              transition-transform duration-200 ease-in-out
              lg:translate-x-0 -translate-x-full"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">

    {{-- FILTERS (scrollable) --}}
    @if(!empty($sidebarTree))
    <div class="min-h-0 flex-1 overflow-y-auto py-4">
        <div class="flex items-center justify-between px-5 mb-2">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-slate-500">Filters</p>
            @if(request()->hasAny(['cat', 'subcat', 'q']))
                <a href="{{ route('home') }}"
                   class="text-xs text-gray-400 transition-colors hover:text-gray-700 dark:text-slate-500 dark:hover:text-slate-200">
                    Reset
                </a>
            @endif
        </div>

        @include('partials.spot-filters-active', ['compact' => false])

        @foreach($sidebarTree as $code => $node)
            @php
                $rootCat = $node['category'];
                $colorVar = $categoryColorMap[$rootCat->slug] ?? '--color-cat-image';
                $subcats = $node['subcategories'];
                $visibleSubcats = collect()
                    ->merge($subcats['format'] ?? [])
                    ->merge($subcats['type'] ?? [])
                    ->merge($subcats['platform'] ?? []);
            @endphp

            <div class="mb-1" x-data="{ open: localStorage.getItem('sidebar-cat-{{ $code }}') !== 'false' }">
                {{-- Category header --}}
                <button type="button"
                        @click="open = !open; localStorage.setItem('sidebar-cat-{{ $code }}', open)"
                        class="flex w-full cursor-pointer items-center gap-2 px-3 py-1.5 text-left transition-colors hover:bg-gray-50 focus:outline-none dark:hover:bg-slate-900">
                    <span class="w-[3px] h-4 rounded-full shrink-0"
                          style="background-color: var({{ $colorVar }})"></span>
                    <a href="{{ request()->fullUrlWithQuery(['cat' => $rootCat->code]) }}"
                       @click.stop
                       class="flex-1 text-sm font-semibold uppercase tracking-wider text-gray-500 transition-colors hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100
                              {{ request('cat') === $rootCat->code ? 'text-gray-900 dark:text-slate-100' : '' }}">
                        {{ $rootCat->name }}
                    </a>
                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform duration-150 dark:text-slate-500"
                         :class="open ? 'rotate-180' : ''"
                         viewBox="0 0 16 16" fill="currentColor">
                        <path d="M4.427 7.427l3.396 3.396a.25.25 0 0 0 .354 0l3.396-3.396A.25.25 0 0 0 11.396 7H4.604a.25.25 0 0 0-.177.427Z"/>
                    </svg>
                </button>

                {{-- Sub-items --}}
                <div x-show="open" x-collapse x-cloak>
                    @foreach($visibleSubcats as $sub)
                        @php
                            $isActive = in_array($sub->code, (array) request('subcat', []), true);
                            $filterUrl = request()->fullUrlWithQuery([
                                'cat'    => $rootCat->code,
                                'subcat' => $sub->code,
                            ]);
                        @endphp
                        <a href="{{ $filterUrl }}"
                           class="flex items-center py-[3px] pl-7 pr-3 text-sm transition-colors
                                  {{ $isActive
                                      ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300'
                                      : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100' }}">
                            @if($isActive)
                                <span class="mr-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600"></span>
                            @else
                                <span class="mr-1.5 h-1.5 w-1.5 shrink-0"></span>
                            @endif
                            {{ $sub->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Last retrieval + USER + LOGOUT (always visible at bottom) --}}
    <div class="mt-auto shrink-0 border-t border-gray-200 px-3 py-3 dark:border-slate-800">
        @auth
            @php
                $usenetState = \App\Models\UsenetState::orderBy('last_retrieval_at', 'desc')->first();
            @endphp
            @if($usenetState?->last_retrieval_at)
                <p class="mb-2 px-2 text-sm text-gray-400 dark:text-slate-500">
                    Last update: {{ $usenetState->last_retrieval_at->diffForHumans() }}
                </p>
            @endif

            <a href="{{ route('profile') }}"
               class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M10.561 8.073a6.005 6.005 0 0 1 3.432 5.142.75.75 0 1 1-1.498.07 4.5 4.5 0 0 0-8.99 0 .75.75 0 0 1-1.498-.07 6.004 6.004 0 0 1 3.431-5.142 3.999 3.999 0 1 1 5.123 0ZM10.5 5a2.5 2.5 0 1 0-5 0 2.5 2.5 0 0 0 5 0Z"/>
                </svg>
                {{ auth()->user()->username }}
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M2 2.75C2 1.784 2.784 1 3.75 1h2.5a.75.75 0 0 1 0 1.5h-2.5a.25.25 0 0 0-.25.25v10.5c0 .138.112.25.25.25h2.5a.75.75 0 0 1 0 1.5h-2.5A1.75 1.75 0 0 1 2 13.25Zm10.44 4.5-1.97-1.97a.749.749 0 0 1 .326-1.275.749.749 0 0 1 .734.215l3.25 3.25a.75.75 0 0 1 0 1.06l-3.25 3.25a.749.749 0 0 1-1.275-.326.749.749 0 0 1 .215-.734l1.97-1.97H6.75a.75.75 0 0 1 0-1.5Z"/>
                    </svg>
                    Sign out
                </button>
            </form>
        @endauth
    </div>

</aside>

{{-- Desktop spacer --}}
<div class="hidden lg:block w-64 shrink-0"></div>

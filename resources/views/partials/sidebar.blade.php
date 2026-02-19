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
<div class="fixed inset-0 z-40 bg-black/40 lg:hidden"
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
<aside class="fixed top-[52px] left-0 z-40 flex h-[calc(100vh-52px)] w-64 flex-col border-r border-gray-200 bg-white overflow-y-auto
              transition-transform duration-200 ease-in-out
              lg:translate-x-0 -translate-x-full"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">

    {{-- FILTERS --}}
    @if(!empty($sidebarTree))
    <div class="py-4 flex-1">
        <div class="flex items-center justify-between px-5 mb-2">
            <p class="text-xs font-semibold text-gray-400 tracking-wider uppercase">Filters</p>
            @if(request()->hasAny(['cat', 'subcat', 'q']))
                <a href="{{ route('home') }}"
                   class="text-xs text-gray-400 hover:text-gray-700 transition-colors">
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
                        class="cursor-pointer flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-gray-50 transition-colors focus:outline-none">
                    <span class="w-[3px] h-4 rounded-full shrink-0"
                          style="background-color: var({{ $colorVar }})"></span>
                    <a href="{{ request()->fullUrlWithQuery(['cat' => $rootCat->code]) }}"
                       @click.stop
                       class="flex-1 text-sm font-semibold text-gray-500 uppercase tracking-wider hover:text-gray-900 transition-colors
                              {{ request('cat') === $rootCat->code ? 'text-gray-900' : '' }}">
                        {{ $rootCat->name }}
                    </a>
                    <svg class="h-3.5 w-3.5 text-gray-400 transition-transform duration-150 shrink-0"
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
                           class="flex items-center pl-7 pr-3 py-[3px] text-[12px] transition-colors
                                  {{ $isActive
                                      ? 'text-blue-700 font-medium bg-blue-50'
                                      : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50' }}">
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

    {{-- Last retrieval + USER + LOGOUT --}}
    <div class="border-t border-gray-200 py-3 px-3 shrink-0">
        @auth
            @php
                $usenetState = \App\Models\UsenetState::orderBy('last_retrieval_at', 'desc')->first();
            @endphp
            @if($usenetState?->last_retrieval_at)
                <p class="px-2 mb-2 text-xs text-gray-400">
                    Last update: {{ $usenetState->last_retrieval_at->diffForHumans() }}
                </p>
            @endif

            <a href="{{ route('profile') }}"
               class="flex items-center gap-2.5 px-2 py-1.5 rounded-md text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M10.561 8.073a6.005 6.005 0 0 1 3.432 5.142.75.75 0 1 1-1.498.07 4.5 4.5 0 0 0-8.99 0 .75.75 0 0 1-1.498-.07 6.004 6.004 0 0 1 3.431-5.142 3.999 3.999 0 1 1 5.123 0ZM10.5 5a2.5 2.5 0 1 0-5 0 2.5 2.5 0 0 0 5 0Z"/>
                </svg>
                {{ auth()->user()->username }}
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-2.5 px-2 py-1.5 rounded-md text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors text-left">
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

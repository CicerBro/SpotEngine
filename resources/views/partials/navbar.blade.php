<header class="fixed inset-x-0 top-0 z-50 flex h-[52px] items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">

    {{-- LEFT: hamburger + wordmark --}}
    <div class="flex items-center gap-3 shrink-0">
        <button type="button"
                class="flex items-center justify-center rounded-md p-1.5 text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-400 dark:hover:bg-slate-800 dark:focus:ring-offset-slate-950 lg:hidden"
                @click="sidebarOpen = !sidebarOpen"
                aria-label="Toggle menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>

        <a href="{{ route('home') }}" class="flex items-center gap-1.5 text-2xl leading-none">
            <img src="{{ asset('spotengine-mark.svg') }}" alt="" class="h-7 w-7" aria-hidden="true">
            <span><span class="font-bold text-gray-900 dark:text-slate-100">Spot</span><span class="font-semibold text-blue-600 dark:text-blue-400">Engine</span></span>
        </a>
    </div>

    {{-- CENTER: search --}}
    <div class="flex-1 max-w-sm lg:max-w-md">
        <form method="GET" action="{{ route('home') }}" role="search">
            @foreach(array_filter(request()->query(), fn ($key) => ! in_array($key, ['q', 'search_in', 'cursor', 'exc_subcat']), ARRAY_FILTER_USE_KEY) as $key => $value)
                @foreach((array) $value as $item)
                    <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $item }}">
                @endforeach
            @endforeach
            <div class="flex items-center gap-1.5">
                <div class="relative flex-1">
                    <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center">
                        <svg class="h-4 w-4 text-gray-400 dark:text-slate-500" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/>
                        </svg>
                    </div>
                    <input type="search"
                           name="q"
                           value="{{ request('q') }}"
                           placeholder="Search spots..."
                           class="w-full rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder-slate-500 dark:focus:bg-slate-800 dark:focus:ring-offset-slate-950">
                </div>
                <select name="search_in"
                        onchange="if(this.form.q.value) this.form.submit()"
                        class="cursor-pointer rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5 text-xs text-gray-500 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:focus:ring-offset-slate-950">
                    <option value="title" @selected(request('search_in', 'title') === 'title')>Title</option>
                    <option value="description" @selected(request('search_in') === 'description')>Description</option>
                    <option value="both" @selected(request('search_in') === 'both')>Title + Description</option>
                </select>
            </div>
        </form>
    </div>

    {{-- RIGHT: user --}}
    <div class="flex items-center gap-3 shrink-0">
        @auth
            @if(auth()->user()->is_admin)
                <a href="{{ route('admin.index') }}"
                   class="hidden text-xs font-medium text-gray-500 transition-colors hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 sm:inline">
                    Admin
                </a>
            @endif

            <x-theme-toggle class="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:focus:ring-offset-slate-950" />

            <a href="{{ route('profile') }}"
               class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 dark:focus:ring-offset-slate-950"
               title="{{ auth()->user()->username }}">
                {{ strtoupper(substr(auth()->user()->username, 0, 2)) }}
            </a>
        @endauth
    </div>

</header>

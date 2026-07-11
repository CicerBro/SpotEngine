<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SpotEngine')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <script>
        (() => {
            const preference = localStorage.getItem('spotengine-theme');
            const dark = preference === 'dark'
                || (preference === null && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data class="h-full bg-gray-50 text-gray-900 antialiased dark:bg-slate-950 dark:text-slate-100">

    <button type="button"
            @click="$store.theme.toggle()"
            :aria-label="$store.theme.dark ? 'Use light theme' : 'Use dark theme'"
            class="fixed right-4 top-4 flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
        <svg x-show="!$store.theme.dark" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M8 1.25a.75.75 0 0 1 .75.75v.5a.75.75 0 0 1-1.5 0V2A.75.75 0 0 1 8 1.25ZM8 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8Zm0 1.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Zm6.75 4.75a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1 0-1.5h.5a.75.75 0 0 1 .75.75ZM2.5 8.75H2a.75.75 0 0 1 0-1.5h.5a.75.75 0 0 1 0 1.5Z"/>
        </svg>
        <svg x-show="$store.theme.dark" x-cloak class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M6.049 1.41a.75.75 0 0 1 .673 1.23A5.5 5.5 0 0 0 13.36 9.28a.75.75 0 0 1 1.23.673A6.75 6.75 0 1 1 6.049 1.41ZM4.78 3.236a5.25 5.25 0 1 0 7.984 7.984A7 7 0 0 1 4.78 3.236Z"/>
        </svg>
    </button>

    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <a href="{{ route('login') }}" class="mb-8 flex items-center gap-1.5 text-2xl">
            <img src="{{ asset('spotengine-mark.svg') }}" alt="" class="h-8 w-8" aria-hidden="true">
            <span><span class="font-semibold text-gray-900 dark:text-slate-100">Spot</span><span class="font-semibold text-blue-600 dark:text-blue-400">Engine</span></span>
        </a>

        @yield('content')
    </div>

</body>
</html>

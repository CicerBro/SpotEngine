<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SpotEngine')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data class="h-full bg-gray-50 text-gray-900 antialiased dark:bg-slate-950 dark:text-slate-100">

    <x-theme-toggle class="fixed right-4 top-4 flex h-9 w-9 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100" />

    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <a href="{{ route('login') }}" class="mb-8 flex items-center gap-1.5 text-2xl">
            <img src="{{ asset('spotengine-mark.svg') }}" alt="" class="h-8 w-8" aria-hidden="true">
            <span><span class="font-semibold text-gray-900 dark:text-slate-100">Spot</span><span class="font-semibold text-blue-600 dark:text-blue-400">Engine</span></span>
        </a>

        @yield('content')
    </div>

</body>
</html>

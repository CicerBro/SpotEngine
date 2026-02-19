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
<body class="h-full bg-gray-50 text-gray-900 antialiased">

    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <a href="{{ route('login') }}" class="mb-8 text-2xl">
            <span class="font-semibold text-gray-900">Spot</span><span class="font-semibold text-blue-600">Engine</span>
        </a>

        @yield('content')
    </div>

</body>
</html>

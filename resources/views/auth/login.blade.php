@extends('layouts.auth')

@section('title', 'Sign in — SpotEngine')

@section('content')
<div class="w-full max-w-sm">
    <div class="rounded-xl border border-gray-200 bg-white px-8 py-8 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">Sign in</h1>
        <p class="mt-1 text-sm text-gray-500">Access downloads and manage your account.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                    Username or email
                </label>
                <input id="username"
                       type="text"
                       name="username"
                       value="{{ old('username') }}"
                       required
                       autofocus
                       autocomplete="username"
                       class="w-full rounded-lg border px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1
                              {{ $errors->has('username') ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white' }}">
                @error('username')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                    Password
                </label>
                <input id="password"
                       type="password"
                       name="password"
                       required
                       autocomplete="current-password"
                       class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1
                              {{ $errors->has('password') ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white' }}">
                @error('password')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between">
                <label for="remember" class="flex items-center gap-2 text-sm text-gray-600 select-none cursor-pointer">
                    <input type="checkbox"
                           name="remember"
                           id="remember"
                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 focus:ring-offset-1">
                    Remember me
                </label>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                Sign in
            </button>
        </form>
    </div>

    @if(Route::has('register'))
        <p class="mt-4 text-center text-sm text-gray-500">
            No account?
            <a href="{{ route('register') }}" class="font-medium text-blue-600 hover:text-blue-700">Create one</a>
        </p>
    @endif
</div>
@endsection

@extends('layouts.auth')

@section('title', 'Register — SpotEngine')

@section('content')
<div class="w-full max-w-sm">
    <div class="rounded-xl border border-gray-200 bg-white px-8 py-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Create account</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Register to download spots and manage API access.</p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Username</label>
                <input id="username"
                       type="text"
                       name="username"
                       value="{{ old('username') }}"
                       required
                       autofocus
                       autocomplete="username"
                       class="w-full rounded-lg border px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-100 dark:focus:ring-offset-slate-900
                              {{ $errors->has('username') ? 'border-red-400 bg-red-50 dark:bg-red-950' : 'border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-800' }}">
                @error('username')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Display name</label>
                <input id="name"
                       type="text"
                       name="name"
                       value="{{ old('name') }}"
                       required
                       class="w-full rounded-lg border px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-100 dark:focus:ring-offset-slate-900
                              {{ $errors->has('name') ? 'border-red-400 bg-red-50 dark:bg-red-950' : 'border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-800' }}">
                @error('name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Email</label>
                <input id="email"
                       type="email"
                       name="email"
                       value="{{ old('email') }}"
                       required
                       autocomplete="email"
                       class="w-full rounded-lg border px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-100 dark:focus:ring-offset-slate-900
                              {{ $errors->has('email') ? 'border-red-400 bg-red-50 dark:bg-red-950' : 'border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-800' }}">
                @error('email')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Password</label>
                <input id="password"
                       type="password"
                       name="password"
                       required
                       autocomplete="new-password"
                       class="w-full rounded-lg border px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-100 dark:focus:ring-offset-slate-900
                              {{ $errors->has('password') ? 'border-red-400 bg-red-50 dark:bg-red-950' : 'border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-800' }}">
                @error('password')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Confirm password</label>
                <input id="password_confirmation"
                       type="password"
                       name="password_confirmation"
                       required
                       autocomplete="new-password"
                       class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-offset-slate-900">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                Create account
            </button>
        </form>
    </div>

    <p class="mt-4 text-center text-sm text-gray-500 dark:text-slate-400">
        Already registered?
        <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Sign in</a>
    </p>
</div>
@endsection

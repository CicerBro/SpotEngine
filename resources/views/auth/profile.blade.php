@extends('layouts.app')

@section('title', 'Profile — SpotEngine')

@section('content')
<div class="mx-auto max-w-3xl space-y-6 p-4">

    <header class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Profile</h1>
        <p class="mt-1 text-sm text-gray-500">Update account details, password and API credentials.</p>
    </header>

    {{-- Profile information --}}
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="text-base font-semibold text-gray-900">Profile information</h2>
        <form method="POST" action="{{ route('user-profile-information.update') }}" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            @csrf @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Display name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:outline-none
                              @error('name', 'updateProfileInformation') ring-red-400 bg-red-50 focus:ring-red-500 @else ring-gray-300 focus:ring-blue-600 @enderror">
                @error('name', 'updateProfileInformation')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:outline-none
                              @error('email', 'updateProfileInformation') ring-red-400 bg-red-50 focus:ring-red-500 @else ring-gray-300 focus:ring-blue-600 @enderror">
                @error('email', 'updateProfileInformation')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                    Save profile
                </button>
            </div>
        </form>
    </section>

    {{-- Change password --}}
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="text-base font-semibold text-gray-900">Change password</h2>
        <form method="POST" action="{{ route('user-password.update') }}" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            @csrf @method('PUT')

            <div class="md:col-span-2">
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current password</label>
                <input id="current_password" type="password" name="current_password" required autocomplete="current-password"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:outline-none
                              @error('current_password', 'updatePassword') ring-red-400 bg-red-50 focus:ring-red-500 @else ring-gray-300 focus:ring-blue-600 @enderror">
                @error('current_password', 'updatePassword')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:outline-none
                              @error('password', 'updatePassword') ring-red-400 bg-red-50 focus:ring-red-500 @else ring-gray-300 focus:ring-blue-600 @enderror">
                @error('password', 'updatePassword')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 focus:outline-none">
            </div>

            <div class="md:col-span-2">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                    Update password
                </button>
            </div>
        </form>
    </section>

    {{-- API key --}}
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="text-base font-semibold text-gray-900">API key</h2>
        <p class="mt-1 text-sm text-gray-500">Use this key in Sonarr, Radarr, or other Newznab clients.</p>
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <code class="min-w-0 flex-1 break-all rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-mono text-gray-800">{{ $user->api_token }}</code>
            <form method="POST" action="{{ route('profile.api-key.regenerate') }}" class="shrink-0">
                @csrf
                <button type="submit"
                        onclick="return confirm('Regenerate API key? Existing integrations will stop working.')"
                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                    Regenerate
                </button>
            </form>
        </div>
    </section>

</div>
@endsection

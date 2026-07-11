@extends('layouts.app')

@section('title', 'Users — Admin')

@section('content')
<div class="space-y-6 p-4">

    @include('partials.admin-nav')

    <header class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-slate-100">Users</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Manage accounts and admin access.</p>
    </header>

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-gray-100 bg-gray-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
            <form method="GET" action="{{ route('admin.users') }}" class="flex items-center gap-2">
                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search username, email, or API token…"
                       class="block w-full max-w-sm rounded-lg border-0 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 dark:placeholder:text-slate-500">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-gray-700 transition-colors">
                    Search
                </button>
                @if($search ?? false)
                    <a href="{{ route('admin.users') }}" class="text-sm text-gray-500 transition-colors hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">Clear</a>
                @endif
            </form>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-slate-950 dark:text-slate-400">
                <tr class="text-left">
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800">Username</th>
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800">Email</th>
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800">Admin</th>
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800">Signed up</th>
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800">Last login</th>
                    <th class="border-b border-gray-100 px-4 py-2 font-semibold dark:border-slate-800"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-slate-800 dark:text-slate-300">
                @foreach($users as $user)
                    <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-slate-800">
                        <td class="px-4 py-2 font-medium">{{ $user->username }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-slate-400">{{ $user->email }}</td>
                        <td class="px-4 py-2">
                            @if($user->is_admin)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-900">Admin</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{{ $user->created_at->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{{ $user->last_login_at ? $user->last_login_at->format('M j, Y') . ' (' . $user->last_login_at->diffForHumans() . ')' : 'Never' }}</td>
                        <td class="px-4 py-2 text-right">
                            @unless($user->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.users.delete', $user) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            onclick="return confirm('Delete this user?')"
                                            class="text-xs font-medium text-red-600 hover:text-red-700 transition-colors">
                                        Delete
                                    </button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($users->hasPages())
            <div class="border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                {{ $users->links() }}
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Create user</h2>
        <form method="POST" action="{{ route('admin.users.create') }}" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            @csrf

            <div>
                <label for="new_username" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Username</label>
                <input id="new_username" type="text" name="username" required
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 dark:placeholder:text-slate-500">
            </div>

            <div>
                <label for="new_name" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Display name</label>
                <input id="new_name" type="text" name="name" required
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 dark:placeholder:text-slate-500">
            </div>

            <div>
                <label for="new_email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Email</label>
                <input id="new_email" type="email" name="email" required
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 dark:placeholder:text-slate-500">
            </div>

            <div>
                <label for="new_password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Password</label>
                <input id="new_password" type="password" name="password" required autocomplete="new-password"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700 dark:placeholder:text-slate-500">
            </div>

            <label for="is_admin_new" class="flex cursor-pointer select-none items-center gap-2 text-sm text-gray-600 dark:text-slate-300 md:col-span-2">
                <input type="checkbox" name="is_admin" value="1" id="is_admin_new"
                       class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 focus:ring-offset-1">
                Administrator
            </label>

            <div class="md:col-span-2">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                    Create user
                </button>
            </div>
        </form>
    </section>

</div>
@endsection

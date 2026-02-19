@extends('layouts.app')

@section('title', 'Admin — SpotEngine')

@section('content')
<div class="space-y-6 p-4">

    <header class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Admin Dashboard</h1>
        <p class="mt-1 text-sm text-gray-500">Monitor overview metrics and clean up old spots.</p>
    </header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <article class="rounded-xl border border-gray-200 bg-white shadow-sm p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total spots</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($stats['total_spots']) }}</p>
        </article>

        <article class="rounded-xl border border-gray-200 bg-white shadow-sm p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Users</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($stats['total_users']) }}</p>
        </article>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <header class="border-b border-gray-100 bg-gray-50 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-700">Category breakdown</h2>
            </header>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr class="text-left">
                        <th class="border-b border-gray-100 px-4 py-2 font-semibold">Category</th>
                        <th class="border-b border-gray-100 px-4 py-2 font-semibold">Count</th>
                        <th class="border-b border-gray-100 px-4 py-2 font-semibold">Latest</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700">
                    @foreach($stats['category_stats'] as $stat)
                        <tr>
                            <td class="px-4 py-2">{{ $stat->category_code }}</td>
                            <td class="px-4 py-2 font-mono">{{ number_format($stat->count) }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ \Carbon\Carbon::parse($stat->latest)->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="space-y-2">
            <h2 class="px-1 text-sm font-semibold text-gray-700">Usenet state</h2>
            @foreach($usenetState as $state)
                <article class="rounded-xl border border-gray-200 bg-white shadow-sm p-4 text-sm">
                    <p class="font-mono font-semibold text-gray-900">{{ $state->newsgroup }}</p>
                    <p class="mt-1 text-gray-500">Last article: {{ number_format($state->last_article_id) }}</p>
                    <p class="text-xs text-gray-400">{{ $state->last_retrieval_at?->diffForHumans() ?? 'Never' }}</p>
                </article>
            @endforeach
        </section>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="text-base font-semibold text-gray-900">Cleanup</h2>
        <form method="POST" action="{{ route('admin.clean') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="days" class="block text-sm font-medium text-gray-700 mb-1">Delete spots older than (days)</label>
                <input id="days" type="number" name="days" value="30" min="1"
                       class="block w-32 rounded-lg border-0 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-blue-600 focus:outline-none">
            </div>
            <button type="submit"
                    onclick="return confirm('This will permanently delete spots. Continue?')"
                    class="inline-flex items-center justify-center rounded-md border border-transparent bg-red-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1 transition-colors">
                Delete old spots
            </button>
        </form>
    </section>

</div>
@endsection

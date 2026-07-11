<div x-data="infiniteSpots(@js($spots->nextPageUrl()), {{ $spots->count() }}, {{ (int) $spotCount }})">
    {{-- Toolbar --}}
    <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-2 dark:border-slate-800 dark:bg-slate-950">
        <div class="flex min-w-0 items-center gap-3">
            <span class="shrink-0 text-sm text-gray-500 dark:text-slate-400">{{ number_format($spotCount) }} spots</span>

            @include('partials.spot-filters-active', ['compact' => true])
        </div>
    </div>

    {{-- Table (all breakpoints — rows control their own visibility) --}}
    <table class="w-full text-sm">
        <thead class="hidden border-b border-gray-200 bg-gray-50 dark:border-slate-800 dark:bg-slate-900 md:table-header-group">
            <tr>
                <th class="w-16 overflow-hidden px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Cat.</th>
                <th class="px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Title</th>
                <th class="w-28 px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Genre</th>
                <th class="w-32 px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Sender</th>
                <th class="w-32 px-3 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Age</th>
                <th class="w-20 px-3 py-1.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">Size</th>
                <th class="w-16 px-3 py-1.5 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">NZB</th>
            </tr>
        </thead>
        <tbody x-ref="rows" class="divide-y divide-gray-100 dark:divide-slate-800">
            @fragment('spot-rows')
                @forelse($spots as $spot)
                    @include('partials.spots-table-row', ['spot' => $spot])
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-12 text-center text-sm text-gray-400 dark:text-slate-500">
                            No spots found matching your filters.
                        </td>
                    </tr>
                @endforelse
            @endfragment
        </tbody>
    </table>

    <div x-ref="sentinel"
         class="flex min-h-16 items-center justify-center border-t border-gray-200 bg-white px-4 py-3 text-sm text-gray-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400"
         aria-live="polite">
        <div x-show="loading" class="flex items-center gap-2">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                <path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path>
            </svg>
            <span>Loading more spots…</span>
        </div>

        <button type="button"
                x-show="error"
                @click="loadMore"
                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
            Loading failed — retry
        </button>

        <button type="button"
                x-show="!automatic && nextUrl && !loading && !error"
                @click="loadMore"
                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
            Load more spots
        </button>

        <span x-show="finished" x-text="`${loadedCount.toLocaleString()} spots loaded`"></span>
    </div>

    {{-- Shared spot image preview (driven by Alpine.store spotPreview) --}}
    <div x-data
         x-show="$store.spotPreview.visible"
         :style="`position:fixed;left:${$store.spotPreview.mx+20}px;top:${Math.max(8,$store.spotPreview.my-260)}px;z-index:9999`"
         class="spot-preview pointer-events-none w-88 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
         style="display:none">
        <img :src="$store.spotPreview.src" class="w-full object-contain" alt="">
    </div>
</div>

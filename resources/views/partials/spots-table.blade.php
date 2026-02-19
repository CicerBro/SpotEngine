{{-- Toolbar --}}
<div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-2">
    <div class="flex items-center gap-3 min-w-0">
        <span class="text-sm text-gray-500 shrink-0">{{ number_format($spots->total()) }} spots</span>

        @include('partials.spot-filters-active', ['compact' => true])
    </div>

    <div class="flex items-center gap-3 shrink-0">
        {{-- Per page --}}
        <form method="GET" action="{{ route('home') }}">
            @foreach(array_filter(request()->query(), fn ($k) => !in_array($k, ['per_page', 'page']), ARRAY_FILTER_USE_KEY) as $key => $value)
                @if(is_array($value))
                    @foreach($value as $v)
                        <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                    @endforeach
                @else
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <select name="per_page"
                    onchange="this.form.submit()"
                    class="rounded-md border border-gray-200 bg-white px-2 py-1 text-sm text-gray-600 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                @foreach([25, 50, 100] as $perPage)
                    <option value="{{ $perPage }}" {{ (int) request('per_page', 50) === $perPage ? 'selected' : '' }}>
                        {{ $perPage }} / page
                    </option>
                @endforeach
            </select>
        </form>
    </div>
</div>

{{-- Table (all breakpoints — rows control their own visibility) --}}
<table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200 hidden md:table-header-group">
        <tr>
            <th class="w-20 px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Cat.</th>
            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Title</th>
            <th class="w-28 px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Genre</th>
            <th class="w-32 px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Sender</th>
            <th class="w-32 px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Age</th>
            <th class="w-20 px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Size</th>
            <th class="w-16 px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">NZB</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse($spots as $spot)
            @include('partials.spots-table-row', ['spot' => $spot])
        @empty
            <tr>
                <td colspan="7" class="px-3 py-12 text-center text-sm text-gray-400">
                    No spots found matching your filters.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- Pagination --}}
@if($spots->hasPages())
    <div class="border-t border-gray-200 bg-white px-4 py-3">
        {{ $spots->appends(request()->query())->links('vendor.pagination.tailwind') }}
    </div>
@endif

{{-- Shared spot image preview (driven by Alpine.store spotPreview) --}}
<div x-data
     x-show="$store.spotPreview.visible"
     :style="`position:fixed;left:${$store.spotPreview.mx+20}px;top:${Math.max(8,$store.spotPreview.my-260)}px;z-index:9999`"
     class="spot-preview pointer-events-none w-88 rounded-xl border border-gray-200 bg-white shadow-2xl overflow-hidden"
     style="display:none">
    <img :src="$store.spotPreview.src" class="w-full object-contain" alt="">
</div>

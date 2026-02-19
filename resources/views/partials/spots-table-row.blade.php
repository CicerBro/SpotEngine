@php
$rootCategory = $spot->root_category;
$badgeCategory = $spot->resolveBadgeCategory($categoriesByCode ?? collect());
$genreLabel = $spot->resolveGenreLabel($categoriesByCode ?? collect());
$rootColorVar = $rootCategory?->cssColorVar() ?? '--color-cat-image';
$rowBgClass = $rootCategory?->rowBackgroundClass() ?? 'hover:bg-gray-100/60';
@endphp

{{-- Desktop row --}}
<tr class="group transition-colors hidden md:table-row {{ $rowBgClass }}">
    <td class="px-3 py-1.5">
        @include('partials.category-badge', ['category' => $badgeCategory ?? $spot->category, 'rootCategory' => $rootCategory])
    </td>
    <td class="px-3 py-1.5 max-w-0 w-full" @mouseleave="$store.spotPreview.hide()">
        <div class="flex items-center gap-2">
            <a href="{{ route('spots.show', $spot) }}"
               class="truncate text-gray-900 hover:text-blue-600 font-medium transition-colors leading-snug focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 rounded"
               @mouseenter="$store.spotPreview.show('{{ route('spots.image', $spot) }}', $event.clientX, $event.clientY)"
               @mousemove="$store.spotPreview.move($event.clientX, $event.clientY)">
                {{ $spot->title }}
            </a>
            <button type="button"
                    class="opacity-0 group-hover:opacity-100 text-gray-300 hover:text-amber-400 transition-all shrink-0 focus:outline-none focus:opacity-100"
                    title="Add to watchlist">
                <svg class="h-[14px] w-[14px]" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M8 .25a.75.75 0 0 1 .673.418l1.882 3.815 4.21.612a.75.75 0 0 1 .416 1.279l-3.046 2.97.719 4.192a.751.751 0 0 1-1.088.791L8 12.347l-3.766 1.98a.75.75 0 0 1-1.088-.79l.72-4.194L.818 6.374a.75.75 0 0 1 .416-1.28l4.21-.611L7.327.668A.75.75 0 0 1 8 .25Z"/>
                </svg>
            </button>
        </div>
    </td>
    <td class="px-3 py-1.5 text-xs text-gray-400 truncate max-w-28">
        {{ $genreLabel ?? '—' }}
    </td>
    <td class="px-3 py-1.5 text-xs text-gray-400 truncate max-w-32">
        {{ $spot->sender }}
    </td>
    <td class="px-3 py-1.5 text-right text-xs text-gray-400 font-mono tabular-nums whitespace-nowrap">
        {{ $spot->age_formatted }}
    </td>
    <td class="px-3 py-1.5 text-right text-xs text-gray-500 font-mono tabular-nums whitespace-nowrap">
        {{ $spot->size_formatted }}
    </td>
    <td class="px-3 py-1.5 text-center">
        @auth
            <a href="{{ route('spots.nzb', $spot) }}"
               class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-600 hover:text-white transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                NZB
            </a>
        @else
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-400 cursor-not-allowed"
                  title="Login required">
                NZB
            </span>
        @endauth
    </td>
</tr>

{{-- Mobile card row --}}
<tr class="md:hidden">
    <td colspan="7" class="p-0">
        <div class="border-b border-gray-100 px-4 py-3 flex flex-col gap-1"
             style="border-left: 3px solid var({{ $rootColorVar }}); background-color: color-mix(in srgb, var({{ $rootColorVar }}) 5%, transparent)">
            <div class="flex items-start justify-between gap-2">
                @include('partials.category-badge', ['category' => $badgeCategory ?? $spot->category, 'rootCategory' => $rootCategory])
                <span class="text-xs text-gray-400 font-mono whitespace-nowrap">{{ $spot->age_formatted }}</span>
            </div>

            <a href="{{ route('spots.show', $spot) }}"
               class="text-sm font-medium text-gray-900 hover:text-blue-600 leading-snug transition-colors">
                {{ $spot->title }}
            </a>

            <div class="flex items-center justify-between gap-2 mt-0.5">
                <span class="text-xs text-gray-400 truncate">
                    {{ $genreLabel ?? ($spot->category?->name ?? '') }}{{ $spot->sender ? ' · '.$spot->sender : '' }}
                </span>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-xs text-gray-500 font-mono">{{ $spot->size_formatted }}</span>
                    @auth
                        <a href="{{ route('spots.nzb', $spot) }}"
                           class="text-xs px-2 py-0.5 bg-blue-600 text-white rounded font-medium hover:bg-blue-700 transition-colors">
                            NZB
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </td>
</tr>

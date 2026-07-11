@php
$rootCategory = $spot->root_category;
$badgeCategory = $spot->resolveBadgeCategory($categoriesByCode ?? collect());
$genreLabel = $spot->resolveGenreLabel($categoriesByCode ?? collect());
$rootColorVar = $rootCategory?->cssColorVar() ?? '--color-cat-image';
$rowBgClass = $rootCategory?->rowBackgroundClass() ?? 'hover:bg-gray-100/60';
$imageUrl = route('spots.image', ['spot' => $spot, 'v' => config('spotengine.cache.image_version')]);
@endphp

{{-- Desktop row --}}
<tr class="group hidden transition-colors md:table-row {{ $rowBgClass }}">
    <td class="w-16 overflow-hidden py-1 pl-3 pr-1">
        @include('partials.category-badge', ['category' => $badgeCategory ?? $spot->category, 'rootCategory' => $rootCategory])
    </td>
    <td class="max-w-0 w-full py-1 pl-1 pr-3" @mouseleave="$store.spotPreview.hide()">
        <a href="{{ route('spots.show', $spot) }}"
           class="block truncate rounded font-medium leading-tight text-gray-900 transition-colors hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:text-slate-100 dark:hover:text-blue-400 dark:focus:ring-offset-slate-950"
           @mouseenter="$store.spotPreview.show('{{ $imageUrl }}', $event.clientX, $event.clientY)"
           @mousemove="$store.spotPreview.move($event.clientX, $event.clientY)">
            {{ $spot->title }}
        </a>
    </td>
    <td class="max-w-28 truncate px-3 py-1 text-xs text-gray-400 dark:text-slate-500">
        {{ $genreLabel ?? '—' }}
    </td>
    <td class="max-w-32 truncate px-3 py-1 text-xs text-gray-400 dark:text-slate-500">
        {{ $spot->sender }}
    </td>
    <td class="whitespace-nowrap px-3 py-1 text-right font-mono text-xs tabular-nums text-gray-400 dark:text-slate-500">
        {{ $spot->age_formatted }}
    </td>
    <td class="whitespace-nowrap px-3 py-1 text-right font-mono text-xs tabular-nums text-gray-500 dark:text-slate-400">
        {{ $spot->size_formatted }}
    </td>
    <td class="px-3 py-1 text-center">
        @if($spot->has_nzb)
            @auth
                <a href="{{ route('spots.nzb', $spot) }}"
                   class="inline-flex items-center rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 transition-colors hover:bg-blue-600 hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-600 dark:hover:text-white dark:focus:ring-offset-slate-950">
                    NZB
                </a>
            @else
                <span class="inline-flex cursor-not-allowed items-center rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-400 dark:bg-slate-800 dark:text-slate-500"
                      title="Login required">
                    NZB
                </span>
            @endauth
        @endif
    </td>
</tr>

{{-- Mobile card row --}}
<tr class="md:hidden">
    <td colspan="7" class="p-0">
        <div class="flex flex-col gap-0.5 border-b border-gray-100 px-4 py-2 dark:border-slate-800"
             style="border-left: 3px solid var({{ $rootColorVar }}); background-color: color-mix(in srgb, var({{ $rootColorVar }}) 5%, transparent)">
            <div class="flex items-start justify-between gap-2">
                @include('partials.category-badge', ['category' => $badgeCategory ?? $spot->category, 'rootCategory' => $rootCategory])
                <span class="whitespace-nowrap font-mono text-xs text-gray-400 dark:text-slate-500">{{ $spot->age_formatted }}</span>
            </div>

            <a href="{{ route('spots.show', $spot) }}"
               class="text-sm font-medium leading-snug text-gray-900 transition-colors hover:text-blue-600 dark:text-slate-100 dark:hover:text-blue-400">
                {{ $spot->title }}
            </a>

            <div class="mt-0.5 flex items-center justify-between gap-2">
                <span class="truncate text-xs text-gray-400 dark:text-slate-500">
                    {{ $genreLabel ?? ($spot->category?->name ?? '') }}{{ $spot->sender ? ' · '.$spot->sender : '' }}
                </span>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="font-mono text-xs text-gray-500 dark:text-slate-400">{{ $spot->size_formatted }}</span>
                    @if($spot->has_nzb)
                        @auth
                            <a href="{{ route('spots.nzb', $spot) }}"
                               class="rounded bg-blue-600 px-2 py-0.5 text-xs font-medium text-white transition-colors hover:bg-blue-700">
                                NZB
                            </a>
                        @endauth
                    @endif
                </div>
            </div>
        </div>
    </td>
</tr>

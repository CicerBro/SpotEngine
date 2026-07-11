@php
$colorVar = $rootCategory?->cssColorVar() ?? '--badge-bitmap';
$textColor = ($rootCategory?->usesLightBadgeText() ?? false) ? '#000000' : '#ffffff';
$label = $category?->displayLabel() ?? '?';
@endphp

<span class="inline-block max-w-full truncate rounded px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide"
      style="background-color: var({{ $colorVar }}); color: {{ $textColor }}"
      title="{{ $label }}">
    {{ $label }}
</span>

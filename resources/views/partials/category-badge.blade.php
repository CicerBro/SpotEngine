@php
$colorVar = $rootCategory?->cssColorVar() ?? '--badge-bitmap';
$textColor = ($rootCategory?->usesLightBadgeText() ?? false) ? '#000000' : '#ffffff';
@endphp

<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold uppercase tracking-wide whitespace-nowrap shrink-0"
      style="background-color: var({{ $colorVar }}); color: {{ $textColor }}">
    {{ $category?->displayLabel() ?? '?' }}
</span>

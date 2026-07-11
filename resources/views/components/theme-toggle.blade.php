<button type="button"
        @click="$store.theme.toggle()"
        :aria-label="$store.theme.label"
        :title="$store.theme.label"
        {{ $attributes }}>
    <svg x-show="$store.theme.mode === 'light'" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
        <path d="M8 1.25a.75.75 0 0 1 .75.75v.5a.75.75 0 0 1-1.5 0V2A.75.75 0 0 1 8 1.25ZM8 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8Zm0 1.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Zm6.75 2.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1 0-1.5h.5a.75.75 0 0 1 .75.75ZM2.5 8.75H2a.75.75 0 0 1 0-1.5h.5a.75.75 0 0 1 0 1.5Zm9.743-5.993a.75.75 0 0 1 0 1.06l-.354.354a.75.75 0 0 1-1.06-1.06l.353-.354a.75.75 0 0 1 1.061 0ZM5.171 12.889l-.353.354a.75.75 0 0 1-1.061-1.06l.354-.354a.75.75 0 0 1 1.06 1.06Zm7.072.354a.75.75 0 0 1-1.06 0l-.354-.354a.75.75 0 0 1 1.06-1.06l.354.353a.75.75 0 0 1 0 1.061ZM5.171 3.111a.75.75 0 0 1-1.06 1.06l-.354-.353a.75.75 0 0 1 1.06-1.061l.354.354ZM8.75 13.5v.5a.75.75 0 0 1-1.5 0v-.5a.75.75 0 0 1 1.5 0Z"/>
    </svg>
    <svg x-show="$store.theme.mode === 'dark'" x-cloak class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
        <path d="M6.049 1.41a.75.75 0 0 1 .673 1.23A5.5 5.5 0 0 0 13.36 9.28a.75.75 0 0 1 1.23.673A6.75 6.75 0 1 1 6.049 1.41ZM4.78 3.236a5.25 5.25 0 1 0 7.984 7.984A7 7 0 0 1 4.78 3.236Z"/>
    </svg>
    <svg x-show="$store.theme.mode === 'system'" x-cloak class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
        <path d="M2 2.25A1.25 1.25 0 0 1 3.25 1h9.5A1.25 1.25 0 0 1 14 2.25v5A1.25 1.25 0 0 1 12.75 8.5h-9.5A1.25 1.25 0 0 1 2 7.25v-5ZM6.5 8.5h3v1.75h-3V8.5ZM4.75 10.25h6.5v1.5H4.75v-1.5Z"/>
    </svg>
</button>

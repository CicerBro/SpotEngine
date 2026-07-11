<nav class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
    <a href="{{ route('admin.index') }}"
       class="text-gray-500 transition-colors hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100 {{ request()->routeIs('admin.index') ? 'font-medium text-gray-900 dark:text-slate-100' : '' }}">
        Dashboard
    </a>
    <a href="{{ route('admin.users') }}"
       class="text-gray-500 transition-colors hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100 {{ request()->routeIs('admin.users*') ? 'font-medium text-gray-900 dark:text-slate-100' : '' }}">
        Users
    </a>
</nav>

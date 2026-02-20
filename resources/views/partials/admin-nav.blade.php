<nav class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
    <a href="{{ route('admin.index') }}"
       class="text-gray-500 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.index') ? 'font-medium text-gray-900' : '' }}">
        Dashboard
    </a>
    <a href="{{ route('admin.users') }}"
       class="text-gray-500 hover:text-gray-900 transition-colors {{ request()->routeIs('admin.users*') ? 'font-medium text-gray-900' : '' }}">
        Users
    </a>
</nav>

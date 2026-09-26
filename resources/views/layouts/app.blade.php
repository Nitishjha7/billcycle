<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BillCycle')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    @auth
    <div class="flex min-h-screen">
        <aside class="fixed inset-y-0 left-0 hidden w-64 flex-col bg-slate-900 text-slate-300 lg:flex">
            <div class="flex items-center gap-2 px-6 py-5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-500 text-sm font-bold text-white">B</span>
                <span class="text-lg font-semibold text-white">BillCycle</span>
            </div>

            <nav class="mt-4 flex-1 space-y-1 px-3">
                @php
                    $navItems = [
                        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
                        ['route' => 'customers.index', 'label' => 'Customers', 'icon' => 'users'],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @php $active = request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*'); @endphp
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition
                              {{ $active ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                        <x-nav-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-slate-800 p-4">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white">
                        <x-nav-icon name="logout" class="h-5 w-5 shrink-0" />
                        Log out
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex w-full flex-1 flex-col lg:pl-64">
            <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
                <span class="text-lg font-semibold">BillCycle</span>
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('dashboard') }}" class="text-slate-600 hover:text-slate-900">Dashboard</a>
                    <a href="{{ route('customers.index') }}" class="text-slate-600 hover:text-slate-900">Customers</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-600 hover:text-slate-900">Log out</button>
                    </form>
                </nav>
            </header>

            <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-10">
                @if (session('status'))
                    <div class="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-100">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
    @else
        <main class="mx-auto max-w-6xl px-4 py-8">
            @yield('content')
        </main>
    @endauth
</body>
</html>

@extends('layouts.app')

@section('title', 'Log in - BillCycle')

@section('content')
<div class="mx-auto mt-16 max-w-sm">
    <div class="mb-6 flex items-center justify-center gap-2">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-500 text-sm font-bold text-white">B</span>
        <h1 class="text-xl font-semibold text-slate-900">BillCycle</h1>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm">
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input type="password" name="password" id="password" required
                class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm">
        </div>
        <button type="submit" class="w-full rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
            Log in
        </button>
    </form>

    <p class="mt-4 text-center text-xs text-slate-500">Demo login: admin@billcycle.demo / password</p>
</div>
@endsection

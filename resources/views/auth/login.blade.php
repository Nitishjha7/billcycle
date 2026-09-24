@extends('layouts.app')

@section('title', 'Log in - BillCycle')

@section('content')
<div class="mx-auto mt-16 max-w-sm">
    <h1 class="mb-6 text-center text-xl font-semibold">BillCycle</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
        @csrf
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500 sm:text-sm">
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input type="password" name="password" id="password" required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500 sm:text-sm">
        </div>
        <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
            Log in
        </button>
    </form>

    <p class="mt-4 text-center text-xs text-gray-500">Demo login: admin@billcycle.demo / password</p>
</div>
@endsection

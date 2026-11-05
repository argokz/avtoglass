@extends('layouts.app')
@section('title', (app()->getLocale() === 'kk' ? 'Көмекші жады' : 'Память помощника').' — '.settings()->brandName())
@section('content')
    <div class="account-workspace mx-auto max-w-2xl py-6">
        <a href="{{ route('account.profile') }}" class="text-sm font-bold underline">{{ app()->getLocale() === 'kk' ? 'Профильге оралу' : 'Вернуться в профиль' }}</a>
        <livewire:assistant-memory />
    </div>
@endsection

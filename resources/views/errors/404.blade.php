@extends('errors.layout')

@section('code', '404')

@section('title', 'Halaman tidak ditemukan')

@section('message', $message ?? 'Halaman yang Anda cari tidak ditemukan. Periksa kembali alamat atau jelajahi destinasi yang tersedia.')

@section('actions')
    <a href="{{ url('/') }}" class="button button--primary">Kembali ke beranda</a>
    <a href="{{ url('/destinations') }}" class="button button--secondary">Jelajahi destinasi</a>
@endsection

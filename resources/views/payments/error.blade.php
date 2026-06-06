@extends('payments.layout')

@section('title', 'حدث خطأ')

@section('body')
    <div class="icon error">!</div>
    <h1>تعذر إتمام العملية</h1>
    <p>{{ $message ?? 'تعذر إتمام العملية. يرجى المحاولة لاحقاً.' }}</p>
@endsection

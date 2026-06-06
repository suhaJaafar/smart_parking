@extends('payments.layout')

@section('title', 'لم يكتمل الدفع')

@section('body')
    <div class="icon failed">&#10007;</div>
    <h1>لم يكتمل الدفع</h1>
    <p>حدثت مشكلة أثناء معالجة عملية الدفع.</p>

    <div class="details">
        <div class="row">
            <span class="label">المبلغ</span>
            <span class="value">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</span>
        </div>
        <div class="row">
            <span class="label">رقم العملية</span>
            <span class="value">{{ $payment->payment_id ?? $payment->request_id }}</span>
        </div>
    </div>

    <p class="hint">
        يمكنك المحاولة مرة أخرى من الرابط في واتساب، أو الدفع نقداً عند الخروج.
    </p>
@endsection

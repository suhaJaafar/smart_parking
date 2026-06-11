@extends('payments.layout')

@section('title', 'تم الدفع بنجاح')

@section('body')
    <div class="icon success">&#10003;</div>
    <h1>تم الدفع بنجاح</h1>
    <p>شكراً لاستخدامك Smart Parking.</p>

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
@endsection

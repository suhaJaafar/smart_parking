<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>وصل دفع — Smart Parking</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", "Tahoma", Arial, sans-serif;
            color: #111827;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .page {
            max-width: 460px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        .receipt {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            padding: 28px 24px 22px;
            text-align: center;
        }
        .header .badge {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            line-height: 1;
            margin-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }
        .header .brand {
            margin-top: 4px;
            font-size: 13px;
            opacity: 0.9;
            letter-spacing: 0.5px;
        }
        .amount {
            text-align: center;
            padding: 22px 24px 6px;
        }
        .amount .label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .amount .value {
            font-size: 30px;
            font-weight: 800;
            color: #059669;
            direction: ltr;
            unicode-bidi: embed;
        }
        .details {
            padding: 12px 24px 8px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px dashed #e5e7eb;
            font-size: 14px;
        }
        .row:last-child { border-bottom: none; }
        .row .label { color: #6b7280; white-space: nowrap; }
        .row .value {
            color: #111827;
            font-weight: 600;
            text-align: left;
            word-break: break-word;
        }
        .row .value.mono {
            direction: ltr;
            unicode-bidi: embed;
            font-size: 13px;
        }
        .footer {
            text-align: center;
            padding: 18px 24px 24px;
            color: #9ca3af;
            font-size: 12px;
            line-height: 1.7;
            border-top: 1px solid #f3f4f6;
        }
        .actions {
            max-width: 460px;
            margin: 18px auto 0;
            padding: 0 16px;
        }
        .btn {
            display: block;
            width: 100%;
            border: 0;
            cursor: pointer;
            padding: 14px 16px;
            border-radius: 12px;
            background: #111827;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            text-align: center;
        }
        .btn:active { opacity: 0.9; }
        .btn.secondary {
            margin-top: 10px;
            background: #ffffff;
            color: #111827;
            border: 1px solid #e5e7eb;
        }
        .hint {
            max-width: 460px;
            margin: 10px auto 0;
            padding: 0 16px;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            line-height: 1.7;
        }
        /* Full-screen image overlay used on iPhone/iPad, where in-app
           browsers ignore programmatic downloads: the customer long-presses
           the image and chooses "Save to Photos". */
        .overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            background: rgba(17, 24, 39, 0.94);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            padding: 20px;
            overflow: auto;
        }
        .overlay[hidden] { display: none; }
        .overlay .tip {
            margin: 0;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            line-height: 1.7;
        }
        .overlay img {
            max-width: 100%;
            border-radius: 12px;
            box-shadow: 0 10px 34px rgba(0, 0, 0, 0.45);
        }
        .overlay .close {
            border: 0;
            cursor: pointer;
            background: #ffffff;
            color: #111827;
            font-family: inherit;
            font-weight: 700;
            font-size: 14px;
            padding: 11px 22px;
            border-radius: 10px;
        }
        @media print {
            body { background: #ffffff; }
            .page { padding: 0; }
            .receipt { box-shadow: none; border-radius: 0; }
            .actions, .hint, .overlay { display: none; }
        }
    </style>
</head>
<body>
    @php
        $reserve  = $payment->reserve;
        $parkName = $reserve?->park?->name ?? '—';
        $customer = $reserve?->user?->name ?? 'عميل';
        $ref      = $payment->payment_id ?? $payment->request_id;
        $paidAt   = ($payment->paid_at ?? $payment->created_at)?->setTimezone(config('app.timezone'));
    @endphp

    <div class="page">
        <div class="receipt" id="receipt">
            <div class="header">
                <div class="badge">&#10003;</div>
                <h1>وصل دفع</h1>
                <div class="brand">Smart Parking</div>
            </div>

            <div class="amount">
                <div class="label">المبلغ المدفوع</div>
                <div class="value">{{ number_format((float) $payment->amount, 0) }} {{ $payment->currency }}</div>
            </div>

            <div class="details">
                <div class="row">
                    <span class="label">الموقف</span>
                    <span class="value">{{ $parkName }}</span>
                </div>
                <div class="row">
                    <span class="label">العميل</span>
                    <span class="value">{{ $customer }}</span>
                </div>
                <div class="row">
                    <span class="label">طريقة الدفع</span>
                    <span class="value">QiCard</span>
                </div>
                <div class="row">
                    <span class="label">الحالة</span>
                    <span class="value">مدفوع</span>
                </div>
                @if ($paidAt)
                    <div class="row">
                        <span class="label">التاريخ والوقت</span>
                        <span class="value mono">{{ $paidAt->format('Y-m-d H:i') }}</span>
                    </div>
                @endif
                <div class="row">
                    <span class="label">رقم العملية</span>
                    <span class="value mono">{{ $ref }}</span>
                </div>
            </div>

            <div class="footer">
                هذا الوصل دليل على إتمام الدفع.<br>
                شكراً لاستخدامك Smart Parking.
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn" id="downloadBtn">تحميل وصل الدفع</button>
        </div>
        <p class="hint" id="saveHint"></p>
    </div>

    <div class="overlay" id="overlay" hidden>
        <p class="tip">اضغط مطولاً على الوصل ثم اختر «حفظ الصورة» لتحميله</p>
        <img id="overlayImg" alt="وصل الدفع">
        <button type="button" class="close" id="overlayClose">إغلاق</button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        (function () {
            var btn = document.getElementById('downloadBtn');
            var overlay = document.getElementById('overlay');
            var overlayImg = document.getElementById('overlayImg');
            var hint = document.getElementById('saveHint');
            if (!btn) { return; }

            // iOS/iPadOS in-app browsers (Telegram, etc.) silently ignore
            // programmatic file downloads, so on those we present the image
            // for a long-press "Save to Photos" instead.
            var isApple = /iP(hone|od|ad)/.test(navigator.userAgent) ||
                (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

            if (isApple) {
                hint.textContent = 'بعد الضغط، احتفظ بالوصل في صورك عبر «حفظ الصورة».';
            }

            function closeOverlay() {
                overlay.hidden = true;
                overlayImg.removeAttribute('src');
            }
            document.getElementById('overlayClose').addEventListener('click', closeOverlay);

            btn.addEventListener('click', function () {
                if (!window.html2canvas) {
                    try { window.print(); } catch (e) {}
                    return;
                }

                var original = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'جارٍ التحضير…';

                html2canvas(document.getElementById('receipt'), {
                    scale: 2,
                    backgroundColor: '#ffffff',
                }).then(function (canvas) {
                    var dataUrl = canvas.toDataURL('image/png');

                    if (isApple) {
                        overlayImg.src = dataUrl;
                        overlay.hidden = false;
                    } else {
                        var a = document.createElement('a');
                        a.href = dataUrl;
                        a.download = 'Smart-Parking-Receipt.png';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                }).catch(function () {
                    try { window.print(); } catch (e) {}
                }).then(function () {
                    btn.disabled = false;
                    btn.textContent = original;
                });
            });
        })();
    </script>
</body>
</html>

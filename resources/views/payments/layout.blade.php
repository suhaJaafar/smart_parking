<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Smart Parking')</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", "Tahoma", Arial, sans-serif;
            color: #111827;
        }
        .wrap {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            padding: 32px 24px;
            text-align: center;
        }
        .icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 16px;
            color: #ffffff;
        }
        .icon.success { background: #10b981; }
        .icon.failed  { background: #ef4444; }
        .icon.error   { background: #ef4444; }
        .icon.pending { background: #f59e0b; }
        h1 {
            font-size: 22px;
            margin: 0 0 8px;
            font-weight: 700;
        }
        p {
            margin: 8px 0;
            color: #4b5563;
            line-height: 1.6;
        }
        .details {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            text-align: right;
        }
        .row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .row .label { color: #6b7280; }
        .row .value {
            color: #111827;
            font-weight: 600;
            direction: ltr;
            unicode-bidi: embed;
        }
        .hint {
            margin-top: 16px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 13px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            @yield('body')
        </div>
    </div>
</body>
</html>

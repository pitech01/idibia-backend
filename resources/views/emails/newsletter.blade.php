<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #334155;
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .header {
            background: #0f172a;
            color: #ffffff;
            padding: 40px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: 1px;
        }
        .content {
            padding: 40px;
            white-space: pre-wrap;
        }
        .footer {
            padding: 30px;
            background: #f1f5f9;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
        .footer p {
            margin: 5px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>IDIBIA HEALTH</h1>
        </div>
        <div class="content">
            <h2 style="color: #0f172a; margin-top: 0;">{{ $subject }}</h2>
            <div>
                {!! nl2br(e($message)) !!}
            </div>
            
            <a href="{{ config('app.frontend_url') }}" class="btn">Visit Website</a>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} IDIBIA Med. All rights reserved.</p>
            <p>You are receiving this because you subscribed to our newsletter.</p>
            <p><a href="{{ config('app.frontend_url') }}/unsubscribe" style="color: #64748b;">Unsubscribe</a></p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MotoSync Stock Alert {{ now()->format('Y-m-d') }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { color: #20232a; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 20px; }
        main, nav { max-width: 620px; margin: auto; }
        main { background: white; border-top: 6px solid #ff6500; padding: 24px; overflow-wrap: anywhere; }
        nav { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
        button { background: #6234a4; color: white; border: 0; border-radius: 8px; padding: 12px 16px; font: inherit; }
        h1, h2 { break-after: avoid; }
        @media print {
            body, main { padding: 0; background: white; }
            main { max-width: none; }
            nav { display: none; }
        }
    </style>
</head>
<body>
    <nav aria-label="Report actions">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ route('admin.low-stocks') }}">Back to Low Stock Alerts</a>
    </nav>
    <main>
        @include('emails.inventory-alert-content')
    </main>
</body>
</html>

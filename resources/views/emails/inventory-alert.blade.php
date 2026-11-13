<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;color:#20232a;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e2e5ea;border-radius:12px;overflow:hidden;">
                <tr><td style="height:6px;background:#ff6500;"></td></tr>
                <tr>
                    <td style="padding:28px;">
                        @include('emails.inventory-alert-content')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

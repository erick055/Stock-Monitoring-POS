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
                        @php
                            $sectionHeadings = ['Priority overview', 'Products to review', 'Recommended next steps', 'Recommended action'];
                        @endphp
                        @foreach(explode("\n", $bodyText) as $index => $line)
                            @if($index === 0)
                                <h1 style="margin:0 0 20px;font-size:24px;line-height:1.25;color:#111318;font-weight:700;">{{ $line }}</h1>
                            @elseif(trim($line) === '')
                                <div style="height:8px;line-height:8px;">&nbsp;</div>
                            @elseif(in_array($line, $sectionHeadings, true))
                                <h2 style="margin:12px 0 8px;font-size:16px;line-height:1.4;color:#111318;font-weight:700;">{{ $line }}</h2>
                            @elseif(str_contains($line, ' | '))
                                <p style="margin:7px 0;font-size:14px;line-height:1.55;">
                                    @foreach(explode(' | ', $line) as $partIndex => $part)
                                        @if($partIndex > 0) <span style="color:#8b909a;"> | </span> @endif
                                        @if(preg_match('/^([A-Za-z ]+:)\s*(.*)$/u', $part, $itemParts))
                                            <strong style="font-weight:700;color:#111318;">{{ $itemParts[1] }}</strong> {{ $itemParts[2] }}
                                        @else
                                            <strong style="font-weight:700;color:#111318;">{{ $part }}</strong>
                                        @endif
                                    @endforeach
                                </p>
                            @elseif(preg_match('/^([A-Za-z][A-Za-z ]{0,49}:)\s*(.*)$/u', $line, $parts))
                                <p style="margin:7px 0;font-size:14px;line-height:1.55;"><strong style="font-weight:700;color:#111318;">{{ $parts[1] }}</strong> {{ $parts[2] }}</p>
                            @else
                                <p style="margin:7px 0;font-size:14px;line-height:1.55;">{{ $line }}</p>
                            @endif
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

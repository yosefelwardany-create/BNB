{{--
    The shared frame for every document this platform produces.

    Deliberately plain and deliberately printable: a statement an owner cannot
    read on paper is a statement they telephone about. Everything is inline CSS
    because dompdf's stylesheet support is narrower than a browser's, and a
    layout that silently degrades in the renderer is worse than a plain one that
    does not.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22mm 18mm; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #111827;
            line-height: 1.45;
        }

        h1 { font-size: 17pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 7mm 0 2mm; }

        .muted { color: #6b7280; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        .strong { font-weight: bold; }

        .header { border-bottom: 1.5pt solid #111827; padding-bottom: 4mm; margin-bottom: 6mm; }
        .header td { vertical-align: top; }

        table { width: 100%; border-collapse: collapse; }

        table.lines th {
            text-align: left;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            border-bottom: 0.7pt solid #d1d5db;
            padding: 1.6mm 2mm 1.6mm 0;
        }

        table.lines td {
            padding: 1.6mm 2mm 1.6mm 0;
            border-bottom: 0.4pt solid #e5e7eb;
        }

        table.lines tr.total td {
            border-top: 1pt solid #111827;
            border-bottom: none;
            font-weight: bold;
            padding-top: 2.4mm;
        }

        /* Reserved for things the reader must not miss: that a payment was
           simulated, that a document is a draft. Never decoration. */
        .notice {
            border: 0.8pt solid #b45309;
            background: #fffbeb;
            color: #7c2d12;
            padding: 3mm;
            margin-bottom: 5mm;
            font-size: 9pt;
        }

        .footer {
            margin-top: 9mm;
            padding-top: 3mm;
            border-top: 0.4pt solid #e5e7eb;
            color: #6b7280;
            font-size: 8pt;
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $heading }}</h1>
                @isset($subheading)
                    <div class="muted">{{ $subheading }}</div>
                @endisset
                @isset($reference)
                    <div class="small muted">Reference {{ $reference }}</div>
                @endisset
            </td>
            <td class="right">
                <div class="strong">{{ $organization->legal_name ?? $organization->name }}</div>
                @if ($organization->address_line_1 ?? null)
                    <div class="small muted">{{ $organization->address_line_1 }}</div>
                @endif
                @if ($organization->tax_identifier)
                    <div class="small muted">Tax ID {{ $organization->tax_identifier }}</div>
                @endif
                @if ($organization->contact_email)
                    <div class="small muted">{{ $organization->contact_email }}</div>
                @endif
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="footer">
        Produced {{ $producedAt }} by {{ $organization->name }}.
        @yield('footer')
    </div>
</body>
</html>

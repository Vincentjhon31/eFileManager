<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Routing slip — {{ $document->tracking_no }}</title>

    {{--
        Styles are inline rather than compiled through Vite. A routing slip has
        to print identically from any machine in the hall, including the one in
        the corner running whatever browser it shipped with, and it has to keep
        printing if the asset build is ever missing. Nothing here depends on the
        rest of the application.
    --}}
    <style>
        @page { size: A5 portrait; margin: 10mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f1f5f9;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #0f172a;
        }

        .sheet {
            width: 148mm;
            min-height: 210mm;
            margin: 0 auto;
            padding: 10mm;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .2);
        }

        .toolbar {
            width: 148mm;
            margin: 0 auto 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            font-size: 9pt;
            color: #475569;
        }

        .toolbar button, .toolbar a {
            font: inherit;
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar button { background: #1d4ed8; border-color: #1d4ed8; color: #fff; font-weight: 600; }

        header { text-align: center; line-height: 1.35; }
        header .republic { font-size: 8.5pt; letter-spacing: .02em; }
        header .lgu { font-size: 12pt; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }

        .title {
            margin: 10px 0 0;
            text-align: center;
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .identity { display: flex; gap: 10px; align-items: flex-start; margin-top: 10px; }
        .identity .facts { flex: 1 1 auto; }
        .identity .qr { flex: 0 0 auto; text-align: center; }
        .identity .qr svg { width: 30mm; height: 30mm; display: block; }
        .identity .qr .caption { margin-top: 2px; font-size: 6.5pt; color: #475569; line-height: 1.2; }

        .tracking {
            font-family: "Consolas", "Courier New", monospace;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: .04em;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #94a3b8; padding: 3px 5px; vertical-align: top; }
        th { background: #e2e8f0; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .04em; text-align: left; }

        .facts-table th { width: 26mm; background: transparent; border: none; padding: 1.5px 0; font-size: 8pt; color: #475569; text-transform: none; letter-spacing: 0; }
        .facts-table td { border: none; padding: 1.5px 0 1.5px 6px; font-size: 9pt; }

        .section { margin-top: 10px; }
        .section h2 { margin: 0 0 4px; font-size: 8pt; text-transform: uppercase; letter-spacing: .08em; color: #334155; }

        .routing-table td { height: 11mm; font-size: 8.5pt; }
        .routing-table .seq { width: 7mm; text-align: center; color: #64748b; }
        .routing-table .sig { width: 34mm; }
        .muted { color: #64748b; }
        .paper-note { font-size: 7pt; color: #b45309; }

        footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #cbd5e1;
            font-size: 7pt;
            color: #64748b;
            line-height: 1.4;
        }

        @media print {
            body { background: #fff; padding: 0; font-size: 9.5pt; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <span>Print on A5. If your printer only has A4, choose “fit to page”.</span>
    <span>
        <a href="{{ route('documents.show', $document) }}">Back to the document</a>
        <button type="button" onclick="window.print()">Print</button>
    </span>
</div>

<div class="sheet">

    <header>
        <div class="republic">Republic of the Philippines</div>
        <div class="republic">Province of {{ config('lgu.province') }}</div>
        <div class="lgu">{{ config('lgu.name') }}</div>
    </header>

    <p class="title">Document Routing Slip</p>

    <div class="identity">
        <div class="facts">
            <div class="tracking">{{ $document->tracking_no }}</div>

            <table class="facts-table">
                <tr>
                    <th scope="row">Subject</th>
                    <td><strong>{{ $document->subject }}</strong></td>
                </tr>
                <tr>
                    <th scope="row">Kind</th>
                    <td>
                        {{ $document->type?->name }}
                        @if ($document->reference_no) · {{ $document->reference_no }} @endif
                    </td>
                </tr>
                <tr>
                    <th scope="row">From</th>
                    <td>{{ $document->originLabel() }}</td>
                </tr>
                <tr>
                    <th scope="row">Registered</th>
                    <td>
                        {{ ph_datetime($document->created_at) }},
                        {{ $document->registeringDepartment?->displayName() }}
                    </td>
                </tr>
                @if ($document->due_at)
                    <tr>
                        <th scope="row">Deadline</th>
                        <td><strong>{{ ph_datetime($document->due_at) }}</strong></td>
                    </tr>
                @endif
                @if ($document->isConfidential())
                    <tr>
                        <th scope="row">Handling</th>
                        <td><strong>CONFIDENTIAL</strong> — restricted to the office head and the holder</td>
                    </tr>
                @endif
            </table>
        </div>

        <div class="qr">
            {!! $qr !!}
            <div class="caption">Scan to receive<br>or to see where it is</div>
        </div>
    </div>

    <div class="section">
        <h2>Routing and receipt</h2>

        <table class="routing-table">
            <thead>
                <tr>
                    <th class="seq">#</th>
                    <th>To</th>
                    <th>Action requested</th>
                    <th>Released</th>
                    <th class="sig">Received by / date / signature</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($document->routes as $leg)
                    <tr>
                        <td class="seq">{{ $leg->seq }}</td>
                        <td>
                            {{ $leg->toDepartment?->displayName() }}
                            @if ($leg->is_return)<br><span class="muted">(returned)</span>@endif
                        </td>
                        <td>{{ $leg->action_requested?->label() }}</td>
                        <td>{{ ph_date($leg->sent_at, 'd M') }}</td>
                        <td>
                            @if ($leg->received_at)
                                {{ $leg->received_by_name }}<br>
                                <span class="muted">{{ ph_datetime($leg->received_at, 'd M Y, g:i A') }}</span>
                                @unless ($leg->receipt_method?->isWitnessed())
                                    <br><span class="paper-note">signed on paper</span>
                                @endunless
                            @endif
                        </td>
                    </tr>
                @endforeach

                {{-- Room for the next few hand-offs, so the slip travels with
                     the document instead of being reprinted at every desk. --}}
                @for ($i = 0; $i < $blankRows; $i++)
                    <tr>
                        <td class="seq">{{ $document->routes->count() + $i + 1 }}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="sig"></td>
                    </tr>
                @endfor
            </tbody>
        </table>
    </div>

    <footer>
        Keep this slip with the document. Anyone receiving it should sign above and,
        where the receiving office uses the system, scan the code to record the time.
        A receipt recorded in the system is never edited afterwards.
        <br>
        Printed {{ ph_datetime(now()) }} · {{ config('app.name') }}
    </footer>
</div>

</body>
</html>

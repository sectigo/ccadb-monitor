<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CCADB Monitor - CA Owner: {{ config('app.ca_owner') }}</title>
    <meta http-equiv="refresh" content="30">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #0b1220;
            --panel: #0f1a2e;
            --panel2: #0c1629;
            --text: #e7edf7;
            --muted: #a7b3c6;
            --line: rgba(255,255,255,0.08);
            --ok: rgba(74, 222, 128, 0.18);
            --bad: rgba(248, 113, 113, 0.18);
            --okText: #4ade80;
            --badText: #f87171;
            --chip: rgba(255,255,255,0.08);
            --btn: rgba(255,255,255,0.10);
            --btnHover: rgba(255,255,255,0.16);
            --shadow: 0 10px 30px rgba(0,0,0,0.40);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
            background: radial-gradient(1200px 600px at 20% 0%, #142549 0%, var(--bg) 50%);
            color: var(--text);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(11, 18, 32, 0.75);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        .topbar-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand { font-weight: 800; letter-spacing: 0.2px; }
        .meta {
            margin-left: auto;
            display: flex;
            gap: 12px;
            align-items: center;
            color: var(--muted);
            font-size: 12px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 14px;
        }
        .card {
            background: linear-gradient(180deg, var(--panel) 0%, var(--panel2) 100%);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 14px;
        }
        .card h3 {
            margin: 0 0 6px 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .card .value {
            font-size: 26px;
            font-weight: 800;
            line-height: 1.1;
        }
        .card .sub {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        .card.good {
            border-color: rgba(74,222,128,0.25);
            box-shadow: 0 10px 30px rgba(0,0,0,0.40), 0 0 0 1px rgba(74,222,128,0.10) inset;
        }
        .card.bad {
            border-color: rgba(248,113,113,0.30);
            box-shadow: 0 10px 30px rgba(0,0,0,0.40), 0 0 0 1px rgba(248,113,113,0.10) inset;
        }
        .kpi-title.good { color: var(--okText); }
        .kpi-title.bad  { color: var(--badText); }
        .card.kpi {
            background: linear-gradient(180deg, var(--panel) 0%, var(--panel2) 100%);
            position: relative;
        }

        .card.kpi .recheck-btn {
            position: absolute;
            top: 12px;
            right: 14px;

            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.03em;

            padding: 4px 10px;
            border-radius: 999px;

            color: #f8fafc;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);

            text-decoration: none;
            opacity: 0.85;
        }

        .card.kpi .recheck-btn:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
        }


        /* KPI OK = green background */
        .card.kpi.good {
            background: linear-gradient(
                180deg,
                rgba(74, 222, 128, 0.22) 0%,
                rgba(74, 222, 128, 0.10) 100%
            );
            border-color: rgba(74, 222, 128, 0.35);
        }

        /* KPI BAD = red background */
        .card.kpi.bad {
            background: linear-gradient(
                180deg,
                rgba(248, 113, 113, 0.30) 0%,
                rgba(248, 113, 113, 0.14) 100%
            );
            border-color: rgba(248, 113, 113, 0.45);
        }
        .controls {
            margin-top: 14px;
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .search {
            flex: 1;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            color: var(--text);
            outline: none;
        }
        .search::placeholder { color: rgba(231,237,247,0.45); }

        .btn {
            background: var(--btn);
            border: 1px solid var(--line);
            color: var(--text);
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 750;
        }
        .btn:hover { background: var(--btnHover); }

        .notice {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 650;

            display: flex;
            align-items: center;
            gap: 10px;

            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .notice.success {
            background: linear-gradient(
                180deg,
                rgba(74, 222, 128, 0.22),
                rgba(74, 222, 128, 0.12)
            );
            border-color: rgba(74, 222, 128, 0.35);
            color: var(--okText);
        }


        table {
            margin-top: 12px;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
        }
        thead th {
            text-align: left;
            font-size: 12px;
            color: var(--muted);
            font-weight: 800;
            padding: 12px 12px;
            border-bottom: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
        }
        tbody td {
            padding: 12px 12px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        tbody tr:hover td { background: rgba(255,255,255,0.02); }
        tbody tr:last-child td { border-bottom: none; }

        .host { font-weight: 850; letter-spacing: 0.2px; }
        .muted { color: var(--muted); font-size: 12px; margin-top: 3px; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 850;
            border: 1px solid var(--line);
            background: var(--chip);
            white-space: nowrap;
        }
        .badge.ok { background: var(--ok); color: var(--okText); border-color: rgba(74,222,128,0.25); }
        .badge.bad { background: var(--bad); color: var(--badText); border-color: rgba(248,113,113,0.25); }

        .kv {
            margin-top: 8px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
        }
        .kv div span { color: var(--text); font-weight: 650; }
        .kv code {
            color: var(--text);
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 2px 6px;
        }

        .actions form { display: inline; margin: 0; }
        .actions .btn { padding: 8px 10px; border-radius: 10px; font-size: 12px; }

        @media (max-width: 980px) {
            .grid { grid-template-columns: repeat(2, 1fr); }
            .controls { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 640px) {
            .grid { grid-template-columns: 1fr; }
            thead { display:none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tbody td { border-bottom: 1px solid var(--line); }
            tbody tr { border-bottom: 1px solid var(--line); }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-inner">
        <div class="brand">CCADB Monitor - CA Owner: {{ config('app.ca_owner') }}</div>
        <div class="meta">
            <div>Now: <strong>{{ \Carbon\Carbon::now('UTC')->format('Y-m-d H:i:s') }} UTC</strong></div>
        </div>
    </div>
</div>

<div class="container">
    @if (session('status'))
        <div class="notice success">
            {{ session('status') }}
        </div>
    @endif
    <div class="grid">
        <div class="card kpi @if($totalCaRecords > 0 && $ccadbLastRefresh !== 'N/A' && \Carbon\Carbon::parse($ccadbLastRefresh)->isAfter(\Carbon\Carbon::now()->subDay())) good @else bad @endif">
            <a href="/recheck/ccadb" class="recheck-btn">
                Recheck
            </a>
            <h3>Total CA Records</h3>
            <div class="value">{{$totalCaRecords}}</div>
            <div class="sub">Last Refresh: {{ $ccadbLastRefresh }}</div>
        </div>

        <div class="card kpi @if($cpsErrors > 0) bad @else good @endif">
            <a href="/recheck/cps" class="recheck-btn">
                Recheck
            </a>
            <h3>CPS Errors detected</h3>
            <div class="value">{{$cpsErrors}}</div>
            <div class="sub">Last Run: {{ $cpsUrlLastCheck }}</div>
        </div>

        <div class="card kpi @if($crlErrors > 0) bad @else good @endif">
            <a href="/recheck/crl" class="recheck-btn">
                Recheck
            </a>
            <h3>CRL Errors detected</h3>
            <div class="value">{{$crlErrors}}</div>
            <div class="sub">Last Run: {{ $crlUrlLastCheck }}</div>
        </div>


    </div>


    <div class="controls">
        <input id="q" class="search" placeholder="Filter (substring match)…" oninput="filterRows()">
    </div>

    <table id="tbl">
        <thead>
        <tr>
            <th style="width: 22%;">Certificate Name</th>
            <th style="width: 24%;">Error</th>
            <th style="width: 24%;">URL</th>
            <th style="width: 24%;">Last Checked</th>
        </tr>
        </thead>
        <tbody>
            @foreach($errors as $issue)
                <tr class="row" data-ca="{{ $issue->certificate_name }}">
                    <td>
                        <div class="ca">{{ $issue->certificate_name }}</div>
                        <div class="muted">{{ $issue->ccadb_record_id }}</div>
                    </td>
                    <td>
                        <div class="badge bad">{{ $issue->issue_type }}</div>
                        <div class="muted">{{ $issue->error }}</div>
                    </td>
                    <td>
                        <div>{{ $issue->url }}</div>
                    </td>
                    <td>
                        <div>{{ $issue->last_detected_at }}</div>

                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

</div>

<script>
    function filterRows() {
        const q = (document.getElementById('q').value || '').toLowerCase();
        const rows = document.querySelectorAll('#tbl tbody tr.row');
        for (const r of rows) {
            const ca = (r.getAttribute('data-ca') || '').toLowerCase();
            r.style.display = ca.includes(q) ? '' : 'none';
        }
    }

        setTimeout(() => {
        const n = document.querySelector('.notice');
        if (n) n.style.display = 'none';
    }, 10000);

</script>

</body>
</html>

<style>
    @page { margin: 28mm 18mm 22mm 18mm; }
    * { box-sizing: border-box; }
    body {
        font-family: "DejaVu Sans", sans-serif;
        color: #0f172a;
        font-size: 10.5px;
        line-height: 1.45;
        margin: 0;
    }
    .accent { color: #0f172a; }
    .muted { color: #64748b; }
    .small { font-size: 9px; }
    .xsmall { font-size: 8.5px; }
    .upper { text-transform: uppercase; letter-spacing: 0.06em; }
    .b { font-weight: 700; }
    .right { text-align: right; }
    .center { text-align: center; }
    .pad-top-12 { padding-top: 12px; }
    .pad-top-20 { padding-top: 20px; }

    /* Header band */
    table.brand {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 22px;
        border-bottom: none;
        padding-bottom: 12px;
    }
    table.brand td { vertical-align: top; padding: 0; }
    .brand .logo-cell { width: 60%; }
    .brand .doc-cell { width: 40%; text-align: right; }
    .brand h1 {
        margin: 0;
        font-size: 26px;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 800;
    }
    .brand .doc-meta {
        margin-top: 6px;
        font-size: 10px;
        color: #475569;
    }
    .brand .doc-meta .label { color: #64748b; }
    .brand .company-name {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin-top: 2px;
    }
    .brand .company-line { color: #475569; font-size: 10px; }

    /* Address blocks */
    table.parties {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 18px;
    }
    table.parties td {
        vertical-align: top;
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        width: 50%;
    }
    table.parties td.spacer { width: 12px; background: transparent; border: none; padding: 0; }
    .parties .label { color: #475569; font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 4px; }
    .parties .name { font-weight: 700; font-size: 11.5px; color: #0f172a; margin-bottom: 2px; }
    .parties p { margin: 0 0 1px; }

    /* Meta strip */
    table.meta-strip {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
    }
    .meta-strip td {
        padding: 10px 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        font-size: 9.5px;
        color: #334155;
    }
    .meta-strip .key { color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; font-size: 8.5px; }
    .meta-strip .val { color: #0f172a; font-weight: 700; font-size: 11px; }

    /* Line items */
    table.lines {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    table.lines th {
        background: #0f172a;
        color: #f8fafc;
        text-align: left;
        font-size: 9px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        padding: 9px 10px;
        font-weight: 600;
    }
    table.lines th.num { text-align: right; }
    table.lines td {
        padding: 9px 10px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }
    table.lines td.num { text-align: right; white-space: nowrap; }
    table.lines tr.zebra td { background: #f8fafc; }

    /* Totals */
    table.totals {
        width: 45%;
        margin-left: auto;
        border-collapse: collapse;
        margin-top: 6px;
    }
    .totals td { padding: 6px 0; font-size: 10.5px; }
    .totals .label { color: #475569; }
    .totals .value { text-align: right; font-variant-numeric: tabular-nums; }
    .totals .grand td {
        border-top: 1.5px solid #0f172a;
        padding-top: 9px;
        margin-top: 4px;
        font-weight: 800;
        font-size: 13px;
        color: #0f172a;
    }
    .totals .grand .value { color: #0f172a; }

    /* Sections */
    .section {
        margin-top: 18px;
        padding: 12px 14px;
        background: #f8fafc;
        border-left: none;
    }
    .section h3 {
        margin: 0 0 6px;
        font-size: 9.5px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #475569;
    }
    .section p { margin: 0 0 4px; color: #334155; font-size: 10px; }

    /* Footer */
    .footer {
        margin-top: 22px;
        border-top: 1px solid #e2e8f0;
        padding-top: 8px;
        color: #94a3b8;
        font-size: 8.5px;
        text-align: center;
    }

    /* Status pill (e.g. PAID / OVERDUE) */
    .pill {
        display: inline-block;
        padding: 3px 10px;
        font-size: 9px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        font-weight: 700;
        border-radius: 999px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    .pill.warn { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .pill.danger { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
</style>

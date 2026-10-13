<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    body { font-size: 9.5px; }
    .report-title {
        margin: 0;
        font-size: 20px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-weight: 800;
        color: #0f172a;
    }
    .report-subtitle {
        margin: 4px 0 0;
        color: #64748b;
        font-size: 10px;
    }
    table.summary {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px 0;
        margin: 4px 0 16px;
    }
    table.summary td {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 10px 12px;
        vertical-align: top;
    }
    table.summary .label {
        color: #64748b;
        font-size: 8px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 3px;
    }
    table.summary .value {
        color: #0f172a;
        font-size: 14px;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    .section-heading {
        margin: 16px 0 8px;
        padding-bottom: 4px;
        border-bottom: 1.5px solid #0f172a;
        font-size: 11px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-weight: 800;
        color: #0f172a;
        page-break-after: avoid;
    }
    table.sheet {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    table.sheet th {
        background: #0f172a;
        color: #f8fafc;
        text-align: left;
        font-size: 8px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding: 7px 6px;
        font-weight: 600;
    }
    table.sheet th.num,
    table.sheet td.num {
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    table.sheet td {
        padding: 6px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
        font-size: 9px;
        color: #0f172a;
    }
    table.sheet tr.zebra td { background: #f8fafc; }
    table.sheet tr.total td {
        border-top: 1.5px solid #0f172a;
        border-bottom: none;
        font-weight: 800;
        padding-top: 8px;
    }
    table.sheet tr.grand td {
        border-top: 2px solid #0f172a;
        font-weight: 800;
        font-size: 10px;
    }
    .muted { color: #64748b; }
    .empty-note {
        margin: 8px 0 16px;
        padding: 16px;
        border: 1px dashed #cbd5e1;
        color: #64748b;
        text-align: center;
    }
    .disclaimer {
        margin-top: 18px;
        padding-top: 8px;
        border-top: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 8px;
        line-height: 1.45;
    }
    .client-block { margin: 0 0 14px; page-break-inside: avoid; }
    .client-name { font-weight: 800; font-size: 11px; margin: 0 0 4px; }
</style>

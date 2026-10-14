<style>
    @page { margin: 10mm 10mm 12mm 10mm; }
    * { box-sizing: border-box; }
    body {
        font-family: "DejaVu Sans", sans-serif;
        color: #0f172a;
        font-size: 8.5px;
        line-height: 1.3;
        margin: 0;
    }
    table.report-head {
        width: 100%;
        border-collapse: collapse;
        margin: 0 0 12px;
    }
    td.report-id,
    td.report-head-meta {
        vertical-align: top;
        padding: 0 0 10px;
        border-bottom: 1.5px solid #0f172a;
    }
    td.report-head-meta {
        width: 48%;
        text-align: right;
    }
    .report-logo {
        display: block;
        max-width: 168px;
        max-height: 44px;
        margin: 0 0 6px;
    }
    .report-business {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .report-letterhead {
        color: #475569;
        font-size: 9px;
        line-height: 1.35;
    }
    .report-title {
        margin: 0;
        font-size: 18px;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #0f172a;
        line-height: 1.15;
    }
    .report-subtitle {
        margin: 3px 0 0;
        color: #64748b;
        font-size: 9px;
    }
    table.doc-meta {
        margin: 8px 0 0 auto;
        border-collapse: collapse;
        font-size: 9px;
    }
    table.doc-meta td {
        padding: 1px 0;
        border: none;
        vertical-align: top;
        line-height: 1.35;
    }
    table.doc-meta td.key {
        color: #64748b;
        text-align: right;
        white-space: nowrap;
        padding-right: 8px;
    }
    table.doc-meta td.val {
        color: #0f172a;
        text-align: left;
        font-weight: 600;
    }
    .report-note {
        margin: 8px 0 0;
        color: #475569;
        font-size: 9px;
    }
    table.summary {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin: 6px 0 8px;
    }
    table.summary td {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 3px 6px;
        vertical-align: top;
    }
    table.summary .label {
        color: #64748b;
        font-size: 7px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    table.summary .value {
        color: #0f172a;
        font-size: 11px;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    h2.section-heading {
        margin: 8px 0 3px;
        padding: 0;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #0f172a;
        page-break-after: avoid;
    }
    table.sheet {
        width: 100%;
        border-collapse: collapse;
        margin: 0 0 4px;
    }
    table.sheet thead { display: table-header-group; }
    table.sheet th {
        background: #0f172a;
        color: #f8fafc;
        text-align: left;
        font-size: 7.5px;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        padding: 3px 4px;
        font-weight: 600;
    }
    table.sheet th.num,
    table.sheet td.num {
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    table.sheet td {
        padding: 2px 4px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
        font-size: 8.5px;
        color: #0f172a;
    }
    table.sheet tr { page-break-inside: avoid; }
    table.sheet tr.group { page-break-after: avoid; }
    table.sheet tr.zebra td { background: #f8fafc; }
    table.sheet tr.group td {
        background: #f1f5f9;
        font-weight: 700;
        border-bottom: 1px solid #cbd5e1;
        padding-top: 4px;
    }
    table.sheet tr.total td,
    table.sheet tr.grand td {
        font-weight: 800;
        border-bottom: none;
        page-break-before: avoid;
    }
    table.sheet tr.total td { border-top: 1px solid #0f172a; }
    table.sheet tr.grand td {
        border-top: 1.5px solid #0f172a;
        font-size: 9px;
    }
    table.sheet td.num.neg { color: #b91c1c; }
    td.details { word-wrap: break-word; }
    .muted { color: #64748b; }
    .sub {
        color: #64748b;
        font-size: 7.5px;
        line-height: 1.25;
    }
    .empty-note {
        margin: 4px 0 8px;
        padding: 6px;
        border: 1px dashed #cbd5e1;
        color: #64748b;
        text-align: center;
    }
    .disclaimer {
        margin: 8px 0 0;
        padding-top: 4px;
        border-top: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 7.5px;
        line-height: 1.35;
    }
</style>

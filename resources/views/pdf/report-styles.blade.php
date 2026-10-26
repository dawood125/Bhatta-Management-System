{{-- Shared look of the report PDFs (mPDF). Urdu pages use a larger size: Lateef runs small. --}}
<style>
    body { font-size: {{ $rtl ? '12.5pt' : '10pt' }}; color: #1c1917; }
    .num { font-family: dejavusans; font-size: {{ $rtl ? '9.5pt' : 'inherit' }}; }
    .muted { color: #78716c; }
    table { border-collapse: collapse; width: 100%; }
    .rows td { padding: 5px 4px; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
    .rows .head td { background: #f5f5f4; font-weight: bold; }
    .rows .total td { font-weight: bold; border-top: 1.5px solid #1c1917; }
    .rows .indent { padding-{{ $start }}: 18px; }
</style>

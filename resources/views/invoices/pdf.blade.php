@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    // DOMPDF-safe helpers — no flex/grid/CSS-vars, tables + inline styles only.
    $fmtCurrency = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $fmtDate = fn ($d) => $d instanceof \DateTimeInterface
        ? Carbon::instance($d)->locale('es_CO')->isoFormat('DD/MM/YYYY')
        : ($d ? Carbon::parse($d)->locale('es_CO')->isoFormat('DD/MM/YYYY') : '—');

    $paymentLabels = [
        'pending' => 'Pendiente',
        'paid' => 'Pagado',
        'overdue' => 'Vencido',
    ];
    $paymentColors = [
        'pending' => ['bg' => '#fef3c7', 'fg' => '#92400e'],
        'paid' => ['bg' => '#d1fae5', 'fg' => '#065f46'],
        'overdue' => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
    ];

    $paymentKey = $invoice->payment_status instanceof \BackedEnum
        ? $invoice->payment_status->value
        : (string) $invoice->payment_status;
    $paymentLabel = $paymentLabels[$paymentKey] ?? $paymentKey;
    $paymentBg = $paymentColors[$paymentKey]['bg'] ?? '#e5e7eb';
    $paymentFg = $paymentColors[$paymentKey]['fg'] ?? '#111827';
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $invoice->invoice_number }}</title>
    <style>
        @page {
            margin: 100px 50px 70px 50px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #111827;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .header {
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 70px;
        }

        .footer {
            position: fixed;
            bottom: -50px;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #6b7280;
        }

        .section-title {
            font-size: 10pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #374151;
            margin: 18px 0 6px 0;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
        }

        .mono {
            font-family: DejaVu Sans Mono, monospace;
        }

        .muted {
            color: #6b7280;
        }

        .tabular {
            font-variant-numeric: tabular-nums;
        }

        .badge-info {
            display: inline-block;
            padding: 2px 8px;
            background: #dc2626;
            color: #ffffff;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .data-table th {
            background: #f3f4f6;
            text-align: left;
            padding: 6px 8px;
            font-size: 9pt;
            font-weight: bold;
            color: #374151;
            border-bottom: 1px solid #d1d5db;
        }

        .data-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9.5pt;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .totals {
            margin-top: 16px;
            margin-left: auto;
            width: 55%;
        }

        .totals td {
            padding: 4px 8px;
            font-size: 10pt;
        }

        .totals .label {
            text-align: right;
            color: #374151;
        }

        .totals .value {
            text-align: right;
            font-variant-numeric: tabular-nums;
            width: 40%;
        }

        .totals .grand {
            border-top: 2px solid #111827;
            font-weight: bold;
            font-size: 12pt;
            padding-top: 8px;
        }

        .notes {
            margin-top: 16px;
            padding: 10px;
            background: #f9fafb;
            border-left: 3px solid #9ca3af;
            white-space: pre-wrap;
        }

        .fallback-note {
            font-style: italic;
            color: #6b7280;
            padding: 10px 0;
        }
    </style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td style="vertical-align: top;">
                <h1 style="font-size: 22pt; font-weight: bold; color: #111827;">{{ config('app.name') }}</h1>
                <p class="muted" style="font-size: 9pt; margin-top: 2px;">
                    {{ config('app.tagline') }}
                </p>
            </td>
            <td style="vertical-align: top; text-align: right;">
                <h2 style="font-size: 16pt; font-weight: bold; color: #111827;">
                    FACTURA INFORMATIVA
                </h2>
                <p class="mono" style="font-size: 12pt; margin-top: 2px; color: #374151;">
                    {{ $invoice->invoice_number }}
                </p>
                <p style="margin-top: 4px;">
                    <span class="badge-info">INFORMATIVO</span>
                </p>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    <table>
        <tr>
            <td style="width: 60%;">
                Documento informativo — no constituye factura fiscal.
            </td>
            <td style="width: 40%; text-align: right;">
                Generado el {{ $now_formatted }} — página
                <span class="pagenum-counter"></span>
            </td>
        </tr>
    </table>
    <script type="text/php">
        if (isset($pdf)) {
            $pdf->page_text(
                $pdf->get_width() - 85,
                $pdf->get_height() - 28,
                "{PAGE_NUM} / {PAGE_COUNT}",
                null,
                8,
                [0.42, 0.45, 0.50]
            );
        }
    </script>
</div>

{{-- Meta row: Fecha + Estado + Valor Total --}}
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 33%;">
            <p class="muted" style="font-size: 8pt; text-transform: uppercase; letter-spacing: 0.04em;">
                Fecha de Emisión
            </p>
            <p style="font-size: 11pt; margin-top: 2px;">
                {{ $fmtDate($invoice->issue_date) }}
            </p>
        </td>
        <td style="width: 33%;">
            <p class="muted" style="font-size: 8pt; text-transform: uppercase; letter-spacing: 0.04em;">
                Estado de Pago
            </p>
            <p style="margin-top: 4px;">
                <span style="display: inline-block; padding: 3px 10px; background: {{ $paymentBg }}; color: {{ $paymentFg }}; border-radius: 3px; font-size: 9pt; font-weight: bold;">
                    {{ $paymentLabel }}
                </span>
            </p>
        </td>
        <td style="width: 34%; text-align: right;">
            <p class="muted" style="font-size: 8pt; text-transform: uppercase; letter-spacing: 0.04em;">
                Valor Total
            </p>
            <p class="tabular" style="font-size: 18pt; font-weight: bold; margin-top: 2px;">
                {{ $fmtCurrency($grand_total) }}
            </p>
        </td>
    </tr>
</table>

{{-- Cliente block --}}
<p class="section-title">Cliente</p>
<p style="font-size: 12pt; font-weight: bold;">{{ $customer_name }}</p>
@if ($customer_document !== '')
    <p class="mono muted" style="font-size: 9.5pt; margin-top: 2px;">{{ $customer_document }}</p>
@endif
@if ($customer_address_line !== '')
    <p class="muted" style="font-size: 9pt; margin-top: 2px;">{{ $customer_address_line }}</p>
@endif

{{-- Servicios Facturados --}}
<p class="section-title">Servicios Facturados</p>
@if ($services->isEmpty())
    <p class="fallback-note">Sin servicios asociados — valor total manual.</p>
@else
    <table class="data-table">
        <thead>
            <tr>
                <th>Consecutivo</th>
                <th>Fecha</th>
                <th>Contrato</th>
                <th>Vehículo</th>
                <th class="right">Valor Unit.</th>
                <th class="right">Cant.</th>
                <th class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($services as $service)
                @php
                    $subtotal = (float) $service->unit_value * (int) $service->quantity;
                @endphp
                <tr>
                    <td class="mono">{{ $service->service_number }}</td>
                    <td>{{ $fmtDate($service->service_date) }}</td>
                    <td class="mono">{{ $service->contract?->contract_number ?? '—' }}</td>
                    <td class="mono">{{ $service->vehicle?->plate ?? '—' }}</td>
                    <td class="right tabular">{{ $fmtCurrency($service->unit_value) }}</td>
                    <td class="right tabular">{{ (int) $service->quantity }}</td>
                    <td class="right tabular">{{ $fmtCurrency($subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Novedades que afectan facturación (only when present) --}}
@if ($billing_incidents->isNotEmpty())
    <p class="section-title">Novedades que afectan facturación</p>
    <table class="data-table">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Servicio</th>
                <th>Tipo</th>
                <th>Descripción</th>
                <th class="right">Valor adicional</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($billing_incidents as $incident)
                @php
                    $service = $services->firstWhere('id', $incident->service_id);
                    $serviceLabel = $service?->service_number ?? ($service?->vehicle?->plate ?? '—');
                @endphp
                <tr>
                    <td>
                        @if ($incident->reported_at)
                            {{ Carbon::parse($incident->reported_at)->locale('es_CO')->isoFormat('DD/MM/YYYY') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="mono">{{ $serviceLabel }}</td>
                    <td>{{ $incident->incidentType?->name ?? '—' }}</td>
                    <td>{{ Str::limit($incident->description ?? '', 100) }}</td>
                    <td class="right tabular">
                        {{ $incident->additional_value === null ? '—' : $fmtCurrency($incident->additional_value) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Totales --}}
<table class="totals">
    <tr>
        <td class="label">Subtotal servicios</td>
        <td class="value">{{ $fmtCurrency($subtotal_services) }}</td>
    </tr>
    @if ($subtotal_incidents > 0)
        <tr>
            <td class="label">Subtotal novedades</td>
            <td class="value">{{ $fmtCurrency($subtotal_incidents) }}</td>
        </tr>
    @endif
    <tr>
        <td class="label grand">Total</td>
        <td class="value grand">{{ $fmtCurrency($grand_total) }}</td>
    </tr>
</table>

{{-- Observaciones (conditional) --}}
@if (filled($invoice->notes))
    <p class="section-title">Observaciones</p>
    <div class="notes">{{ $invoice->notes }}</div>
@endif

</body>
</html>

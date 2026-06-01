@php
    use Illuminate\Support\Carbon;

    // DOMPDF-safe: tables + inline styles, no flex/grid/CSS-vars.
    $fmtDate = fn ($d) => $d instanceof \DateTimeInterface
        ? Carbon::instance($d)->locale('es_CO')->isoFormat('DD/MM/YYYY')
        : ($d ? Carbon::parse($d)->locale('es_CO')->isoFormat('DD/MM/YYYY') : '—');

    $fmtDateTime = fn ($d) => $d instanceof \DateTimeInterface
        ? Carbon::instance($d)->locale('es_CO')->isoFormat('DD/MM/YYYY HH:mm')
        : ($d ? Carbon::parse($d)->locale('es_CO')->isoFormat('DD/MM/YYYY HH:mm') : '—');

    $contract = $service->contract;
    $thirdParty = $contract?->thirdParty;
    $vehicle = $service->vehicle;
    $driver = $service->driver;

    $customerName = $thirdParty?->is_natural_person
        ? trim(($thirdParty->first_name ?? '').' '.($thirdParty->first_lastname ?? ''))
        : ($thirdParty?->company_name ?? '—');
    $customerDoc = $thirdParty
        ? (($thirdParty->documentType?->code ?? '?').' '.$thirdParty->identification_number)
        : '—';

    $driverFullName = $driver
        ? trim(($driver->first_name ?? '').' '.($driver->first_lastname ?? ''))
        : '—';
    $driverDoc = $driver
        ? (($driver->documentType?->code ?? '?').' '.$driver->identification_number)
        : '—';

    $contractObjectLabels = [
        'business' => 'Empresarial',
        'tourism' => 'Turismo',
        'health' => 'Salud',
        'occasional' => 'Ocasional',
    ];
    $contractObject = $contract?->contract_object instanceof \BackedEnum
        ? $contract->contract_object->value
        : (string) $contract?->contract_object;
    $contractObjectLabel = $contractObjectLabels[$contractObject] ?? $contractObject;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>FUEC Nº {{ $consecutive }}</title>
    <style>
        @page {
            margin: 90px 45px 70px 45px;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111827;
        }
        .header {
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 70px;
            border-bottom: 2px solid #111827;
        }
        .header-title {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }
        .header-sub {
            font-size: 9px;
            color: #374151;
        }
        .footer {
            position: fixed;
            bottom: -55px;
            left: 0;
            right: 0;
            height: 50px;
            border-top: 1px solid #9ca3af;
            font-size: 8px;
            color: #4b5563;
            padding-top: 4px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #1f2937;
            background: #f3f4f6;
            padding: 4px 6px;
            margin: 10px 0 4px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table.data td {
            padding: 3px 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        table.data td.label {
            font-weight: bold;
            width: 30%;
            color: #374151;
        }
        .qr-box {
            border: 2px solid #111827;
            padding: 8px;
            text-align: center;
            margin-top: 12px;
        }
        .qr-box img {
            width: 180px;
            height: 180px;
        }
        .qr-url {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8px;
            word-break: break-all;
            margin-top: 4px;
        }
    </style>
</head>
<body>

<div class="header">
    <table style="border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <span class="header-title">FUEC Nº {{ $consecutive }}</span><br>
                <span class="header-sub">Formato Único de Extracto de Contrato</span>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <span class="header-sub">
                    Resolución {{ $range->resolution_number }} de {{ $range->resolution_year }}<br>
                    Rango autorizado: {{ $range->range_from }}–{{ $range->range_to }}<br>
                    Emitido: {{ $fmtDateTime($generatedAt) }}
                </span>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    Documento generado por SGTE — verificable en: {{ $verifyUrl }}<br>
    <em>Este documento es de uso único, intransferible, y únicamente es válido junto con la tarjeta de operación del vehículo y la licencia de conducción del conductor.</em>
</div>

<main>
    <div class="section-title">Contrato</div>
    <table class="data">
        <tr><td class="label">Número</td><td>{{ $contract?->contract_number ?? '—' }}</td></tr>
        <tr><td class="label">Cliente</td><td>{{ $customerName }} ({{ $customerDoc }})</td></tr>
        <tr><td class="label">Objeto</td><td>{{ $contractObjectLabel }}</td></tr>
        <tr><td class="label">Vigencia</td><td>{{ $fmtDate($contract?->start_date) }} → {{ $fmtDate($contract?->end_date) }}</td></tr>
    </table>

    <div class="section-title">Vehículo</div>
    <table class="data">
        <tr><td class="label">Placa</td><td>{{ $vehicle?->plate ?? '—' }}</td></tr>
        <tr><td class="label">Marca / Línea</td><td>{{ $vehicle?->brand ?? '—' }} {{ $vehicle?->line ?? '' }}</td></tr>
        <tr><td class="label">Modelo</td><td>{{ $vehicle?->model_year ?? '—' }}</td></tr>
        <tr><td class="label">Capacidad</td><td>{{ $vehicle?->capacity ?? '—' }} pasajeros</td></tr>
    </table>

    <div class="section-title">Conductor</div>
    <table class="data">
        <tr><td class="label">Nombre completo</td><td>{{ $driverFullName }}</td></tr>
        <tr><td class="label">Cédula</td><td>{{ $driverDoc }}</td></tr>
        <tr><td class="label">Categoría de licencia</td><td>{{ $driver?->license_category?->value ?? '—' }}</td></tr>
        <tr><td class="label">Vencimiento de licencia</td><td>{{ $fmtDate($driver?->license_due_date) }}</td></tr>
    </table>

    <div class="section-title">Servicio</div>
    <table class="data">
        <tr><td class="label">Consecutivo</td><td>{{ $service->service_number }}</td></tr>
        <tr><td class="label">Fecha</td><td>{{ $fmtDate($service->service_date) }}</td></tr>
        <tr><td class="label">Hora planificada</td><td>{{ $service->planned_start_time ?? '—' }}</td></tr>
        <tr><td class="label">Duración estimada</td><td>{{ $service->planned_duration ?? '—' }} min</td></tr>
        <tr>
            <td class="label">Origen</td>
            <td>
                {{ $service->originMunicipality?->name ?? '—' }}<br>
                <small style="color:#6b7280;">{{ $service->origin_address ?: '' }}</small>
            </td>
        </tr>
        <tr>
            <td class="label">Destino</td>
            <td>
                {{ $service->destinationMunicipality?->name ?? '—' }}<br>
                <small style="color:#6b7280;">{{ $service->destination_address ?: '' }}</small>
            </td>
        </tr>
    </table>

    <div class="qr-box">
        <img src="{{ $qrDataUri }}" alt="QR de verificación">
        <div class="qr-url">{{ $verifyUrl }}</div>
    </div>
</main>

</body>
</html>

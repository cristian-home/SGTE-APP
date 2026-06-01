<?php

namespace App\Enums;

enum PermissionGroup: string
{
    case DASHBOARD_SETTINGS = 'dashboard';
    case VEHICLES = 'vehicles';
    case DRIVERS = 'drivers';
    case THIRD_PARTIES = 'third-parties';
    case CONTRACTS = 'contracts';
    case SERVICES = 'services';
    case DAY_SUMMARY = 'day-summary';
    case INCIDENTS = 'incidents';
    case INVOICES = 'invoices';
    case REPORTS = 'reports';
    case FUEC = 'fuec';
    case GPS = 'gps';
    case USERS = 'users';
    case INCIDENT_TYPES = 'incident-types';
    case VEHICLE_TYPES = 'vehicle-types';
    case CATALOGS = 'catalogs';
    case AUDIT = 'audit';
    case DATA_IMPORTS = 'data-imports';
    case NOTIFICATIONS = 'notifications';

    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD_SETTINGS => 'Panel y Configuración',
            self::VEHICLES => 'Vehículos',
            self::DRIVERS => 'Conductores',
            self::THIRD_PARTIES => 'Terceros',
            self::CONTRACTS => 'Contratos',
            self::SERVICES => 'Servicios',
            self::DAY_SUMMARY => 'Resumen del día',
            self::INCIDENTS => 'Novedades',
            self::INVOICES => 'Facturas',
            self::REPORTS => 'Reportes',
            self::FUEC => 'FUEC',
            self::GPS => 'GPS',
            self::USERS => 'Usuarios',
            self::INCIDENT_TYPES => 'Tipos de novedad',
            self::VEHICLE_TYPES => 'Tipos de vehículo',
            self::CATALOGS => 'Catálogos',
            self::AUDIT => 'Auditoría',
            self::DATA_IMPORTS => 'Importaciones',
            self::NOTIFICATIONS => 'Notificaciones',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::DASHBOARD_SETTINGS => 0,
            self::VEHICLES => 1,
            self::DRIVERS => 2,
            self::THIRD_PARTIES => 3,
            self::CONTRACTS => 4,
            self::SERVICES => 5,
            self::DAY_SUMMARY => 6,
            self::INCIDENTS => 7,
            self::INVOICES => 8,
            self::REPORTS => 9,
            self::FUEC => 10,
            self::GPS => 11,
            self::USERS => 12,
            self::INCIDENT_TYPES => 13,
            self::VEHICLE_TYPES => 14,
            self::CATALOGS => 15,
            self::AUDIT => 16,
            self::DATA_IMPORTS => 17,
            self::NOTIFICATIONS => 18,
        };
    }
}

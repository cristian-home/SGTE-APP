<?php

namespace App\Enums;

enum Permission: string
{
    // Dashboard & Settings
    case VIEW_DASHBOARD = 'dashboard.view';
    case VIEW_SETTINGS = 'settings.view';

    // Vehicles
    case VIEW_VEHICLES = 'vehicles.view';
    case CREATE_VEHICLES = 'vehicles.create';
    case UPDATE_VEHICLES = 'vehicles.update';
    case DELETE_VEHICLES = 'vehicles.delete';

    // Drivers
    case VIEW_DRIVERS = 'drivers.view';
    case CREATE_DRIVERS = 'drivers.create';
    case UPDATE_DRIVERS = 'drivers.update';
    case DELETE_DRIVERS = 'drivers.delete';

    // Third Parties
    case VIEW_THIRD_PARTIES = 'third-parties.view';
    case CREATE_THIRD_PARTIES = 'third-parties.create';
    case UPDATE_THIRD_PARTIES = 'third-parties.update';
    case DELETE_THIRD_PARTIES = 'third-parties.delete';

    // Contracts
    case VIEW_CONTRACTS = 'contracts.view';
    case CREATE_CONTRACTS = 'contracts.create';
    case UPDATE_CONTRACTS = 'contracts.update';
    case DELETE_CONTRACTS = 'contracts.delete';

    // Services
    case VIEW_SERVICES = 'services.view';
    case CREATE_SERVICES = 'services.create';
    case UPDATE_PROJECTED_SERVICES = 'services.update-projected';
    case UPDATE_EXECUTED_SERVICES = 'services.update-executed';
    case DELETE_SERVICES = 'services.delete';
    case REGISTER_SERVICE_TIMES = 'services.register-times';

    // Day Summary
    case VIEW_DAY_SUMMARY = 'day-summary.view';
    case EXECUTE_DAY = 'day-summary.execute';

    // Incidents
    case VIEW_INCIDENTS = 'incidents.view';
    case CREATE_INCIDENTS = 'incidents.create';
    case UPDATE_INCIDENTS = 'incidents.update';
    case DELETE_INCIDENTS = 'incidents.delete';

    // Invoices
    case VIEW_INVOICES = 'invoices.view';
    case CREATE_INVOICES = 'invoices.create';
    case UPDATE_INVOICES = 'invoices.update';
    case DELETE_INVOICES = 'invoices.delete';
    case ASSIGN_SERVICES_TO_INVOICES = 'invoices.assign-services';

    // Reports
    case VIEW_REPORTS = 'reports.view';

    // FUEC (optional)
    case VIEW_FUEC = 'fuec.view';
    case GENERATE_FUEC = 'fuec.generate';
    case MANAGE_FUEC_NUMBER_RANGES = 'fuec-number-ranges.manage';

    // GPS / Vehicle Locations (optional)
    case VIEW_VEHICLE_LOCATIONS = 'vehicle-locations.view';
    case REGISTER_VEHICLE_LOCATION = 'vehicle-locations.register';
    case DELETE_VEHICLE_LOCATIONS = 'vehicle-locations.delete';

    // Users
    case VIEW_USERS = 'users.view';
    case CREATE_USERS = 'users.create';
    case UPDATE_USERS = 'users.update';
    case DELETE_USERS = 'users.delete';

    // Incident Types (catalog)
    case VIEW_INCIDENT_TYPES = 'incident-types.view';
    case CREATE_INCIDENT_TYPES = 'incident-types.create';
    case UPDATE_INCIDENT_TYPES = 'incident-types.update';
    case DELETE_INCIDENT_TYPES = 'incident-types.delete';

    // Vehicle Types (catalog)
    case VIEW_VEHICLE_TYPES = 'vehicle-types.view';
    case CREATE_VEHICLE_TYPES = 'vehicle-types.create';
    case UPDATE_VEHICLE_TYPES = 'vehicle-types.update';
    case DELETE_VEHICLE_TYPES = 'vehicle-types.delete';

    // Static Catalogs (document types, EPS, pension funds, severance funds)
    case MANAGE_CATALOGS = 'catalogs.manage';

    // Audit log (Administración)
    case VIEW_AUDIT_LOG = 'audit-log.view';

    // Data Imports (Administración) — bulk CSV/XLSX uploads (super admin only)
    case MANAGE_DATA_IMPORTS = 'data-imports.manage';

    // Notifications
    case RECEIVE_NOTIFICATIONS = 'notifications.receive';

    public function label(): string
    {
        return match ($this) {
            self::VIEW_DASHBOARD => 'Ver dashboard',
            self::VIEW_SETTINGS => 'Acceder a configuración',
            self::VIEW_VEHICLES => 'Ver vehículos',
            self::CREATE_VEHICLES => 'Crear vehículos',
            self::UPDATE_VEHICLES => 'Editar vehículos',
            self::DELETE_VEHICLES => 'Eliminar vehículos',
            self::VIEW_DRIVERS => 'Ver conductores',
            self::CREATE_DRIVERS => 'Crear conductores',
            self::UPDATE_DRIVERS => 'Editar conductores',
            self::DELETE_DRIVERS => 'Eliminar conductores',
            self::VIEW_THIRD_PARTIES => 'Ver terceros',
            self::CREATE_THIRD_PARTIES => 'Crear terceros',
            self::UPDATE_THIRD_PARTIES => 'Editar terceros',
            self::DELETE_THIRD_PARTIES => 'Eliminar terceros',
            self::VIEW_CONTRACTS => 'Ver contratos',
            self::CREATE_CONTRACTS => 'Crear contratos',
            self::UPDATE_CONTRACTS => 'Editar contratos',
            self::DELETE_CONTRACTS => 'Eliminar contratos',
            self::VIEW_SERVICES => 'Ver servicios',
            self::CREATE_SERVICES => 'Crear servicios',
            self::UPDATE_PROJECTED_SERVICES => 'Editar servicios proyectados',
            self::UPDATE_EXECUTED_SERVICES => 'Editar servicios ejecutados',
            self::DELETE_SERVICES => 'Eliminar servicios',
            self::REGISTER_SERVICE_TIMES => 'Registrar tiempos reales',
            self::VIEW_DAY_SUMMARY => 'Ver resumen del día',
            self::EXECUTE_DAY => 'Ejecutar día',
            self::VIEW_INCIDENTS => 'Ver novedades',
            self::CREATE_INCIDENTS => 'Registrar novedades',
            self::UPDATE_INCIDENTS => 'Editar novedades',
            self::DELETE_INCIDENTS => 'Eliminar novedades',
            self::VIEW_INVOICES => 'Ver facturas',
            self::CREATE_INVOICES => 'Crear facturas',
            self::UPDATE_INVOICES => 'Editar facturas',
            self::DELETE_INVOICES => 'Eliminar facturas',
            self::ASSIGN_SERVICES_TO_INVOICES => 'Asociar servicios a facturas',
            self::VIEW_REPORTS => 'Ver reportes',
            self::VIEW_FUEC => 'Ver FUEC',
            self::GENERATE_FUEC => 'Generar FUEC',
            self::MANAGE_FUEC_NUMBER_RANGES => 'Gestionar rangos MinTransporte',
            self::VIEW_VEHICLE_LOCATIONS => 'Ver ubicaciones de vehículos',
            self::REGISTER_VEHICLE_LOCATION => 'Registrar ubicación de vehículo',
            self::DELETE_VEHICLE_LOCATIONS => 'Eliminar ubicaciones de vehículos',
            self::VIEW_USERS => 'Ver usuarios',
            self::CREATE_USERS => 'Crear usuarios',
            self::UPDATE_USERS => 'Editar usuarios',
            self::DELETE_USERS => 'Eliminar usuarios',
            self::VIEW_INCIDENT_TYPES => 'Ver tipos de novedad',
            self::CREATE_INCIDENT_TYPES => 'Crear tipos de novedad',
            self::UPDATE_INCIDENT_TYPES => 'Editar tipos de novedad',
            self::DELETE_INCIDENT_TYPES => 'Eliminar tipos de novedad',
            self::VIEW_VEHICLE_TYPES => 'Ver tipos de vehículo',
            self::CREATE_VEHICLE_TYPES => 'Crear tipos de vehículo',
            self::UPDATE_VEHICLE_TYPES => 'Editar tipos de vehículo',
            self::DELETE_VEHICLE_TYPES => 'Eliminar tipos de vehículo',
            self::MANAGE_CATALOGS => 'Gestionar catálogos (documentos, EPS, fondos)',
            self::VIEW_AUDIT_LOG => 'Ver registro de auditoría',
            self::MANAGE_DATA_IMPORTS => 'Gestionar importaciones masivas',
            self::RECEIVE_NOTIFICATIONS => 'Recibir notificaciones',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::VIEW_DASHBOARD => 'Acceso al panel principal con métricas en tiempo real.',
            self::VIEW_SETTINGS => 'Modificar parámetros generales del sistema.',
            self::VIEW_VEHICLES => 'Consultar el listado de la flota.',
            self::CREATE_VEHICLES => 'Registrar nuevos vehículos.',
            self::UPDATE_VEHICLES => 'Actualizar información de vehículos.',
            self::DELETE_VEHICLES => 'Dar de baja vehículos del sistema.',
            self::VIEW_DRIVERS => 'Consultar el listado de conductores.',
            self::CREATE_DRIVERS => 'Registrar nuevos conductores.',
            self::UPDATE_DRIVERS => 'Actualizar información de conductores.',
            self::DELETE_DRIVERS => 'Remover conductores del sistema.',
            self::VIEW_THIRD_PARTIES => 'Consultar terceros registrados.',
            self::CREATE_THIRD_PARTIES => 'Registrar nuevos terceros.',
            self::UPDATE_THIRD_PARTIES => 'Editar información de terceros.',
            self::DELETE_THIRD_PARTIES => 'Remover terceros.',
            self::VIEW_CONTRACTS => 'Consultar contratos vigentes y finalizados.',
            self::CREATE_CONTRACTS => 'Registrar nuevos contratos.',
            self::UPDATE_CONTRACTS => 'Editar contratos existentes.',
            self::DELETE_CONTRACTS => 'Archivar contratos.',
            self::VIEW_SERVICES => 'Consultar servicios proyectados y ejecutados.',
            self::CREATE_SERVICES => 'Programar nuevos servicios.',
            self::UPDATE_PROJECTED_SERVICES => 'Modificar servicios aún no ejecutados.',
            self::UPDATE_EXECUTED_SERVICES => 'Ajustar servicios cerrados (acción auditada).',
            self::DELETE_SERVICES => 'Eliminar servicios proyectados.',
            self::REGISTER_SERVICE_TIMES => 'Capturar tiempos reales de ejecución.',
            self::VIEW_DAY_SUMMARY => 'Vista consolidada de la operación diaria.',
            self::EXECUTE_DAY => 'Cerrar y ejecutar el día de operación.',
            self::VIEW_INCIDENTS => 'Consultar novedades reportadas.',
            self::CREATE_INCIDENTS => 'Registrar nuevas novedades.',
            self::UPDATE_INCIDENTS => 'Editar novedades existentes.',
            self::DELETE_INCIDENTS => 'Eliminar novedades.',
            self::VIEW_INVOICES => 'Consultar facturas.',
            self::CREATE_INVOICES => 'Emitir nuevas facturas.',
            self::UPDATE_INVOICES => 'Editar facturas.',
            self::DELETE_INVOICES => 'Anular facturas.',
            self::ASSIGN_SERVICES_TO_INVOICES => 'Enlazar servicios ejecutados a una factura.',
            self::VIEW_REPORTS => 'Acceso a reportes operativos y financieros.',
            self::VIEW_FUEC => 'Consultar formatos FUEC emitidos.',
            self::GENERATE_FUEC => 'Emitir nuevos formatos FUEC.',
            self::MANAGE_FUEC_NUMBER_RANGES => 'Administrar rangos de numeración asignados por MinTransporte.',
            self::VIEW_VEHICLE_LOCATIONS => 'Consultar ubicaciones registradas de la flota.',
            self::REGISTER_VEHICLE_LOCATION => 'Capturar ubicaciones GPS desde el portal del conductor.',
            self::DELETE_VEHICLE_LOCATIONS => 'Eliminar registros de ubicación.',
            self::VIEW_USERS => 'Consultar usuarios.',
            self::CREATE_USERS => 'Crear nuevos usuarios.',
            self::UPDATE_USERS => 'Editar usuarios y roles asignados.',
            self::DELETE_USERS => 'Eliminar usuarios.',
            self::VIEW_INCIDENT_TYPES => 'Consultar tipos de novedad.',
            self::CREATE_INCIDENT_TYPES => 'Crear tipos de novedad.',
            self::UPDATE_INCIDENT_TYPES => 'Editar tipos de novedad.',
            self::DELETE_INCIDENT_TYPES => 'Eliminar tipos de novedad.',
            self::VIEW_VEHICLE_TYPES => 'Consultar tipos de vehículo.',
            self::CREATE_VEHICLE_TYPES => 'Crear tipos de vehículo.',
            self::UPDATE_VEHICLE_TYPES => 'Editar tipos de vehículo.',
            self::DELETE_VEHICLE_TYPES => 'Eliminar tipos de vehículo.',
            self::MANAGE_CATALOGS => 'Gestionar catálogos: documentos, EPS, fondos y ciudades.',
            self::VIEW_AUDIT_LOG => 'Historial de cambios sensibles registrados en el sistema.',
            self::MANAGE_DATA_IMPORTS => 'Cargar archivos masivos de usuarios, conductores, terceros o vehículos.',
            self::RECEIVE_NOTIFICATIONS => 'Recibir alertas del sistema por correo y en la aplicación.',
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::VIEW_DASHBOARD,
            self::VIEW_SETTINGS => PermissionGroup::DASHBOARD_SETTINGS,
            self::VIEW_VEHICLES,
            self::CREATE_VEHICLES,
            self::UPDATE_VEHICLES,
            self::DELETE_VEHICLES => PermissionGroup::VEHICLES,
            self::VIEW_DRIVERS,
            self::CREATE_DRIVERS,
            self::UPDATE_DRIVERS,
            self::DELETE_DRIVERS => PermissionGroup::DRIVERS,
            self::VIEW_THIRD_PARTIES,
            self::CREATE_THIRD_PARTIES,
            self::UPDATE_THIRD_PARTIES,
            self::DELETE_THIRD_PARTIES => PermissionGroup::THIRD_PARTIES,
            self::VIEW_CONTRACTS,
            self::CREATE_CONTRACTS,
            self::UPDATE_CONTRACTS,
            self::DELETE_CONTRACTS => PermissionGroup::CONTRACTS,
            self::VIEW_SERVICES,
            self::CREATE_SERVICES,
            self::UPDATE_PROJECTED_SERVICES,
            self::UPDATE_EXECUTED_SERVICES,
            self::DELETE_SERVICES,
            self::REGISTER_SERVICE_TIMES => PermissionGroup::SERVICES,
            self::VIEW_DAY_SUMMARY,
            self::EXECUTE_DAY => PermissionGroup::DAY_SUMMARY,
            self::VIEW_INCIDENTS,
            self::CREATE_INCIDENTS,
            self::UPDATE_INCIDENTS,
            self::DELETE_INCIDENTS => PermissionGroup::INCIDENTS,
            self::VIEW_INVOICES,
            self::CREATE_INVOICES,
            self::UPDATE_INVOICES,
            self::DELETE_INVOICES,
            self::ASSIGN_SERVICES_TO_INVOICES => PermissionGroup::INVOICES,
            self::VIEW_REPORTS => PermissionGroup::REPORTS,
            self::VIEW_FUEC,
            self::GENERATE_FUEC,
            self::MANAGE_FUEC_NUMBER_RANGES => PermissionGroup::FUEC,
            self::VIEW_VEHICLE_LOCATIONS,
            self::REGISTER_VEHICLE_LOCATION,
            self::DELETE_VEHICLE_LOCATIONS => PermissionGroup::GPS,
            self::VIEW_USERS,
            self::CREATE_USERS,
            self::UPDATE_USERS,
            self::DELETE_USERS => PermissionGroup::USERS,
            self::VIEW_INCIDENT_TYPES,
            self::CREATE_INCIDENT_TYPES,
            self::UPDATE_INCIDENT_TYPES,
            self::DELETE_INCIDENT_TYPES => PermissionGroup::INCIDENT_TYPES,
            self::VIEW_VEHICLE_TYPES,
            self::CREATE_VEHICLE_TYPES,
            self::UPDATE_VEHICLE_TYPES,
            self::DELETE_VEHICLE_TYPES => PermissionGroup::VEHICLE_TYPES,
            self::MANAGE_CATALOGS => PermissionGroup::CATALOGS,
            self::VIEW_AUDIT_LOG => PermissionGroup::AUDIT,
            self::MANAGE_DATA_IMPORTS => PermissionGroup::DATA_IMPORTS,
            self::RECEIVE_NOTIFICATIONS => PermissionGroup::NOTIFICATIONS,
        };
    }

    /**
     * Permissions grouped by PermissionGroup, ordered for the admin UI.
     *
     * @return array<int, array{
     *     id: string,
     *     label: string,
     *     permissions: array<int, array{key: string, label: string, description: string}>
     * }>
     */
    public static function groupedForUi(): array
    {
        $buckets = [];
        foreach (PermissionGroup::cases() as $group) {
            $buckets[$group->value] = [
                'id' => $group->value,
                'label' => $group->label(),
                'order' => $group->order(),
                'permissions' => [],
            ];
        }

        foreach (self::cases() as $perm) {
            $buckets[$perm->group()->value]['permissions'][] = [
                'key' => $perm->value,
                'label' => $perm->label(),
                'description' => $perm->description(),
            ];
        }

        usort($buckets, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn (array $b) => [
                'id' => $b['id'],
                'label' => $b['label'],
                'permissions' => $b['permissions'],
            ],
            $buckets,
        );
    }
}

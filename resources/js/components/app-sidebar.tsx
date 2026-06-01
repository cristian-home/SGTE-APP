import { Link, usePage } from '@inertiajs/react';
import {
    Calendar,
    FileText,
    LayoutGrid,
    MapPin,
    Receipt,
    Settings,
    Shield,
    Truck,
    Wrench,
} from 'lucide-react';
import { index as auditLogIndex } from '@/actions/App/Http/Controllers/AuditLogController';
import { index as contractsIndex } from '@/actions/App/Http/Controllers/ContractController';
import { index as dataImportsIndex } from '@/actions/App/Http/Controllers/DataImportController';
import { calendar as dayStatusesCalendar } from '@/actions/App/Http/Controllers/DayStatusController';
import { index as daySummaryIndex } from '@/actions/App/Http/Controllers/DaySummaryController';
import { index as documentTypesIndex } from '@/actions/App/Http/Controllers/DocumentTypeController';
import { index as driversIndex } from '@/actions/App/Http/Controllers/DriverController';
import { index as driverDashboardIndex } from '@/actions/App/Http/Controllers/DriverDashboardController';
import { index as epsIndex } from '@/actions/App/Http/Controllers/EpsController';
import { index as fuecsIndex } from '@/actions/App/Http/Controllers/FuecController';
import { index as fuecNumberRangesIndex } from '@/actions/App/Http/Controllers/FuecNumberRangeController';
import { index as ganttIndex } from '@/actions/App/Http/Controllers/GanttController';
import { index as incidentTypesIndex } from '@/actions/App/Http/Controllers/IncidentTypeController';
import { index as invoicesIndex } from '@/actions/App/Http/Controllers/InvoiceController';
import { index as pensionFundsIndex } from '@/actions/App/Http/Controllers/PensionFundController';
import { index as permissionsIndex } from '@/actions/App/Http/Controllers/PermissionController';
import { index as rolesIndex } from '@/actions/App/Http/Controllers/RoleController';
import { index as servicesIndex } from '@/actions/App/Http/Controllers/ServiceController';
import { index as serviceIncidentsIndex } from '@/actions/App/Http/Controllers/ServiceIncidentController';
import { index as severanceFundsIndex } from '@/actions/App/Http/Controllers/SeveranceFundController';
import { index as thirdPartiesIndex } from '@/actions/App/Http/Controllers/ThirdPartyController';
import { index as usersIndex } from '@/actions/App/Http/Controllers/UserController';
import { index as vehiclesIndex } from '@/actions/App/Http/Controllers/VehicleController';
import { index as vehicleLocationsIndex } from '@/actions/App/Http/Controllers/VehicleLocationController';
import { index as vehicleTypesIndex } from '@/actions/App/Http/Controllers/VehicleTypeController';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Permission } from '@/enums/Permission';
import { dashboard } from '@/routes';
import AppLogo from './app-logo';
import type { NavGroup, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Panel',
        href: dashboard(),
        icon: LayoutGrid,
        permission: Permission.VIEW_DASHBOARD,
    },
];

const navGroups: NavGroup[] = [
    {
        label: 'Conductor',
        icon: Truck,
        permission: Permission.REGISTER_SERVICE_TIMES,
        items: [
            {
                title: 'Mis Servicios',
                href: driverDashboardIndex(),
                permission: Permission.REGISTER_SERVICE_TIMES,
            },
        ],
    },
    {
        label: 'Producción',
        icon: Calendar,
        permission: Permission.VIEW_SERVICES,
        items: [
            {
                title: 'Servicios',
                href: servicesIndex(),
                permission: Permission.VIEW_SERVICES,
            },
            {
                title: 'Planificador',
                href: ganttIndex(),
                permission: Permission.VIEW_SERVICES,
            },
            {
                title: 'Resumen del Día',
                href: daySummaryIndex(),
                permission: Permission.VIEW_DAY_SUMMARY,
            },
            {
                title: 'Calendario',
                href: dayStatusesCalendar(new Date().getFullYear()),
                permission: Permission.VIEW_DAY_SUMMARY,
            },
            {
                title: 'Novedades',
                href: serviceIncidentsIndex(),
                permission: Permission.VIEW_INCIDENTS,
            },
        ],
    },
    {
        label: 'Gestión',
        icon: Wrench,
        permission: Permission.VIEW_VEHICLES,
        items: [
            {
                title: 'Vehículos',
                href: vehiclesIndex(),
                permission: Permission.VIEW_VEHICLES,
            },
            {
                title: 'Conductores',
                href: driversIndex(),
                permission: Permission.VIEW_DRIVERS,
            },
            {
                title: 'Terceros',
                href: thirdPartiesIndex(),
                permission: Permission.VIEW_THIRD_PARTIES,
            },
            {
                title: 'Contratos',
                href: contractsIndex(),
                permission: Permission.VIEW_CONTRACTS,
            },
        ],
    },
    {
        label: 'Facturación',
        icon: Receipt,
        permission: Permission.VIEW_INVOICES,
        items: [
            {
                title: 'Facturas',
                href: invoicesIndex(),
                permission: Permission.VIEW_INVOICES,
            },
        ],
    },
    {
        label: 'Administración',
        icon: Shield,
        permission: Permission.VIEW_USERS,
        items: [
            {
                title: 'Usuarios',
                href: usersIndex(),
                permission: Permission.VIEW_USERS,
            },
            {
                title: 'Roles',
                href: rolesIndex(),
                permission: Permission.VIEW_USERS,
            },
            {
                title: 'Permisos',
                href: permissionsIndex(),
                permission: Permission.VIEW_USERS,
            },
            {
                title: 'Auditoría',
                href: auditLogIndex(),
                permission: Permission.VIEW_AUDIT_LOG,
            },
            {
                title: 'Importaciones',
                href: dataImportsIndex(),
                permission: Permission.MANAGE_DATA_IMPORTS,
            },
        ],
    },
    {
        label: 'FUEC',
        icon: FileText,
        permission: Permission.VIEW_FUEC,
        featureFlag: 'fuec',
        items: [
            {
                title: 'Documentos FUEC',
                href: fuecsIndex(),
                permission: Permission.VIEW_FUEC,
            },
            {
                title: 'Rangos MinTransporte',
                href: fuecNumberRangesIndex(),
                permission: Permission.MANAGE_FUEC_NUMBER_RANGES,
            },
        ],
    },
    {
        label: 'GPS',
        icon: MapPin,
        permission: Permission.VIEW_VEHICLE_LOCATIONS,
        featureFlag: 'gps',
        items: [
            {
                title: 'Mapa',
                href: '/gps/map',
                permission: Permission.VIEW_VEHICLE_LOCATIONS,
            },
            {
                title: 'Ubicaciones',
                href: vehicleLocationsIndex(),
                permission: Permission.VIEW_VEHICLE_LOCATIONS,
            },
        ],
    },
    {
        label: 'Catálogos',
        icon: Settings,
        items: [
            {
                title: 'Tipos de Documento',
                href: documentTypesIndex(),
                permission: Permission.MANAGE_CATALOGS,
            },
            {
                title: 'EPS',
                href: epsIndex(),
                permission: Permission.MANAGE_CATALOGS,
            },
            {
                title: 'Fondos de Pensiones',
                href: pensionFundsIndex(),
                permission: Permission.MANAGE_CATALOGS,
            },
            {
                title: 'Fondos de Cesantías',
                href: severanceFundsIndex(),
                permission: Permission.MANAGE_CATALOGS,
            },
            {
                title: 'Tipos de Novedad',
                href: incidentTypesIndex(),
                permission: Permission.VIEW_INCIDENT_TYPES,
            },
            {
                title: 'Tipos de Vehículo',
                href: vehicleTypesIndex(),
                permission: Permission.VIEW_VEHICLE_TYPES,
            },
        ],
    },
];

export function AppSidebar() {
    const page = usePage<{
        auth?: { featureFlags?: { fuec?: boolean; gps?: boolean } };
    }>();
    const featureFlags = page.props.auth?.featureFlags ?? {
        fuec: false,
        gps: false,
    };

    const visibleGroups = navGroups.filter((group) => {
        if (!group.featureFlag) return true;
        return featureFlags[group.featureFlag] === true;
    });

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} groups={visibleGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

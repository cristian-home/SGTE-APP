<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Eps;
use App\Models\IncidentType;
use App\Models\Municipality;
use App\Models\PensionFund;
use App\Models\SeveranceFund;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Catalog data migration — runs in ALL environments (including production and testing).
 *
 * Seeds:
 * - Roles & permissions (mandatory for authorization)
 * - Super Admin user (mandatory for initial access)
 * - Document types, EPS, pension/severance funds, incident types (business catalogs)
 * - Departments & municipalities (DIVIPOLA geographic catalog)
 *
 * All operations use firstOrCreate to be idempotent and safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedSuperAdmin();
        $this->seedDocumentTypes();
        $this->seedEps();
        $this->seedPensionFunds();
        $this->seedSeveranceFunds();
        $this->seedIncidentTypes();

        // Geographic data is large (~1122 municipalities). Skip in testing
        // where factories create their own Municipality/Department records.
        if (! app()->environment('testing')) {
            $this->seedDepartmentsAndMunicipalities();
        }
    }

    public function down(): void
    {
        // Catalog data should not be rolled back.
    }

    private function seedRolesAndPermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            SpatiePermission::firstOrCreate(
                ['name' => $permission->value, 'guard_name' => 'web'],
            );
        }

        // Super Admin (bypasses all gates via Gate::before)
        SpatieRole::firstOrCreate(
            ['name' => Role::SUPER_ADMIN->value, 'guard_name' => 'web'],
            ['description' => 'Acceso total al sistema. Omite todas las verificaciones de permisos.'],
        );

        // Admin
        $adminRole = SpatieRole::firstOrCreate(
            ['name' => Role::ADMIN->value, 'guard_name' => 'web'],
            ['description' => 'Administra usuarios, configuración y catálogos de la plataforma.'],
        );
        $adminRole->syncPermissions(array_map(fn ($p) => $p->value, [
            Permission::VIEW_DASHBOARD,
            Permission::VIEW_SETTINGS,
            Permission::VIEW_VEHICLES,
            Permission::CREATE_VEHICLES,
            Permission::UPDATE_VEHICLES,
            Permission::DELETE_VEHICLES,
            Permission::VIEW_DRIVERS,
            Permission::CREATE_DRIVERS,
            Permission::UPDATE_DRIVERS,
            Permission::DELETE_DRIVERS,
            Permission::VIEW_THIRD_PARTIES,
            Permission::CREATE_THIRD_PARTIES,
            Permission::UPDATE_THIRD_PARTIES,
            Permission::DELETE_THIRD_PARTIES,
            Permission::VIEW_CONTRACTS,
            Permission::CREATE_CONTRACTS,
            Permission::UPDATE_CONTRACTS,
            Permission::DELETE_CONTRACTS,
            Permission::VIEW_SERVICES,
            Permission::CREATE_SERVICES,
            Permission::UPDATE_PROJECTED_SERVICES,
            Permission::UPDATE_EXECUTED_SERVICES,
            Permission::DELETE_SERVICES,
            Permission::VIEW_DAY_SUMMARY,
            Permission::EXECUTE_DAY,
            Permission::VIEW_INCIDENTS,
            Permission::CREATE_INCIDENTS,
            Permission::UPDATE_INCIDENTS,
            Permission::DELETE_INCIDENTS,
            Permission::VIEW_INVOICES,
            Permission::CREATE_INVOICES,
            Permission::UPDATE_INVOICES,
            Permission::DELETE_INVOICES,
            Permission::ASSIGN_SERVICES_TO_INVOICES,
            Permission::VIEW_REPORTS,
            Permission::VIEW_FUEC,
            Permission::GENERATE_FUEC,
            Permission::MANAGE_FUEC_NUMBER_RANGES,
            Permission::VIEW_VEHICLE_LOCATIONS,
            Permission::REGISTER_VEHICLE_LOCATION,
            Permission::DELETE_VEHICLE_LOCATIONS,
            Permission::VIEW_USERS,
            Permission::CREATE_USERS,
            Permission::UPDATE_USERS,
            Permission::DELETE_USERS,
            Permission::VIEW_INCIDENT_TYPES,
            Permission::CREATE_INCIDENT_TYPES,
            Permission::UPDATE_INCIDENT_TYPES,
            Permission::DELETE_INCIDENT_TYPES,
            Permission::VIEW_VEHICLE_TYPES,
            Permission::CREATE_VEHICLE_TYPES,
            Permission::UPDATE_VEHICLE_TYPES,
            Permission::DELETE_VEHICLE_TYPES,
            Permission::MANAGE_CATALOGS,
            Permission::VIEW_AUDIT_LOG,
            Permission::RECEIVE_NOTIFICATIONS,
        ]));

        // Operator
        $operatorRole = SpatieRole::firstOrCreate(
            ['name' => Role::OPERATOR->value, 'guard_name' => 'web'],
            ['description' => 'Gestiona servicios, contratos, conductores y vehículos día a día.'],
        );
        $operatorRole->syncPermissions(array_map(fn ($p) => $p->value, [
            Permission::VIEW_DASHBOARD,
            Permission::VIEW_SETTINGS,
            // Gestión — master data (admin+operator)
            Permission::VIEW_VEHICLES,
            Permission::CREATE_VEHICLES,
            Permission::UPDATE_VEHICLES,
            Permission::DELETE_VEHICLES,
            Permission::VIEW_DRIVERS,
            Permission::CREATE_DRIVERS,
            Permission::UPDATE_DRIVERS,
            Permission::DELETE_DRIVERS,
            Permission::VIEW_THIRD_PARTIES,
            Permission::CREATE_THIRD_PARTIES,
            Permission::UPDATE_THIRD_PARTIES,
            Permission::DELETE_THIRD_PARTIES,
            Permission::VIEW_CONTRACTS,
            Permission::CREATE_CONTRACTS,
            Permission::UPDATE_CONTRACTS,
            Permission::DELETE_CONTRACTS,
            // Catálogos
            Permission::VIEW_INCIDENT_TYPES,
            Permission::CREATE_INCIDENT_TYPES,
            Permission::UPDATE_INCIDENT_TYPES,
            Permission::DELETE_INCIDENT_TYPES,
            Permission::VIEW_VEHICLE_TYPES,
            Permission::CREATE_VEHICLE_TYPES,
            Permission::UPDATE_VEHICLE_TYPES,
            Permission::DELETE_VEHICLE_TYPES,
            Permission::MANAGE_CATALOGS,
            // Producción
            Permission::VIEW_SERVICES,
            Permission::CREATE_SERVICES,
            Permission::UPDATE_PROJECTED_SERVICES,
            Permission::VIEW_DAY_SUMMARY,
            Permission::EXECUTE_DAY,
            Permission::VIEW_INCIDENTS,
            Permission::CREATE_INCIDENTS,
            Permission::VIEW_REPORTS,
            Permission::VIEW_VEHICLE_LOCATIONS,
            Permission::REGISTER_VEHICLE_LOCATION,
            Permission::RECEIVE_NOTIFICATIONS,
        ]));

        // Driver — no global services.view / incidents.view. Drivers
        // see only their own services through the DriverDashboardController
        // (/driver) and register tiempos/novedades from there.
        $driverRole = SpatieRole::firstOrCreate(
            ['name' => Role::DRIVER->value, 'guard_name' => 'web'],
            ['description' => 'Acceso limitado a sus propios servicios y novedades asignadas.'],
        );
        $driverRole->syncPermissions(array_map(fn ($p) => $p->value, [
            Permission::VIEW_DASHBOARD,
            Permission::VIEW_SETTINGS,
            Permission::REGISTER_SERVICE_TIMES,
            Permission::CREATE_INCIDENTS,
            Permission::REGISTER_VEHICLE_LOCATION,
            Permission::RECEIVE_NOTIFICATIONS,
        ]));

        // Accounting
        $accountingRole = SpatieRole::firstOrCreate(
            ['name' => Role::ACCOUNTING->value, 'guard_name' => 'web'],
            ['description' => 'Visualiza servicios ejecutados y gestiona facturación.'],
        );
        $accountingRole->syncPermissions(array_map(fn ($p) => $p->value, [
            Permission::VIEW_DASHBOARD,
            Permission::VIEW_SETTINGS,
            // Accounting master-data read access. Resolution of
            // accounting-master-data-asymmetry: the Gantt and Services
            // tables already surface vehicle + driver names to
            // accounting via VIEW_SERVICES, so information hiding was
            // already incomplete. Granting explicit read-only access to
            // the first-class vehicle / driver lists formalizes what
            // was effectively visible and lets accounting drill in on
            // provider-owned vehicles during invoice investigation.
            // No create/update/delete — accounting remains read-only on
            // master data.
            Permission::VIEW_VEHICLES,
            Permission::VIEW_DRIVERS,
            Permission::VIEW_THIRD_PARTIES,
            Permission::VIEW_CONTRACTS,
            Permission::VIEW_SERVICES,
            Permission::UPDATE_EXECUTED_SERVICES,
            Permission::VIEW_DAY_SUMMARY,
            Permission::VIEW_INCIDENTS,
            Permission::VIEW_INVOICES,
            Permission::CREATE_INVOICES,
            Permission::UPDATE_INVOICES,
            Permission::ASSIGN_SERVICES_TO_INVOICES,
            Permission::VIEW_REPORTS,
            Permission::RECEIVE_NOTIFICATIONS,
        ]));
    }

    private function seedSuperAdmin(): void
    {
        $email = env('SUPER_ADMIN_USER');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (app()->environment('production') && (empty($email) || empty($password))) {
            throw new \RuntimeException(
                'SUPER_ADMIN_USER and SUPER_ADMIN_PASSWORD must be set in production.'
            );
        }

        $user = User::firstOrCreate(
            ['email' => $email ?: 'superadmin@sgte.app'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password ?: 'password'),
            ],
        );

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole(Role::SUPER_ADMIN);
    }

    private function seedDepartmentsAndMunicipalities(): void
    {
        $csvPath = database_path('data/municipalities_data.csv');
        $handle = fopen($csvPath, 'r');

        // Skip the single header row
        fgetcsv($handle, 0, ';');

        $departments = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (\count($row) < 7 || empty(trim($row[0]))) {
                continue;
            }

            $deptCode = trim($row[0]);
            $deptName = trim($row[1]);
            $munCode = trim($row[2]);
            $munName = trim($row[3]);
            $type = trim($row[4]);
            $longitude = str_replace(',', '.', trim($row[5]));
            $latitude = str_replace(',', '.', trim($row[6]));

            if (! isset($departments[$deptCode])) {
                $departments[$deptCode] = Department::firstOrCreate(
                    ['code' => $deptCode],
                    ['name' => $deptName],
                );
            }

            Municipality::firstOrCreate(
                ['code' => $munCode],
                [
                    'department_id' => $departments[$deptCode]->id,
                    'name' => $munName,
                    'type' => $type,
                    'latitude' => $latitude !== '' ? $latitude : null,
                    'longitude' => $longitude !== '' ? $longitude : null,
                ],
            );
        }

        fclose($handle);
    }

    private function seedDocumentTypes(): void
    {
        $types = [
            ['code' => 'CC', 'name' => 'Cedula de Ciudadania', 'is_natural_person' => true, 'is_legal_person' => false],
            ['code' => 'NIT', 'name' => 'Numero de Identificacion Tributaria', 'is_natural_person' => false, 'is_legal_person' => true],
            ['code' => 'CE', 'name' => 'Cedula de Extranjeria', 'is_natural_person' => true, 'is_legal_person' => false],
            ['code' => 'TI', 'name' => 'Tarjeta de Identidad', 'is_natural_person' => true, 'is_legal_person' => false],
            ['code' => 'PP', 'name' => 'Pasaporte', 'is_natural_person' => true, 'is_legal_person' => false],
        ];

        foreach ($types as $type) {
            DocumentType::firstOrCreate(
                ['code' => $type['code']],
                $type,
            );
        }
    }

    private function seedEps(): void
    {
        $entities = [
            ['code' => 'EPS001', 'name' => 'Nueva EPS'],
            ['code' => 'EPS002', 'name' => 'Sura EPS'],
            ['code' => 'EPS003', 'name' => 'Sanitas'],
            ['code' => 'EPS004', 'name' => 'Salud Total'],
            ['code' => 'EPS005', 'name' => 'Coomeva EPS'],
            ['code' => 'EPS006', 'name' => 'Famisanar'],
            ['code' => 'EPS007', 'name' => 'Compensar'],
            ['code' => 'EPS008', 'name' => 'Coosalud'],
        ];

        foreach ($entities as $entity) {
            Eps::firstOrCreate(
                ['code' => $entity['code']],
                $entity,
            );
        }
    }

    private function seedPensionFunds(): void
    {
        $funds = [
            ['code' => 'FP001', 'name' => 'Porvenir'],
            ['code' => 'FP002', 'name' => 'Proteccion'],
            ['code' => 'FP003', 'name' => 'Colfondos'],
            ['code' => 'FP004', 'name' => 'Old Mutual'],
            ['code' => 'FP005', 'name' => 'Colpensiones'],
        ];

        foreach ($funds as $fund) {
            PensionFund::firstOrCreate(
                ['code' => $fund['code']],
                $fund,
            );
        }
    }

    private function seedSeveranceFunds(): void
    {
        $funds = [
            ['code' => 'FC001', 'name' => 'Porvenir'],
            ['code' => 'FC002', 'name' => 'Proteccion'],
            ['code' => 'FC003', 'name' => 'Colfondos'],
            ['code' => 'FC004', 'name' => 'FNA'],
        ];

        foreach ($funds as $fund) {
            SeveranceFund::firstOrCreate(
                ['code' => $fund['code']],
                $fund,
            );
        }
    }

    private function seedIncidentTypes(): void
    {
        $types = [
            ['code' => 'DELAY', 'name' => 'Retraso', 'severity' => 'minor', 'affects_billing_default' => false],
            ['code' => 'ACCIDENT', 'name' => 'Accidente', 'severity' => 'major', 'affects_billing_default' => true],
            ['code' => 'BREAKDOWN', 'name' => 'Avería', 'severity' => 'major', 'affects_billing_default' => true],
            ['code' => 'TRAFFIC', 'name' => 'Tráfico', 'severity' => 'informational', 'affects_billing_default' => false],
            ['code' => 'WEATHER', 'name' => 'Clima', 'severity' => 'minor', 'affects_billing_default' => false],
            ['code' => 'NO_SHOW', 'name' => 'Cliente No Presentado', 'severity' => 'minor', 'affects_billing_default' => true],
            ['code' => 'OTHER', 'name' => 'Otro', 'severity' => 'informational', 'affects_billing_default' => false],
            // REQ-012 pre-flight decline. Pinned code used by
            // DriverDashboardController::decline() to find-or-create the
            // incident type. Severity maps to 'major' because the domain
            // enum has no 'high' tier; affects_billing is always false
            // since nothing was executed.
            ['code' => 'PREDECL', 'name' => 'Rechazo previo al servicio', 'severity' => 'major', 'affects_billing_default' => false],
        ];

        foreach ($types as $type) {
            IncidentType::firstOrCreate(
                ['code' => $type['code']],
                $type,
            );
        }
    }
};

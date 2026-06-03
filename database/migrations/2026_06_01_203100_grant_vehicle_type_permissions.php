<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

return new class extends Migration
{
    /**
     * @var list<Permission>
     */
    private array $permissions = [
        Permission::VIEW_VEHICLE_TYPES,
        Permission::CREATE_VEHICLE_TYPES,
        Permission::UPDATE_VEHICLE_TYPES,
        Permission::DELETE_VEHICLE_TYPES,
    ];

    /**
     * Register the vehicle-types catalog permissions and grant them to the
     * roles that already manage the Catálogos section (Admin, Operator).
     * Fresh databases get these from the seed_catalog_data migration; this
     * one patches already-migrated databases without resetting any role.
     */
    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            SpatiePermission::firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        $names = array_map(fn (Permission $p) => $p->value, $this->permissions);

        foreach ([Role::ADMIN, Role::OPERATOR] as $role) {
            SpatieRole::where('name', $role->value)->first()?->givePermissionTo($names);
        }

        Artisan::call('permission:cache-reset');
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            SpatiePermission::where('name', $permission->value)->where('guard_name', 'web')->delete();
        }

        Artisan::call('permission:cache-reset');
    }
};

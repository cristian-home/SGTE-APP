<?php

use App\Enums\Role;
use App\Models\User;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Regression for cross-role-ux-qa-audit F-004:
 * every FormRequest referenced snake_case keys that leaked raw English
 * ("El campo vehicle id es obligatorio") to the user. The fix lives in
 * lang/es/validation.php under the `attributes` dictionary. If a compound
 * key is added to a FormRequest without a matching entry here, this test
 * flags it so we don't regress into English leaks again.
 */
beforeEach(function (): void {
    SpatieRole::firstOrCreate(['name' => Role::SUPER_ADMIN->value, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole(Role::SUPER_ADMIN->value);
    $this->actingAs($user);
});

it('renders Spanish attribute names for snake_case service form fields', function (): void {
    $response = $this->post('/services', []);

    $errors = session('errors')->getBag('default')->toArray();

    expect($errors)
        ->toHaveKey('service_date_local')
        ->toHaveKey('contract_id')
        ->toHaveKey('vehicle_id')
        ->toHaveKey('planned_start')
        ->toHaveKey('planned_end_at')
        ->toHaveKey('unit_value');

    // Raw English snake_case must not appear in the rendered messages.
    $joined = implode(' ', array_merge(...array_values($errors)));

    expect($joined)->not->toContain('vehicle id');
    expect($joined)->not->toContain('contract id');
    expect($joined)->not->toContain('service date');
    expect($joined)->not->toContain('planned start');
    expect($joined)->not->toContain('planned end');
    expect($joined)->not->toContain('unit value');

    // Spanish labels from lang/es/validation.php:attributes are present.
    expect($joined)
        ->toContain('fecha del servicio')
        ->toContain('contrato')
        ->toContain('vehículo')
        ->toContain('inicio planificado')
        ->toContain('fecha y hora de fin planificada')
        ->toContain('valor unitario');
});

it('lang/es/validation.php exposes every compound key referenced by a FormRequest', function (): void {
    $map = trans('validation.attributes');

    expect($map)->toBeArray();

    $compoundKeys = [];
    foreach (glob(app_path('Http/Requests/*.php')) as $path) {
        $contents = file_get_contents($path);
        if (preg_match_all("/'([a-z][a-z_]*_[a-z_]+)'\s*=>\s*\[/", $contents, $matches)) {
            $compoundKeys = array_merge($compoundKeys, $matches[1]);
        }
    }

    $compoundKeys = array_values(array_unique($compoundKeys));

    $missing = array_filter(
        $compoundKeys,
        fn (string $key) => ! array_key_exists($key, $map),
    );

    expect($missing)->toBe(
        [],
        'lang/es/validation.php::attributes is missing entries for: '.implode(', ', $missing),
    );
});

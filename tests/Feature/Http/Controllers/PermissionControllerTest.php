<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('admin can view permissions reference', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $response = get(route('permissions.index'));
    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('permissions/index')
            ->has('groups', 19)
    );
});

test('non admin cannot view permissions reference', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(Role::OPERATOR->value);
    actingAs($operator);

    get(route('permissions.index'))->assertForbidden();
});

<?php

namespace Tests\Feature\Support;

use App\Models\Municipality;
use App\Models\User;
use App\Models\Vehicle;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->cityA = Municipality::factory()->create();
    $this->cityB = Municipality::factory()->create();

    Vehicle::factory()->count(3)->create([
        'municipality_id' => $this->cityA->id,
        'status' => 'active',
    ]);
    Vehicle::factory()->count(2)->create([
        'municipality_id' => $this->cityB->id,
        'status' => 'retired',
    ]);
});

test('index exposes per-city facet counts', function (): void {
    get(route('vehicles.index'))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('facetCounts.municipality_id.'.$this->cityA->id, 3)
            ->where('facetCounts.municipality_id.'.$this->cityB->id, 2)
    );
});

test('facet counts respect other active filters', function (): void {
    // Only city A has active vehicles, so filtering by status=active drops
    // city B out of the city counts entirely.
    get(route('vehicles.index', ['filter' => ['status' => 'active']]))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('facetCounts.municipality_id.'.$this->cityA->id, 3)
            ->missing('facetCounts.municipality_id.'.$this->cityB->id)
    );
});

test('facet counts ignore the facet own filter', function (): void {
    // Selecting city A must NOT collapse the city counts to just city A —
    // the user still needs to see city B is available.
    get(route('vehicles.index', ['filter' => ['municipality_id' => (string) $this->cityA->id]]))->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('facetCounts.municipality_id.'.$this->cityA->id, 3)
            ->where('facetCounts.municipality_id.'.$this->cityB->id, 2)
    );
});

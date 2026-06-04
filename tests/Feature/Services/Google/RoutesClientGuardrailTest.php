<?php

use App\Services\Google\RoutesClient;
use Illuminate\Support\Facades\Http;

/**
 * Guards the test suite against billing the real Google Routes API.
 *
 * The suite runs with QUEUE_CONNECTION=sync, so every Service created in a
 * test dispatches FetchServiceRoute inline, which calls RoutesClient. With a
 * non-empty server key that would be a real, billable request on every run.
 * phpunit.xml forces GOOGLE_MAPS_SERVER_KEY to "" so the client short-circuits.
 */
test('routes client is inert in the test environment and never makes a billable call', function (): void {
    // Belt-and-suspenders: if the empty-key guard ever regresses, turn the
    // stray request into a loud failure instead of a charge on the bill.
    Http::preventStrayRequests();

    expect(config('services.google_maps.server_key'))->toBe('');

    $client = new RoutesClient;

    // Bogotá-ish coordinates; must return null via the empty-token guard
    // without ever touching the network.
    expect($client->driving(-74.0763, 4.5984, -74.0648, 4.6291))->toBeNull();
});

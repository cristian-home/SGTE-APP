<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\ServiceStatus;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Database\Factories\Support\RealColombianAddresses;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    /**
     * Keep the planned window consistent and honour the `planned_duration`
     * write-knob that tests/seeders still use for ergonomics. Since
     * `planned_duration` is no longer a column, a caller passing it
     * (e.g. `->create(['planned_start_time' => '09:30', 'planned_duration' => 60])`)
     * sets a stray attribute — we translate it into `planned_end_at` and drop
     * it before persist. When start was overridden without a duration and the
     * end now precedes it, fall back to a sane 60-minute window.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Service $service): void {
            $attributes = $service->getAttributes();
            $passedDuration = array_key_exists('planned_duration', $attributes)
                ? (int) $attributes['planned_duration']
                : null;
            unset($service['planned_duration']);

            if (! $service->planned_start_at instanceof \DateTimeInterface) {
                return;
            }

            $start = CarbonImmutable::instance($service->planned_start_at);

            if ($passedDuration !== null) {
                $service->planned_end_at = $start->addMinutes($passedDuration);

                return;
            }

            $end = $service->planned_end_at instanceof \DateTimeInterface
                ? CarbonImmutable::instance($service->planned_end_at)
                : null;

            if ($end === null || $end->lessThanOrEqualTo($start)) {
                $service->planned_end_at = $start->addMinutes(60);
            }
        });
    }

    /**
     * Model a legacy/location-less service (no origin/destination). Cities
     * are required on new services, so this state exists to exercise the
     * FUEC location gate and graceful-degradation paths.
     */
    public function withoutLocation(): static
    {
        return $this->state(fn (): array => [
            'origin_municipality_id' => null,
            'origin_address' => null,
            'origin_coordinates' => null,
            'origin_coordinates_source' => null,
            'origin_coordinates_accuracy' => null,
            'origin_place_id' => null,
            'destination_municipality_id' => null,
            'destination_address' => null,
            'destination_coordinates' => null,
            'destination_coordinates_source' => null,
            'destination_coordinates_accuracy' => null,
            'destination_place_id' => null,
        ]);
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $timezone = (string) config('app.operation_tz', 'America/Bogota');

        // Compose the planned start as a wall-clock day + time-of-day in the
        // service's own timezone, then convert to a UTC instant. This mirrors
        // how ServiceStoreRequest::prepareForValidation persists production
        // services.
        $day = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');
        $time = fake()->time('H:i');
        $plannedStart = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$day} {$time}", $timezone);
        $plannedStartUtc = $plannedStart->utc();
        $plannedDuration = fake()->numberBetween(30, 480);
        $plannedEndUtc = $plannedStart->addMinutes($plannedDuration)->utc();

        $actualStart = fake()->boolean(40)
            ? $plannedStart->addMinutes(fake()->numberBetween(-15, 30))->utc()
            : null;
        $actualEnd = $actualStart && fake()->boolean(70)
            ? $actualStart->addMinutes(fake()->numberBetween(30, 480))
            : null;

        // Origin / destination: pick two random landmarks from the curated
        // list. Each one already brings (address, coords, source, accuracy,
        // place_id) matching what a real operator would persist via the
        // address autocomplete or the manual pin picker. The city is now
        // required on every service, so both sides always get a location;
        // use the withoutLocation() state to model legacy/location-less rows.
        $originSeed = RealColombianAddresses::random();
        $destinationSeed = RealColombianAddresses::random();

        return [
            'contract_id' => Contract::inRandomOrder()->first()->id ?? Contract::factory(),
            'vehicle_id' => Vehicle::inRandomOrder()->first()->id ?? Vehicle::factory(),
            'driver_id' => Driver::inRandomOrder()->first()->id ?? Driver::factory(),
            'invoice_id' => null,
            'service_date_local' => $day,
            'origin_municipality_id' => self::resolveMunicipalityId($originSeed['municipality_code']),
            'origin_address' => $originSeed['address'],
            'origin_coordinates' => $originSeed['coordinates'],
            'origin_coordinates_source' => $originSeed['source'],
            'origin_coordinates_accuracy' => $originSeed['accuracy'] ?? null,
            'origin_place_id' => $originSeed['place_id'] ?? null,
            'destination_municipality_id' => self::resolveMunicipalityId($destinationSeed['municipality_code']),
            'destination_address' => $destinationSeed['address'],
            'destination_coordinates' => $destinationSeed['coordinates'],
            'destination_coordinates_source' => $destinationSeed['source'],
            'destination_coordinates_accuracy' => $destinationSeed['accuracy'] ?? null,
            'destination_place_id' => $destinationSeed['place_id'] ?? null,
            'planned_start_at' => $plannedStartUtc,
            'planned_end_at' => $plannedEndUtc,
            'actual_start_at' => $actualStart,
            'actual_end_at' => $actualEnd,
            'timezone' => $timezone,
            'unit_value' => fake()->randomFloat(2, 50000, 500000),
            'quantity' => fake()->numberBetween(1, 5),
            'payment_method' => fake()->randomElement(PaymentMethod::cases()),
            // A freshly-created service is open (matches the DB column
            // default); tests needing a closed service set it explicitly.
            'service_status' => ServiceStatus::Open,
        ];
    }

    /**
     * Look up a municipality id by DANE code, falling back to a random
     * municipality (or factory-created) when the test environment hasn't
     * seeded the catalog.
     *
     * No static cache: it survives across `RefreshDatabase` rollbacks and
     * returns ids of rows that have already been undone by the trait's
     * per-test transaction. The result is a sporadic FK violation on the
     * subsequent `services` INSERT — surfaces under CI's test ordering and
     * not consistently on developer machines (file-discovery order differs
     * by filesystem). The lookup is a single indexed SELECT; the cache was
     * a premature optimization.
     */
    private static function resolveMunicipalityId(string $code): int
    {
        return (int) (
            Municipality::where('code', $code)->value('id')
            ?? Municipality::inRandomOrder()->value('id')
            ?? Municipality::factory()->create()->id
        );
    }
}

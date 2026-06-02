<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Requests\DriverInviteAccountRequest;
use App\Http\Requests\DriverStoreRequest;
use App\Http\Requests\DriverUpdateRequest;
use App\Models\DocumentType;
use App\Models\Driver;
use App\Models\Eps;
use App\Models\Municipality;
use App\Models\PensionFund;
use App\Models\Service;
use App\Models\SeveranceFund;
use App\Models\User;
use App\Notifications\DriverAccountInvitationNotification;
use App\Support\FacetCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class DriverController extends Controller
{
    /**
     * Days-ahead window used by the license_status filter to flag
     * "por vencer" licenses. Mirrors the dashboard threshold and the
     * matching constant in VehicleController.
     */
    private const LICENSE_EXPIRY_WINDOW_DAYS = 30;

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_DRIVERS->value);

        $drivers = QueryBuilder::for(Driver::class)
            ->with([
                'municipality:id,name,department_id',
                'municipality.department:id,name',
                'documentType:id,code',
                'user:id,name,email',
            ])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts(['first_name', 'first_lastname', 'municipality_id', 'license_due_at', 'active'])
            ->defaultSort('first_lastname')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($drivers);
        }

        return Inertia::render('drivers/index', [
            'drivers' => $drivers,
            'municipalities' => $this->municipalitiesPayload(),
            'facetCounts' => FacetCounts::for(
                Driver::class,
                $this->allowedFilters(),
                $request,
                ['municipality_id' => 'municipality_id'],
            ),
            'documentTypes' => DocumentType::orderBy('code')->get(['id', 'code', 'name']),
            'eps' => Eps::orderBy('name')->get(['id', 'code', 'name']),
            'pensionFunds' => PensionFund::orderBy('name')->get(['id', 'code', 'name']),
            'severanceFunds' => SeveranceFund::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Filter drivers by the aggregate state of their single legal
     * document (license_due_date).
     *
     * - expired       → license is null or strictly before today
     * - expiring_soon → license is within [today, today+30]
     * - ok            → license is more than 30 days out
     */
    private function applyLicenseStatusFilter(Builder $query, string $value): void
    {
        $now = Carbon::now((string) config('app.operation_tz'));
        $nowInstant = $now->copy()->utc();
        $thresholdInstant = $now->copy()->addDays(self::LICENSE_EXPIRY_WINDOW_DAYS)->utc();

        match ($value) {
            'expired' => $query->where(function (Builder $q) use ($nowInstant): void {
                $q->whereNull('license_due_at')
                    ->orWhere('license_due_at', '<=', $nowInstant);
            }),
            'expiring_soon' => $query
                ->whereNotNull('license_due_at')
                ->whereBetween('license_due_at', [$nowInstant, $thresholdInstant]),
            'ok' => $query
                ->whereNotNull('license_due_at')
                ->where('license_due_at', '>', $thresholdInstant),
            default => null, // ignore unknown values
        };
    }

    /**
     * Shared municipality payload — eager-loads department for the
     * combobox grouping and sorts by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Municipality>
     */
    /**
     * QueryBuilder filters shared by index() and the facet-count helper.
     *
     * @return array<int, mixed>
     */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
            'identification_number',
            'first_name',
            'first_lastname',
            AllowedFilter::exact('municipality_id'),
            AllowedFilter::exact('license_category'),
            AllowedFilter::exact('active'),
            AllowedFilter::exact('has_social_security'),
            AllowedFilter::callback('license_status', function (Builder $query, $value) {
                // Faceted filter UI is multi-select but license_status is
                // semantically single-select. Honor the first comma-
                // separated value (mirrors VehicleController docs_status).
                $first = is_array($value) ? ($value[0] ?? '') : explode(',', (string) $value)[0];
                $this->applyLicenseStatusFilter($query, $first);
            }),
        ];
    }

    private function municipalitiesPayload(): \Illuminate\Database\Eloquent\Collection
    {
        return Municipality::query()
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id']);
    }

    public function store(DriverStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_DRIVERS->value);

        $data = $request->validated();
        $createAccount = (bool) ($data['create_account'] ?? false);
        $accountEmail = $data['account_email'] ?? null;
        unset($data['create_account'], $data['account_email']);

        $driver = DB::transaction(function () use ($data, $createAccount, $accountEmail): Driver {
            $driver = Driver::create($data);

            if ($createAccount) {
                $user = User::create([
                    'name' => $driver->fullName(),
                    'email' => $accountEmail,
                    'password' => Hash::make(Str::password(32)),
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
                $user->markEmailAsVerified();
                $user->syncRoles([Role::DRIVER->value]);
                $driver->forceFill(['user_id' => $user->id])->saveQuietly();
                $user->notify(new DriverAccountInvitationNotification);
            }

            return $driver;
        });

        $message = $createAccount
            ? 'Conductor creado. Se envió el enlace de configuración al correo.'
            : 'Conductor creado.';

        return back()->with('success', $message);
    }

    public function show(Request $request, Driver $driver): Response
    {
        Gate::authorize(Permission::VIEW_DRIVERS->value);

        $driver->load([
            'municipality:id,name,department_id',
            'municipality.department:id,name',
            'documentType:id,code,name',
            'eps:id,code,name',
            'pensionFund:id,code,name',
            'severanceFund:id,code,name',
            'user:id,name,email,is_active',
        ]);

        $recentServices = Service::query()
            ->where('driver_id', $driver->id)
            ->with([
                'vehicle:id,plate,internal_code',
                'contract:id,contract_number,third_party_id',
                'contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('planned_start_at')
            ->limit(5)
            ->get();

        return Inertia::render('drivers/show', [
            'driver' => $driver,
            'recentServices' => $recentServices,
            'municipalities' => $this->municipalitiesPayload(),
            'documentTypes' => DocumentType::orderBy('code')->get(['id', 'code', 'name']),
            'eps' => Eps::orderBy('name')->get(['id', 'code', 'name']),
            'pensionFunds' => PensionFund::orderBy('name')->get(['id', 'code', 'name']),
            'severanceFunds' => SeveranceFund::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(DriverUpdateRequest $request, Driver $driver): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_DRIVERS->value);
        $driver->update($request->validated());

        return back()->with('success', 'Conductor actualizado.');
    }

    public function destroy(Request $request, Driver $driver): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_DRIVERS->value);
        $driver->delete();

        return redirect()->route('drivers.index');
    }

    /**
     * Crea una cuenta de acceso para un Driver que se creó previamente sin
     * cuenta. Asocia un User nuevo, asigna rol Driver y envía la invitación.
     */
    public function inviteAccount(DriverInviteAccountRequest $request, Driver $driver): RedirectResponse
    {
        if ($driver->user_id !== null) {
            throw ValidationException::withMessages([
                'account_email' => 'Este conductor ya tiene una cuenta de acceso.',
            ]);
        }

        $accountEmail = $request->validated('account_email');

        DB::transaction(function () use ($driver, $accountEmail): void {
            $user = User::create([
                'name' => $driver->fullName(),
                'email' => $accountEmail,
                'password' => Hash::make(Str::password(32)),
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $user->markEmailAsVerified();
            $user->syncRoles([Role::DRIVER->value]);
            $driver->forceFill(['user_id' => $user->id])->saveQuietly();
            $user->notify(new DriverAccountInvitationNotification);
        });

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Se envió el enlace de configuración al correo del conductor.');
    }

    /**
     * Reenvía la invitación a un Driver que ya tiene cuenta — útil cuando el
     * token original expira (60 min por defecto).
     */
    public function resendInvitation(Request $request, Driver $driver): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_DRIVERS->value);

        if ($driver->user_id === null || ! $driver->user) {
            throw ValidationException::withMessages([
                'driver' => 'Este conductor no tiene cuenta de acceso. Crea una primero.',
            ]);
        }

        $driver->user->notify(new DriverAccountInvitationNotification);

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Se reenvió el enlace de configuración al correo del conductor.');
    }
}

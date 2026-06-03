<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Http\Requests\UserResetPasswordRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserToggleActiveRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\User;
use App\Notifications\WelcomeUserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_USERS->value);

        $users = QueryBuilder::for(User::class)
            ->with('roles:id,name')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, $value): void {
                    $needle = '%'.mb_strtolower((string) $value).'%';
                    $query->where(function (Builder $q) use ($needle): void {
                        $q->whereRaw('lower(name) like ?', [$needle])
                            ->orWhereRaw('lower(email) like ?', [$needle]);
                    });
                }),
                AllowedFilter::callback('roles', function (Builder $query, $value): void {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_filter(array_map('trim', $values));
                    if ($values === []) {
                        return;
                    }
                    $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $values));
                }),
                AllowedFilter::callback('is_active', function (Builder $query, $value): void {
                    $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOL));
                }),
            ])
            ->allowedSorts(...[
                'name',
                'email',
                'last_login_at',
                'created_at',
                AllowedSort::field('is_active'),
            ])
            ->defaultSort('name')
            ->paginate($request->perPage(10))
            ->withQueryString();

        $users->through(fn (User $user): array => $this->serializeUser($user));

        if ($request->wantsJson()) {
            return response()->json($users);
        }

        return Inertia::render('users/index', [
            'users' => $users,
            'availableRoles' => $this->availableRoles(),
        ]);
    }

    public function store(UserStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $sendWelcome = (bool) ($data['send_welcome_email'] ?? false);

        $temporaryPassword = $sendWelcome ? Str::password(16) : $data['password'];

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($temporaryPassword),
            'is_active' => $data['is_active'],
            'must_change_password' => $sendWelcome,
        ]);

        $user->markEmailAsVerified();
        $user->syncRoles($data['roles']);

        if ($sendWelcome) {
            $user->notify(new WelcomeUserNotification);
        }

        return redirect()->route('users.index')->with('success', 'Usuario creado.');
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $oldRoles = $user->roles->pluck('name')->sort()->values()->all();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ])->save();

        $user->syncRoles($data['roles']);

        $newRoles = $user->roles->pluck('name')->sort()->values()->all();

        if ($oldRoles !== $newRoles) {
            activity()
                ->performedOn($user)
                ->causedBy($request->user())
                ->withProperties([
                    'old_roles' => $oldRoles,
                    'new_roles' => $newRoles,
                ])
                ->event('roles_synced')
                ->log('roles_synced');
        }

        return redirect()->route('users.index')->with('success', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_USERS->value);

        if ($user->hasRole(RoleEnum::SUPER_ADMIN->value)
            && ! $request->user()?->hasRole(RoleEnum::SUPER_ADMIN->value)
        ) {
            abort(403);
        }

        if ($request->user()?->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'No puedes eliminar tu propia cuenta.',
            ]);
        }

        if ($user->hasRole(RoleEnum::ADMIN->value)
            && User::role(RoleEnum::ADMIN->value)->where('id', '!=', $user->id)->count() === 0
        ) {
            throw ValidationException::withMessages([
                'user' => 'No puedes eliminar al último administrador del sistema.',
            ]);
        }

        if ($user->driver !== null) {
            throw ValidationException::withMessages([
                'user' => 'Este usuario está vinculado a un conductor. Elimina el conductor desde el módulo Conductores.',
            ]);
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'Usuario eliminado.');
    }

    public function toggleActive(UserToggleActiveRequest $request, User $user): RedirectResponse|JsonResponse
    {
        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $message = $user->is_active ? 'Usuario activado.' : 'Usuario desactivado.';

        if ($request->wantsJson()) {
            return response()->json([
                'user' => $this->serializeUser($user->fresh()->load('roles:id,name')),
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function resetPassword(UserResetPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => Hash::make(Str::password(16)),
            'must_change_password' => true,
        ])->save();

        Password::sendResetLink(['email' => $user->email]);

        return redirect()->back()->with('success', 'Se envió un enlace de restablecimiento al correo del usuario.');
    }

    /**
     * Roles an admin can assign from this screen. Super admin is excluded
     * because it is bootstrapped via the SUPER_ADMIN_USER env + bypasses
     * all gates — not something we expose through a form. Driver is also
     * excluded: el rol Driver requiere un registro Driver vinculado y se
     * asigna exclusivamente desde el módulo Conductores.
     *
     * @return list<array{value: string, label: string}>
     */
    private function availableRoles(): array
    {
        return [
            ['value' => RoleEnum::ADMIN->value, 'label' => RoleEnum::ADMIN->label()],
            ['value' => RoleEnum::OPERATOR->value, 'label' => RoleEnum::OPERATOR->label()],
            ['value' => RoleEnum::ACCOUNTING->value, 'label' => RoleEnum::ACCOUNTING->label()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $this->roleLabel($role->name),
            ])->values()->all(),
        ];
    }

    private function roleLabel(string $name): string
    {
        return RoleEnum::tryFrom($name)?->label() ?? $name;
    }
}

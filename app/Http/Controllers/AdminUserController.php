<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Superadmin-only user management (stricter than the isStaff() gate used
 * elsewhere): create/edit/deactivate/reactivate accounts and reset passwords.
 * Guards protect against locking yourself out (self role-change) and against
 * leaving the platform without any active superadmin.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $status = (string) $request->query('status', 'active');
        $role = (string) $request->query('role', '');
        $companyId = $request->query('company', '');
        $q = trim((string) $request->query('q', ''));

        $paginator = User::query()
            ->when($status === 'deactivated', fn ($qr) => $qr->onlyTrashed())
            ->when($status === 'all', fn ($qr) => $qr->withTrashed())
            ->when(
                in_array($role, ['buyer', 'staff_admin', 'superadmin'], true),
                fn ($qr) => $qr->where('role', $role),
            )
            ->when($companyId !== '', fn ($qr) => $qr->where('company_id', $companyId))
            ->when($q !== '', fn ($qr) => $qr->where(function ($w) use ($q): void {
                $like = '%'.mb_strtolower($q).'%';
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            }))
            ->with('company:id,name')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u): array => $this->serialize($u)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function companies(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return response()->json([
            'data' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(['buyer', 'staff_admin', 'superadmin'])],
            'company_id' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === 'buyer'),
                'nullable',
                'exists:companies,id',
            ],
        ]);

        // Buyers keep their company_id; staff/superadmin are always company-less.
        $companyId = $validated['role'] === 'buyer' ? ($validated['company_id'] ?? null) : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'company_id' => $companyId,
        ]);

        $this->audit->log($user, 'user.created', null, ['email' => $user->email, 'role' => $user->role->value]);

        return response()->json(['data' => $this->serialize($user->fresh('company'))], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $user->load('company:id,name');

        return response()->json(['data' => $this->serialize($user)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::in(['buyer', 'staff_admin', 'superadmin'])],
            'company_id' => ['nullable', 'exists:companies,id'],
            // Granular access allowlist. Every entry must be a known permission
            // key; unknown keys are rejected rather than silently stored.
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        if (array_key_exists('role', $validated) && $this->isSelf($request, $user)) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        if (array_key_exists('role', $validated) && $this->isLastSuperadmin($user) && $validated['role'] !== UserRole::Superadmin->value) {
            return response()->json(['message' => 'Cannot change the role of the last active superadmin.'], 422);
        }

        $resultingRole = $validated['role'] ?? $user->role->value;

        if ($resultingRole === 'buyer') {
            $resultingCompanyId = $validated['company_id'] ?? $user->company_id;

            if ($resultingCompanyId === null) {
                return response()->json(['message' => 'company_id is required for the buyer role.'], 422);
            }

            $validated['company_id'] = $resultingCompanyId;
        } else {
            $validated['company_id'] = null;
        }

        // Granular access only means anything for a staff_admin. If permissions
        // were sent, store them (deduped) only when the resulting role is
        // staff_admin; a superadmin/buyer always resolves to null (full/none by
        // role). And a role moving OFF staff_admin drops any stale allowlist.
        if (array_key_exists('permissions', $validated)) {
            $validated['permissions'] = $resultingRole === 'staff_admin'
                ? array_values(array_unique($validated['permissions']))
                : null;
        } elseif ($resultingRole !== 'staff_admin') {
            $validated['permissions'] = null;
        }

        $before = [
            'role' => $user->role->value,
            'email' => $user->email,
            'permissions' => $user->permissions,
        ];
        $user->fill($validated);
        $user->save();

        $this->audit->log($user, 'user.updated', $before, [
            'role' => $user->role->value,
            'email' => $user->email,
            'permissions' => $user->permissions,
        ]);

        return response()->json(['data' => $this->serialize($user->fresh('company'))]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        if ($this->isSelf($request, $user)) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ($this->isLastSuperadmin($user)) {
            return response()->json(['message' => 'Cannot deactivate the last active superadmin.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        $this->audit->log($user, 'user.deactivated', ['active' => true], ['active' => false]);

        return response()->json(['data' => $this->serialize($user->fresh('company'))]);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        if (! $user->trashed()) {
            return response()->json(['message' => 'User is not deactivated.'], 422);
        }

        $user->restore();

        $this->audit->log($user, 'user.reactivated', ['active' => false], ['active' => true]);

        return response()->json(['data' => $this->serialize($user->fresh('company'))]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Never log the password value itself - only that a reset occurred.
        $this->audit->log($user, 'user.password_reset', null, null);

        return response()->json(['data' => $this->serialize($user->fresh('company'))]);
    }

    private function isSelf(Request $request, User $user): bool
    {
        return $request->user()->id === $user->id;
    }

    private function activeSuperadminCount(): int
    {
        // Default query scope excludes soft-deleted rows, so this only counts
        // active (non-deactivated) superadmins.
        return User::where('role', UserRole::Superadmin->value)->count();
    }

    private function isLastSuperadmin(User $user): bool
    {
        return $user->role === UserRole::Superadmin && ! $user->trashed() && $this->activeSuperadminCount() <= 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'company' => $user->relationLoaded('company') && $user->company !== null
                ? ['id' => $user->company->id, 'name' => $user->company->name]
                : null,
            'active' => ! $user->trashed(),
            'created_at' => $user->created_at?->toIso8601String(),
            // Effective access. For a staff_admin this is the granted allowlist
            // (or everything, if never restricted); superadmin is everything,
            // buyers nothing. The access table checks these boxes.
            'permissions' => $user->effectivePermissions(),
            // Whether the granular allowlist even applies to this user - only
            // staff_admin can be restricted, so only they get an editable table.
            'permissions_editable' => $user->role === UserRole::StaffAdmin,
        ];
    }

    /**
     * The catalogue of grantable permissions, grouped for the access table.
     */
    public function permissionCatalog(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return response()->json(['data' => Permissions::CATALOG]);
    }
}

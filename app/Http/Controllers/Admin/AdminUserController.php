<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserIndexRequest;
use App\Http\Requests\Admin\ChangeAdminUserPasswordRequest;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(AdminUserIndexRequest $request)
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $query = User::query()
            ->where('store_id', (int) config('provider-auth.store_id'));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", [$like]);
            });
        }

        return AdminUserResource::collection(
            $query->orderByDesc('id')->paginate($filters['per_page'] ?? 20)
        );
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();

        $user = User::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
            'user_type' => $validated['user_type'] ?? User::ADMIN_USER_TYPE,
            'status' => User::ACTIVE_STATUS,
            'store_id' => (int) config('provider-auth.store_id'),
            'two_factor_enabled' => false,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => new AdminUserResource($user),
        ], 201);
    }

    public function update(UpdateAdminUserRequest $request, User $user): JsonResponse
    {
        abort_unless($this->sameProviderStore($user), 404);

        $this->authorize('update', $user);

        $validated = $request->validated();

        if (array_key_exists('email', $validated)) {
            $validated['email'] = strtolower(trim((string) $validated['email']));
        }

        $user->fill($validated);
        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new AdminUserResource($user->fresh()),
        ]);
    }

    public function changePassword(ChangeAdminUserPasswordRequest $request, User $user): JsonResponse
    {
        abort_unless($this->sameProviderStore($user), 404);

        $this->authorize('changePassword', $user);

        $user->password = $request->validated('password');
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully.',
            'data' => new AdminUserResource($user->fresh()),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($this->sameProviderStore($user), 404);

        $this->authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($user->isAdmin() && $this->isLastAdminInStore($user)) {
            return response()->json([
                'message' => 'Cannot delete the last admin user for this store.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    private function sameProviderStore(User $user): bool
    {
        return $user->store_id === (int) config('provider-auth.store_id');
    }

    private function isLastAdminInStore(User $user): bool
    {
        return User::query()
            ->where('store_id', $user->store_id)
            ->where('user_type', User::ADMIN_USER_TYPE)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}

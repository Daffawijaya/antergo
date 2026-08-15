<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ExpoPushNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $metrics = [
            'total_users' => User::count(),
            'total_customers' => UserRole::where('role', UserRole::CUSTOMER)->count(),
            'total_drivers' => Driver::count(),
            'pending_drivers' => Driver::where('status', 'pending')->count(),
            'approved_drivers' => Driver::where('status', 'approved')->count(),
            'total_merchants' => Merchant::count(),
            'active_merchants' => Merchant::where('is_active', true)->count(),
            'total_orders' => Order::count(),
            'total_ride' => Order::where('type', Order::TYPE_RIDE)->count(),
            'total_food' => Order::where('type', Order::TYPE_FOOD)->count(),
            'total_send' => Order::where('type', Order::TYPE_SEND)->count(),
            'completed_orders' => Order::where('status', Order::STATUS_COMPLETED)->count(),
            'cancelled_orders' => Order::where('status', Order::STATUS_CANCELLED)->count(),
            'total_gmv' => (float) Order::where('status', Order::STATUS_COMPLETED)->sum('total_price'),
            'total_paid_cash' => (float) Payment::where('method', 'cash')->where('status', 'paid')->sum('amount'),
            'unpaid_completed_orders' => Order::where('status', Order::STATUS_COMPLETED)
                ->where('payment_status', '!=', 'paid')->count(),
        ];

        $recentOrders = Order::query()
            ->with($this->orderListRelations())
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Order $order) => $this->orderSummary($order));

        return response()->json(['metrics' => $metrics, 'recent_orders' => $recentOrders]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $users = User::query()
            ->select($this->safeUserColumns())
            ->with(['roles:id,user_id,role'])
            ->withCount('orders')
            ->when($validated['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', $this->searchOperator(), "%{$search}%")
                        ->orWhere('email', $this->searchOperator(), "%{$search}%")
                        ->orWhere('phone', $this->searchOperator(), "%{$search}%");
                });
            })
            ->when(array_key_exists('active', $validated), fn (Builder $query) => $query->where('is_active', $validated['active']))
            ->latest()
            ->paginate($validated['per_page'] ?? 20);
        $users->through(fn (User $user) => $this->userSummary($user));

        return response()->json($users);
    }

    public function user(User $user): JsonResponse
    {
        $user->load([
            'roles:id,user_id,role',
            'driver.vehicle',
            'merchant.category',
        ])->loadCount('orders');

        return response()->json(['user' => $this->userDetail($user)]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        if ($request->user()->is($user) && ! $validated['is_active']) {
            return response()->json(['message' => 'You cannot deactivate your own admin account.'], 422);
        }
        $user->update(['is_active' => $validated['is_active']]);
        if (! $validated['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'User status updated.', 'user' => $this->userSummary($user->load('roles'))]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $drivers = Driver::query()
            ->with(['user' => fn ($query) => $query->select($this->safeUserColumns()), 'vehicle'])
            ->when($validated['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->whereHas('user', fn (Builder $query) => $query
                        ->where('name', $this->searchOperator(), "%{$search}%")
                        ->orWhere('email', $this->searchOperator(), "%{$search}%")
                        ->orWhere('phone', $this->searchOperator(), "%{$search}%"))
                        ->orWhere('nik', $this->searchOperator(), "%{$search}%")
                        ->orWhere('license_number', $this->searchOperator(), "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($drivers);
    }

    public function driver(Driver $driver): JsonResponse
    {
        return response()->json(['driver' => $driver->load([
            'user' => fn ($query) => $query->select($this->safeUserColumns())->with('roles:id,user_id,role'),
            'vehicle',
            'location',
        ])]);
    }

    public function updateDriverStatus(Request $request, Driver $driver, ExpoPushNotificationService $push): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected', 'suspended'])]]);
        if ($validated['status'] === 'approved') {
            $driver->load(['user', 'vehicles', 'documents']);
            $types = $driver->documents->pluck('type');
            $complete = $driver->user->getRawOriginal('avatar')
                && $types->contains('ktp') && $driver->vehicles->isNotEmpty()
                && $driver->vehicles->every(fn ($vehicle) => $vehicle->image_path
                    && $types->contains($vehicle->type === 'car' ? 'sim_a' : 'sim_c'));
            if (! $complete) {
                return response()->json(['message' => 'Driver documents and vehicles are incomplete.'], 422);
            }
        }

        DB::transaction(function () use ($driver, $validated) {
            $driver->update([
                'status' => $validated['status'],
                'is_online' => $validated['status'] === 'approved' ? $driver->is_online : false,
            ]);
            if ($validated['status'] === 'approved') {
                $driver->user->addRole(UserRole::DRIVER);
            }
        });
        $messages = [
            'approved' => ['Driver disetujui', 'Akun driver Anda telah disetujui.'],
            'rejected' => ['Pengajuan driver ditolak', 'Pengajuan driver Anda belum dapat disetujui.'],
            'suspended' => ['Akun driver ditangguhkan', 'Akses operasional driver Anda telah ditangguhkan.'],
        ];
        [$title, $body] = $messages[$validated['status']];
        $push->notify($driver->user, "driver_{$validated['status']}", $title, $body, [
            'screen' => 'driver-profile',
            'status' => $validated['status'],
        ]);

        return response()->json(['message' => 'Driver status updated.', 'driver' => $driver->fresh(['user.roles', 'vehicle'])]);
    }

    public function merchants(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $merchants = Merchant::query()
            ->with(['user' => fn ($query) => $query->select($this->safeUserColumns()), 'category'])
            ->withCount(['products', 'orders'])
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('name', $this->searchOperator(), "%{$search}%")
                    ->orWhere('phone', $this->searchOperator(), "%{$search}%")
                    ->orWhereHas('user', fn (Builder $query) => $query->where('email', $this->searchOperator(), "%{$search}%"))))
            ->when(array_key_exists('active', $validated), fn (Builder $query) => $query->where('is_active', $validated['active']))
            ->when($validated['category_id'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($merchants);
    }

    public function merchant(Merchant $merchant): JsonResponse
    {
        return response()->json(['merchant' => $merchant->load([
            'user' => fn ($query) => $query->select($this->safeUserColumns())->with('roles:id,user_id,role'),
            'category', 'products',
        ])->loadCount('orders')]);
    }

    public function updateMerchantStatus(Request $request, Merchant $merchant, ExpoPushNotificationService $push): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $merchant->update([
            'is_active' => $validated['is_active'],
            'is_open' => $validated['is_active'] ? $merchant->is_open : false,
        ]);
        $enabled = $validated['is_active'];
        $push->notify(
            $merchant->user,
            $enabled ? 'merchant_activated' : 'merchant_deactivated',
            $enabled ? 'Merchant diaktifkan' : 'Merchant dinonaktifkan',
            $enabled ? 'Merchant Anda telah diaktifkan.' : 'Merchant Anda telah dinonaktifkan sementara.',
            ['screen' => 'merchant-profile', 'is_active' => $enabled],
        );

        return response()->json(['message' => 'Merchant status updated.', 'merchant' => $merchant->fresh(['user', 'category'])]);
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in([Order::TYPE_RIDE, Order::TYPE_FOOD, Order::TYPE_SEND])],
            'status' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', Rule::in(['pending', 'paid', 'failed', 'refunded'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $orders = Order::query()
            ->with($this->orderListRelations())
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query->where('order_number', $this->searchOperator(), "%{$search}%"))
            ->when($validated['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('payment_status', $status))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate($validated['per_page'] ?? 20);
        $orders->through(fn (Order $order) => $this->orderSummary($order));

        return response()->json($orders);
    }

    public function order(Order $order): JsonResponse
    {
        $order->load([
            ...$this->orderListRelations(),
            'items.product:id,name',
            'payment',
            'rating',
            'statusHistories' => fn ($query) => $query->oldest('created_at'),
        ]);

        return response()->json(['order' => $order]);
    }

    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function safeUserColumns(): array
    {
        return ['id', 'name', 'email', 'phone', 'avatar', 'is_active', 'created_at', 'updated_at'];
    }

    private function userSummary(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone, 'avatar' => $user->avatar, 'is_active' => $user->is_active,
            'roles' => $user->roles->pluck('role')->values()->all(),
            'orders_count' => $user->orders_count ?? null,
            'created_at' => $user->created_at, 'updated_at' => $user->updated_at,
        ];
    }

    private function userDetail(User $user): array
    {
        return [...$this->userSummary($user), 'driver' => $user->driver, 'merchant' => $user->merchant];
    }

    private function orderListRelations(): array
    {
        return [
            'user' => fn ($query) => $query->select($this->safeUserColumns()),
            'driver.user' => fn ($query) => $query->select($this->safeUserColumns()),
            'merchant:id,user_id,name,is_active,is_open',
        ];
    }

    private function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'type' => $order->type,
            'status' => $order->status, 'payment_status' => $order->payment_status,
            'customer' => $order->user, 'driver' => $order->driver, 'merchant' => $order->merchant,
            'total_price' => $order->total_price, 'created_at' => $order->created_at,
        ];
    }
}

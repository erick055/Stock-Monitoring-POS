<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturn;
use App\Models\DamagedGood;
use App\Models\HeldOrder;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(): View
    {
        $user = request()->user();
        $products = Product::query()->where('is_active', true)->get();
        $lowStock = $products->filter(fn (Product $product) => $product->current_stock <= $product->reorder_level);
        $monthSales = $this->salesBetween(now()->startOfMonth(), now()->endOfMonth());
        $previousMonthSales = $this->salesBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        $dashboard = [
            ...$this->identity($user, 'Administrator', 'Monitor live inventory, POS sales, alerts, and business operations.'),
            'navigation' => [
                ['⌂', 'Dashboard', '#'], ['▣', 'Stock Management', '/admin/inventory'], ['□', 'Products', '/admin/products'],
                ['⌁', 'Analytics', '/admin/analytics'], ['!', 'Low Stock Alerts', '/admin/low-stocks'], ['@', 'Dead Stock', '/admin/deadstock'],
                ['◇', 'Returns & Damages', '/admin/returns'], ['♙', 'Supplier Price', '/admin/suppliers'], ['⚙', 'Part Compatibility', '/admin/compatibility'],
            ],
            'stats' => [
                ['TOTAL PRODUCTS', number_format($products->count()), $products->filter(fn (Product $product) => $product->created_at?->isCurrentMonth())->count().' added this month', 'purple'],
                ['TOTAL STOCK', number_format($products->sum('current_stock')), $products->whereNotNull('category')->pluck('category')->map(fn ($category) => mb_strtolower(trim($category)))->unique()->count().' categories', 'violet'],
                ['LOW STOCK ITEMS', number_format($lowStock->count()), $lowStock->where('current_stock', 0)->count().' currently out of stock', 'red'],
                ['MONTH SALES', '₱'.number_format($monthSales, 2), $this->comparison($monthSales, $previousMonthSales), 'cyan'],
            ],
            'modules_title' => 'System Modules',
            'modules' => [
                ['▣', 'Stock Management', number_format($products->sum('current_stock')).' units currently recorded', '/admin/inventory'],
                ['□', 'Product Catalog', number_format($products->count()).' active products', '/admin/products'],
                ['⌁', 'Data Analytics', '₱'.number_format($monthSales, 2).' sales this month', '/admin/analytics'],
                ['!', 'Low Stock Alerts', number_format($lowStock->count()).' products need attention', '/admin/low-stocks'],
                ['◉', 'Dead Stock Detection', 'Review inventory unlikely to sell', '/admin/deadstock'],
                ['◇', 'Returns & Damages', CustomerReturn::whereDate('returned_at', today())->count().' return(s) today', '/admin/returns'],
                ['♧', 'Supplier Price', 'Import and compare supplier pricing', '/admin/suppliers'],
                ['⚙', 'Part Compatibility', 'Search current inventory with AI', '/admin/compatibility'],
            ],
            'chart_kicker' => 'PERFORMANCE',
            'chart_title' => 'Sales — Last 7 Days',
            'chart' => $this->weeklySales(),
            'activity' => $this->recentActivity(),
            'notification_count' => $lowStock->count(),
            'notification_url' => route('admin.low-stocks'),
        ];

        return view('admin.dashboard', compact('dashboard'));
    }

    public function staff(): View
    {
        $user = request()->user();
        $todaySales = SalesTransaction::query()->where('staff_id', $user->id)->whereDate('sale_date', today());
        $todayRevenue = (clone $todaySales)->sum('total_sale_amount');
        $todayTransactions = (clone $todaySales)->count();
        $todayItems = SalesItem::query()
            ->whereHas('sale', fn ($query) => $query->where('staff_id', $user->id)->whereDate('sale_date', today()))
            ->sum('quantity');
        $lowStockCount = Product::query()->where('is_active', true)->whereColumn('current_stock', '<=', 'reorder_level')->count();
        $heldCount = HeldOrder::query()->where('staff_id', $user->id)->where('status', 'held')->count();

        $dashboard = [
            ...$this->identity($user, 'Staff', 'Track your live shift sales, orders, and inventory work.'),
            'navigation' => [
                ['⌂', 'Dashboard', '#'], ['□', 'Products', '/staff/products'],
                ['▤', 'POS Checkout', '/staff/pos'], ['◇', 'Return & Damage', '/staff/returns'], ['⚙', 'Part Compatibility', '/staff/compatibility'],
            ],
            'stats' => [
                ['MY SALES TODAY', '₱'.number_format($todayRevenue, 2), number_format($todayTransactions).' completed transaction(s)', 'cyan'],
                ['ITEMS SOLD', number_format($todayItems), 'From your POS transactions today', 'purple'],
                ['LOW STOCK ITEMS', number_format($lowStockCount), 'Visible for immediate reporting', 'red'],
                ['MY HELD ORDERS', number_format($heldCount), 'Waiting to resume in POS', 'orange'],
            ],
            'modules_title' => 'My Work Modules',
            'modules' => [
                ['▤', 'POS Checkout', number_format($heldCount).' held order(s) waiting', '/staff/pos'],
                ['□', 'Product Catalog', 'View current products and stock levels', '/staff/products'],
                ['◇', 'Return & Damage', CustomerReturn::where('user_id', $user->id)->whereDate('returned_at', today())->count().' return(s) handled today', '/staff/returns'],
                ['⚙', 'Part Compatibility', 'Search available inventory with AI', '/staff/compatibility'],
            ],
            'chart_kicker' => 'MY PERFORMANCE',
            'chart_title' => 'My Sales — Last 7 Days',
            'chart' => $this->weeklySales($user->id),
            'activity' => $this->recentActivity($user->id),
            'notification_count' => $lowStockCount + $heldCount,
            'notification_url' => $heldCount > 0 ? route('staff.pos') : route('staff.products'),
        ];

        return view('staff.dashboard', compact('dashboard'));
    }

    private function identity(User $user, string $role, string $description): array
    {
        $words = preg_split('/\s+/u', trim($user->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['U'];

        return [
            'role_name' => $role,
            'display_name' => $user->name,
            'first_name' => $words[0],
            'initials' => collect($words)->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->join(''),
            'description' => $description,
            'updated_at' => now(),
        ];
    }

    private function salesBetween(Carbon $from, Carbon $to, ?int $staffId = null): float
    {
        return (float) SalesTransaction::query()
            ->when($staffId, fn ($query) => $query->where('staff_id', $staffId))
            ->whereBetween('sale_date', [$from, $to])
            ->sum('total_sale_amount');
    }

    private function weeklySales(?int $staffId = null): array
    {
        $values = collect(range(6, 0))->map(function (int $daysAgo) use ($staffId) {
            $date = now()->subDays($daysAgo);

            return [
                'label' => $date->format('D'),
                'date' => $date->format('M d'),
                'value' => $this->salesBetween($date->copy()->startOfDay(), $date->copy()->endOfDay(), $staffId),
            ];
        });
        $maximum = max(1, (float) $values->max('value'));

        return $values->map(fn ($day) => [
            ...$day,
            'height' => $day['value'] > 0 ? max(8, round(($day['value'] / $maximum) * 100, 2)) : 0,
        ])->all();
    }

    private function recentActivity(?int $userId = null): array
    {
        $sales = SalesTransaction::query()->with('staff')
            ->when($userId, fn ($query) => $query->where('staff_id', $userId))
            ->latest('sale_date')->limit(5)->get()
            ->map(fn (SalesTransaction $sale) => [
                'color' => 'cyan', 'title' => 'Sale completed',
                'detail' => 'Receipt #'.str_pad((string) $sale->sale_id, 8, '0', STR_PAD_LEFT).' · ₱'.number_format((float) $sale->total_sale_amount, 2).($userId ? '' : ' · '.($sale->staff?->name ?? 'System')),
                'occurred_at' => $sale->sale_date,
                'url' => $userId ? route('staff.pos.receipts.show', $sale) : route('admin.analytics'),
            ]);
        $movements = InventoryLedger::query()->with(['product', 'user'])
            ->when($userId !== null, fn ($query) => $query->whereRaw('1 = 0'))
            ->latest('created_at')->limit(5)->get()
            ->map(fn (InventoryLedger $ledger) => [
                'color' => $ledger->qty_in > 0 ? 'purple' : 'orange',
                'title' => $ledger->qty_in > 0 ? 'Stock added' : 'Stock released',
                'detail' => ($ledger->product?->name ?? 'Product').' · '.number_format($ledger->qty_in ?: $ledger->qty_out).' unit(s)'.($userId ? '' : ' · '.($ledger->user?->name ?? 'System')),
                'occurred_at' => $ledger->created_at,
                'url' => route('admin.inventory'),
            ]);
        $returns = CustomerReturn::query()->with(['product', 'user'])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->latest('returned_at')->limit(4)->get()
            ->map(fn (CustomerReturn $return) => [
                'color' => 'orange', 'title' => 'Return recorded',
                'detail' => ($return->product?->name ?? 'Product').' · '.number_format($return->quantity).' unit(s)',
                'occurred_at' => $return->returned_at,
                'url' => $userId ? route('staff.returns') : route('admin.returns'),
            ]);
        $damages = DamagedGood::query()->with(['product', 'user'])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->latest('reported_at')->limit(4)->get()
            ->map(fn (DamagedGood $damage) => [
                'color' => 'red', 'title' => 'Damage reported',
                'detail' => ($damage->product?->name ?? 'Product').' · '.number_format($damage->quantity).' unit(s)',
                'occurred_at' => $damage->reported_at,
                'url' => $userId ? route('staff.returns') : route('admin.returns'),
            ]);
        $holds = HeldOrder::query()->with('staff')
            ->when($userId, fn ($query) => $query->where('staff_id', $userId))
            ->latest('held_at')->limit(4)->get()
            ->map(fn (HeldOrder $hold) => [
                'color' => $hold->status === 'held' ? 'orange' : 'purple',
                'title' => $hold->status === 'held' ? 'Order placed on hold' : 'Held order resolved',
                'detail' => 'Hold #'.str_pad((string) $hold->held_order_id, 6, '0', STR_PAD_LEFT).($userId ? '' : ' · '.($hold->staff?->name ?? 'Staff')),
                'occurred_at' => $hold->held_at,
                'url' => $userId ? route('staff.pos') : route('admin.analytics'),
            ]);
        $stockAlerts = Product::query()
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->latest('updated_at')->limit(4)->get()
            ->map(fn (Product $product) => [
                'color' => 'red', 'title' => $product->current_stock === 0 ? 'Out of stock' : 'Low-stock alert',
                'detail' => $product->name.' · '.number_format($product->current_stock).' unit(s) left · reorder at '.number_format($product->reorder_level),
                'occurred_at' => $product->updated_at,
                'url' => $userId ? route('staff.products') : route('admin.low-stocks'),
            ]);

        return Collection::make()->concat($sales)->concat($movements)->concat($returns)->concat($damages)->concat($holds)->concat($stockAlerts)
            ->sortByDesc('occurred_at')->take(6)->values()->map(function ($activity) {
                $activity['time'] = $activity['occurred_at']?->diffForHumans() ?? 'Recently';

                return $activity;
            })->all();
    }

    private function comparison(float $current, float $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? 'First recorded sales this month' : 'No sales recorded this month';
        }

        $change = (($current - $previous) / $previous) * 100;

        return ($change >= 0 ? '+' : '').number_format($change, 1).'% vs last month';
    }
}

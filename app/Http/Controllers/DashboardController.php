<?php

namespace App\Http\Controllers;

use App\Models\Hat;
use App\Models\Order;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /**
     * Render the merchant dashboard: recent orders, hat admin grid, and
     * top-level stat cards.
     */
    public function index(): View
    {
        $orders = Order::with('hat')->latest()->take(20)->get();
        $hats = Hat::latest()->get();

        $revenueByCurrency = Order::query()
            ->selectRaw('currency, SUM(total_price) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($total) => (float) $total)
            ->all();

        $stats = [
            'total_orders' => Order::count(),
            'revenue_by_currency' => $revenueByCurrency,
            'hats_count' => Hat::count(),
            'orders_today' => Order::whereDate('created_at', today())->count(),
        ];

        return view('dashboard', [
            'orders' => $orders,
            'hats' => $hats,
            'stats' => $stats,
        ]);
    }
}

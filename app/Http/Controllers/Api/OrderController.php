<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    /**
     * List orders with their linked hat, latest first, 20 per page.
     */
    public function index(): JsonResponse
    {
        return response()->json(Order::with('hat')->latest()->paginate(20));
    }

    /**
     * Show a single order with its linked hat.
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load('hat'));
    }

    /**
     * AI-powered merchant summary of order trends, cached for 10 minutes.
     *
     * Falls back to a deterministic summary built from the same stats when
     * Claude is unavailable (see ClaudeService::orderInsights).
     */
    public function insights(ClaudeService $claude): JsonResponse
    {
        $payload = Cache::remember('order-insights', 600, function () use ($claude) {
            $stats = $this->computeStats();
            $insights = $claude->orderInsights($stats);

            return [
                'summary' => $insights['summary'],
                'ai' => $insights['ai'],
                'stats' => $stats,
            ];
        });

        return response()->json($payload);
    }

    /**
     * Compute the raw order statistics used to power the insights panel.
     *
     * @return array<string, mixed>
     */
    protected function computeStats(): array
    {
        $totalOrders = Order::count();

        $currencyRow = Order::query()
            ->select('currency')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy('currency')
            ->orderByDesc('aggregate_count')
            ->first();

        $currency = $currencyRow->currency ?? 'USD';

        $topHats = Order::query()
            ->selectRaw('hat_id, COUNT(*) as orders_count')
            ->whereNotNull('hat_id')
            ->groupBy('hat_id')
            ->orderByDesc('orders_count')
            ->take(3)
            ->with('hat:id,name')
            ->get()
            ->map(fn (Order $row) => [
                'name' => $row->hat?->name,
                'orders_count' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        return [
            'total_orders' => $totalOrders,
            'total_revenue' => (float) Order::sum('total_price'),
            'currency' => $currency,
            'orders_last_7_days' => Order::where('created_at', '>=', now()->subDays(7))->count(),
            'top_hats' => $topHats,
            'unmatched_orders' => Order::whereNull('hat_id')->count(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class StockAlertController extends Controller
{
    #[OA\Get(
        path: '/api/stock/alerts',
        tags: ['Stock'],
        summary: 'Consultar alertas de stock baixo e de caducidade',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'threshold', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0, default: 5)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Alertas de stock baixo e produtos caducados ou a caducar nos próximos 30 dias',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'threshold', type: 'integer', example: 5),
                        new OA\Property(property: 'count', type: 'integer', example: 1),
                        new OA\Property(
                            property: 'alerts',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'product_name', type: 'string', example: 'Leite'),
                                    new OA\Property(property: 'quantity', type: 'integer', example: 2),
                                    new OA\Property(property: 'alert', type: 'string', example: 'low_stock'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'expiration_alert_days', type: 'integer', example: 30),
                        new OA\Property(property: 'expiration_alert_count', type: 'integer', example: 1),
                        new OA\Property(
                            property: 'expiration_alerts',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'product_name', type: 'string', example: 'Leite'),
                                    new OA\Property(property: 'category', type: 'string', nullable: true, example: 'Laticínios'),
                                    new OA\Property(property: 'quantity', type: 'integer', example: 2),
                                    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date', nullable: true, example: '2026-01-15'),
                                    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', example: '2026-12-01'),
                                    new OA\Property(property: 'days_until_expiration', type: 'integer', example: 7),
                                    new OA\Property(property: 'alert', type: 'string', enum: ['expired', 'expiring_soon'], example: 'expiring_soon'),
                                ]
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'threshold' => ['sometimes', 'integer', 'min:0'],
        ]);
        $threshold = (int) ($validated['threshold'] ?? 5);

        $stocks = Stock::query()
            ->with(['product.category'])
            ->where('quantity', '<=', $threshold)
            ->whereHas('product')
            ->orderBy('quantity')
            ->get()
            ->map(fn (Stock $stock): array => [
                'product_id' => $stock->product_id,
                'product_name' => $stock->product->name,
                'category' => $stock->product->category?->name,
                'quantity' => $stock->quantity,
                'threshold' => $threshold,
                'alert' => $stock->quantity === 0 ? 'out_of_stock' : 'low_stock',
            ]);

        $today = today();
        $expirationAlertDays = 30;
        $expirationAlerts = Product::query()
            ->with(['category', 'stock'])
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<=', $today->copy()->addDays($expirationAlertDays))
            ->orderBy('expiration_date')
            ->get()
            ->map(function (Product $product) use ($today): array {
                $expirationDate = Carbon::parse($product->expiration_date);
                $daysUntilExpiration = $today->diffInDays($expirationDate, false);

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'category' => $product->category?->name,
                    'quantity' => $product->stock->quantity ?? 0,
                    'manufacturing_date' => $product->manufacturing_date === null
                        ? null
                        : Carbon::parse($product->manufacturing_date)->toDateString(),
                    'expiration_date' => $expirationDate->toDateString(),
                    'days_until_expiration' => $daysUntilExpiration,
                    'alert' => $daysUntilExpiration < 0 ? 'expired' : 'expiring_soon',
                ];
            });

        return response()->json([
            'threshold' => $threshold,
            'count' => $stocks->count(),
            'alerts' => $stocks,
            'expiration_alert_days' => $expirationAlertDays,
            'expiration_alert_count' => $expirationAlerts->count(),
            'expiration_alerts' => $expirationAlerts,
        ]);
    }
}

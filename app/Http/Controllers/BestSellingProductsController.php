<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class BestSellingProductsController extends Controller
{
    #[OA\Get(
        path: '/api/reports/best-selling-products',
        tags: ['Reports'],
        summary: 'Listar os produtos mais vendidos (administradores e gerentes)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Produtos ordenados pela quantidade vendida')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->where('sales.payment_status', 'paid')
            ->whereNotNull('sales.paid_at')
            ->select([
                'products.id as product_id',
                'products.name as product_name',
            ])
            ->selectRaw('SUM(sale_items.quantity) as quantity_sold')
            ->selectRaw('SUM(sale_items.subtotal) as revenue');

        if (isset($validated['from'])) {
            $query->whereDate('sales.paid_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('sales.paid_at', '<=', $validated['to']);
        }

        $query->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity_sold')
            ->orderByDesc('revenue')
            ->orderBy('products.id');

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }
}

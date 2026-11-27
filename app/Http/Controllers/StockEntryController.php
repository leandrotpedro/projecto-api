<?php

namespace App\Http\Controllers;

use App\Models\StockEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class StockEntryController extends Controller
{
    #[OA\Get(
        path: '/api/stock/entries',
        tags: ['Stock'],
        summary: 'Consultar histórico de entradas de stock recebidas',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'supplier_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Entradas de stock com data, produtos, quantidades e fornecedor')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockEntry::query()->with(['purchase', 'product', 'supplier', 'recorder']);
        foreach (['product_id', 'supplier_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (isset($validated['from'])) {
            $query->whereDate('occurred_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('occurred_at', '<=', $validated['to']);
        }

        return response()->json([
            'entries' => $query->latest('occurred_at')->paginate($validated['per_page'] ?? 20),
        ]);
    }
}

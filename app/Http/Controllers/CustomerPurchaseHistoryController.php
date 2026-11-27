<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CustomerPurchaseHistoryController extends Controller
{
    #[OA\Get(
        path: '/api/me/purchases',
        tags: ['Sales'],
        summary: 'Consultar histórico de compras do cliente autenticado',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Histórico de compras paginado')]
    )]
    public function mine(Request $request): JsonResponse
    {
        return $this->history($request, $request->user()->id);
    }

    #[OA\Get(
        path: '/api/users/{user}/purchases',
        tags: ['Users'],
        summary: 'Consultar histórico de compras de um cliente (administradores e gerentes)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Histórico de compras paginado'),
            new OA\Response(response: 404, description: 'Cliente não encontrado'),
        ]
    )]
    public function forCustomer(Request $request, int $user): JsonResponse
    {
        abort_unless($request->user()->canViewAllSales(), 403);
        User::query()->findOrFail($user);

        return $this->history($request, $user);
    }

    private function history(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'status' => ['sometimes', 'string', 'in:completed,cancelled'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Sale::query()
            ->where('user_id', $userId)
            ->with(['items.product', 'user', 'client.user', 'creator', 'seller'])
            ->latest();

        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }
}

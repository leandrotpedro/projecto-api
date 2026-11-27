<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use OpenApi\Attributes as OA;

class SaleController extends Controller
{
    #[OA\Get(
        path: '/api/sales',
        tags: ['Sales'],
        summary: 'Listar vendas próprias ou todas para administradores',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['completed', 'cancelled'])),
            new OA\Parameter(name: 'payment_method', in: 'query', schema: new OA\Schema(type: 'string', enum: ['dinheiro', 'cartao', 'transferencia'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista paginada de vendas')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'status' => ['sometimes', 'string', 'in:completed,cancelled'],
            'payment_method' => ['sometimes', 'string', 'in:dinheiro,cartao,transferencia'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Sale::with(['user', 'client.user', 'creator', 'seller', 'items.product']);

        if (! $request->user()->canViewAllSales()) {
            $query->where(function (Builder $sales) use ($request): void {
                $sales->where('user_id', $request->user()->id);
                if ($request->user()->canManageSales()) {
                    $sales->orWhere('created_by', $request->user()->id);
                }
            });
        }

        $this->applySaleFilters($query, $validated);

        return response()->json($query->latest()->paginate($validated['per_page'] ?? 20));
    }

    #[OA\Get(
        path: '/api/sales/{id}',
        tags: ['Sales'],
        summary: 'Consultar uma venda própria ou venda (administradores)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Venda encontrada')]
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->visibleSale($request, $id)->load(['user', 'client.user', 'creator', 'seller', 'items.product']));
    }

    #[OA\Get(
        path: '/api/sales/{id}/invoice',
        tags: ['Sales'],
        summary: 'Gerar factura HTML imprimível',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Factura HTML com valores em AOA')]
    )]
    public function invoice(Request $request, int $id): View
    {
        $sale = $this->visibleSale($request, $id)->load(['user', 'client.user', 'creator', 'seller', 'items.product.category']);

        return view('invoices.sale', compact('sale'));
    }

    #[OA\Post(
        path: '/api/sales',
        tags: ['Sales'],
        summary: 'Registar uma venda',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['payment_method', 'items'],
                properties: [
                    new OA\Property(
                        property: 'client_id',
                        type: 'integer',
                        nullable: true,
                        description: 'ID do cliente registado. Opcional para venda a consumidor final.',
                        example: 3
                    ),
                    new OA\Property(property: 'payment_method', type: 'string', enum: ['dinheiro', 'cartao', 'transferencia']),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        minItems: 1,
                        description: 'Um ou mais produtos. O preço é obtido do catálogo; não envie price ou unit_price.',
                        example: [
                            ['product_id' => 1, 'quantity' => 2],
                            ['product_id' => 2, 'quantity' => 1],
                        ],
                        items: new OA\Items(
                            type: 'object',
                            required: ['product_id', 'quantity'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'integer'),
                                new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Venda registada; inclui cliente, vendedor, produtos e total em AOA',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Venda registada com sucesso.'),
                        new OA\Property(
                            property: 'sale',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 16),
                                new OA\Property(property: 'user_id', type: 'integer', nullable: true, example: 3),
                                new OA\Property(property: 'client_id', type: 'integer', nullable: true, example: 3),
                                new OA\Property(property: 'customer_name', type: 'string', example: 'Ana Silva'),
                                new OA\Property(property: 'seller_name', type: 'string', nullable: true, example: 'João Manuel'),
                                new OA\Property(property: 'total', type: 'number', example: 43000),
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'product_id', type: 'integer', example: 1),
                                            new OA\Property(property: 'quantity', type: 'integer', example: 2),
                                            new OA\Property(property: 'unit_price', type: 'number', example: 20000),
                                            new OA\Property(property: 'subtotal', type: 'number', example: 40000),
                                            new OA\Property(
                                                property: 'product',
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'name', type: 'string', example: 'Teclado mecânico'),
                                                    new OA\Property(
                                                        property: 'category',
                                                        type: 'object',
                                                        nullable: true,
                                                        properties: [
                                                            new OA\Property(property: 'name', type: 'string', example: 'Acessórios'),
                                                        ]
                                                    ),
                                                ]
                                            ),
                                        ]
                                    )
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'O perfil caixa não pode registar vendas; inicie sessão com a conta individual do vendedor'),
            new OA\Response(response: 422, description: 'Dados inválidos ou stock insuficiente'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            ! $actor->isCashier(),
            403,
            'Registe a venda com a sessão da conta individual do vendedor.',
        );

        $validated = $request->validate([
            'user_id' => $actor->canManageSales()
                ? ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            'client_id' => $actor->canManageSales()
                ? ['nullable', 'integer', 'exists:clients,id']
                : ['prohibited'],
            'customer_name' => [
                Rule::requiredIf(
                    $request->input('customer_type', 'individual') === 'company'
                    && ! $request->filled('client_id')
                    && ! $request->filled('user_id')
                ),
                'nullable',
                'string',
                'max:255',
                'required_with:customer_phone',
            ],
            'customer_type' => ['sometimes', 'required', 'string', 'in:individual,company'],
            'customer_nif' => [
                'sometimes',
                'nullable',
                ...(
                    $request->input('customer_type', 'individual') === 'company'
                        ? [
                            'string',
                            'max:30',
                            'regex:/^[0-9]+$/',
                            ...(
                                $request->filled('customer_nif')
                                && ! $request->filled('client_id')
                                && ! $request->filled('user_id')
                                    ? [Rule::unique('clients', 'customer_nif')->where('customer_type', 'company')]
                                    : []
                            ),
                        ]
                        : []
                ),
            ],
            'customer_phone' => [
                'nullable',
                'string',
                'max:30',
                'required_with:customer_name',
            ],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'seller_name' => ['prohibited'],
            'payment_method' => ['required', 'string', 'in:dinheiro,cartao,transferencia'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['prohibited'],
            'items.*.unit_price' => ['prohibited'],
        ]);

        $customer = null;
        $client = null;
        if ($actor->canManageSales()) {
            if (isset($validated['user_id'])) {
                $customer = User::query()->whereKey($validated['user_id'])->firstOrFail();
                if (! $customer->isClient()) {
                    throw ValidationException::withMessages([
                        'user_id' => ['A conta indicada não tem perfil de cliente.'],
                    ]);
                }
                $client = $customer->client()->first();
            } elseif (isset($validated['client_id'])) {
                $client = Client::query()->whereKey($validated['client_id'])->firstOrFail();
                $customer = $client->user()->first();
            }
        } else {
            $customer = $actor;
            $client = $actor->client()->first();
        }

        if (isset($validated['user_id'], $validated['client_id']) && $client?->id !== (int) $validated['client_id']) {
            throw ValidationException::withMessages([
                'client_id' => ['O client_id não corresponde ao user_id indicado.'],
            ]);
        }

        return DB::transaction(function () use ($validated, $actor, $customer, $client, $request) {
            $seller = null;
            $cashSession = null;
            $customerType = $client === null
                ? ($validated['customer_type'] ?? 'individual')
                : $client->customer_type;

            if ($client !== null && isset($validated['customer_type']) && $validated['customer_type'] !== $client->customer_type) {
                throw ValidationException::withMessages([
                    'customer_type' => ['O tipo de cliente não corresponde ao cadastro selecionado.'],
                ]);
            }

            if ($actor->isSeller()) {
                $seller = $actor;
            }

            if ($actor->isSeller() || $actor->isManager() || $actor->isAdmin()) {
                $cashSession = CashSession::query()
                    ->where('opened_by', $actor->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($actor->isSeller() && ! $cashSession) {
                    throw ValidationException::withMessages([
                        'cash_session' => ['Abra uma sessão de caixa antes de registar vendas.'],
                    ]);
                }
            }

            $resolvedClient = $client;
            if ($actor->canManageSales() && $customerType === 'individual' && ! $resolvedClient && ! empty($validated['customer_email'])) {
                $customer = User::query()
                    ->where('role', 'cliente')
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['customer_email']))])
                    ->first();

                if ($customer !== null) {
                    $resolvedClient = $customer->client()->first();
                }
            }

            if ($actor->canManageSales() && $customerType === 'individual' && ! $resolvedClient && ! empty($validated['customer_phone'])) {
                $resolvedClient = Client::query()
                    ->where('phone', trim($validated['customer_phone']))
                    ->where('customer_type', 'individual')
                    ->lockForUpdate()
                    ->first();
            }

            if ($customer === null && $resolvedClient !== null) {
                $customer = $resolvedClient->user()->first();
            }

            if ($actor->canManageSales() && ! $resolvedClient && ! empty($validated['customer_name'])) {
                $resolvedClient = Client::create([
                    'name' => trim($validated['customer_name']),
                    'phone' => isset($validated['customer_phone']) ? trim($validated['customer_phone']) : null,
                    'customer_type' => $customerType,
                    'customer_nif' => $customerType === 'company'
                        ? ($validated['customer_nif'] ?? null)
                        : null,
                ]);
            }

            if ($resolvedClient && $actor->canManageSales() && isset($validated['customer_name'])) {
                $resolvedClient->update([
                    'name' => trim($validated['customer_name']),
                    'phone' => isset($validated['customer_phone'])
                        ? trim($validated['customer_phone'])
                        : $resolvedClient->phone,
                ]);
            }

            if ($resolvedClient !== null && isset($validated['customer_type']) && $validated['customer_type'] !== $resolvedClient->customer_type) {
                throw ValidationException::withMessages([
                    'customer_type' => ['O tipo de cliente não corresponde ao cadastro encontrado.'],
                ]);
            }

            $clientName = $resolvedClient?->name;
            $clientPhone = $resolvedClient?->phone;
            $accountName = $customer === null ? null : $customer->name;
            $accountPhone = $customer === null ? null : $customer->phone;
            $customerName = isset($validated['customer_name'])
                ? trim($validated['customer_name'])
                : ($clientName ?? $accountName ?? 'Consumidor final');
            $customerPhone = isset($validated['customer_phone'])
                ? trim($validated['customer_phone'])
                : ($clientPhone ?? $accountPhone);
            $customerData = [
                'user_id' => $customer?->id,
                'client_id' => $resolvedClient?->id,
                'customer_name' => $customerName,
                'customer_type' => $resolvedClient === null ? $customerType : $resolvedClient->customer_type,
                'customer_nif' => $customerType !== 'company'
                    ? null
                    : ($resolvedClient !== null
                        ? $resolvedClient->customer_nif
                        : ($validated['customer_nif'] ?? null)),
                'customer_email' => $customer->email ?? ($validated['customer_email'] ?? null),
                'customer_phone' => $customerPhone,
                'customer_address' => $customer?->address,
            ];

            $groupedItems = [];

            foreach ($validated['items'] as $item) {
                $productId = $item['product_id'];
                $groupedItems[$productId] = ($groupedItems[$productId] ?? 0) + $item['quantity'];
            }

            ksort($groupedItems);
            $sale = Sale::create([
                ...$customerData,
                'created_by' => $actor->id,
                'seller_id' => $seller?->id,
                'seller_name' => $seller?->name,
                'cash_session_id' => $cashSession?->id,
                'total' => 0,
                'status' => 'completed',
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'paid',
                'currency' => 'AOA',
                'paid_at' => now(),
            ]);

            $total = 0;
            foreach ($groupedItems as $productId => $quantity) {
                $product = Product::query()->lockForUpdate()->findOrFail($productId);
                $stock = Stock::query()
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock || $stock->quantity < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ['Stock insuficiente para um ou mais produtos.'],
                    ]);
                }

                $unitPrice = (float) $product->price;
                $subtotal = round($quantity * $unitPrice, 2);

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);

                $stockBefore = $stock->quantity;
                $stock->decrement('quantity', $quantity);
                StockLedger::record(
                    $product->id,
                    $actor->id,
                    'sale',
                    'out',
                    $quantity,
                    $stockBefore,
                    $stock->quantity,
                    'Venda #'.$sale->id,
                    $sale,
                );
                $total += $subtotal;
            }

            $sale->update(['total' => round($total, 2)]);
            $this->logActivity($request, 'sale.created', $sale, [
                'total' => $sale->total,
                'currency' => $sale->currency,
                'payment_method' => $sale->payment_method,
                'customer_id' => $sale->user_id,
                'client_id' => $sale->client_id,
            ]);

            return response()->json([
                'message' => 'Venda registada com sucesso.',
                'sale' => $sale->load(['client', 'items.product.category'])
                    ->makeHidden(['created_by', 'seller_id']),
            ], 201);
        });
    }

    #[OA\Post(
        path: '/api/sales/{id}/cancel',
        tags: ['Sales'],
        summary: 'Cancelar uma venda (administradores e gerentes)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Venda cancelada e stock reposto; reembolso pendente'),
            new OA\Response(response: 409, description: 'Venda já cancelada ou stock em falta'),
        ]
    )]
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $validated, $id) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($id);
            if ($sale->status === 'cancelled') {
                return response()->json(['message' => 'A venda já foi cancelada.'], 409);
            }

            foreach ($sale->items()->orderBy('product_id')->get() as $item) {
                $stock = Stock::query()
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    abort(409, 'Não foi possível repor o stock: registo de stock em falta.');
                }

                $stockBefore = $stock->quantity;
                $stock->increment('quantity', $item->quantity);
                StockLedger::record(
                    $item->product_id,
                    $request->user()->id,
                    'cancellation',
                    'in',
                    $item->quantity,
                    $stockBefore,
                    $stock->quantity,
                    'Cancelamento da venda #'.$sale->id,
                    $sale,
                );
            }

            $sale->update([
                'status' => 'cancelled',
                'payment_status' => $sale->payment_status === 'paid' ? 'refund_pending' : $sale->payment_status,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $validated['reason'],
            ]);

            $this->logActivity($request, 'sale.cancelled', $sale, [
                'reason' => $validated['reason'],
                'payment_status' => $sale->payment_status,
                'stock_restored' => true,
            ]);

            return response()->json([
                'message' => 'Venda cancelada e stock reposto. O reembolso ficou pendente de confirmação.',
                'sale' => $sale->load(['user', 'client.user', 'creator', 'seller', 'items.product.category']),
            ]);
        });
    }

    #[OA\Patch(
        path: '/api/sales/{id}/refund',
        tags: ['Sales'],
        summary: 'Confirmar o reembolso de uma venda (administradores e gerentes)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Reembolso registado'),
            new OA\Response(response: 409, description: 'Não existe reembolso pendente'),
        ]
    )]
    public function refund(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($id);
            if ($sale->status !== 'cancelled' || $sale->payment_status !== 'refund_pending') {
                return response()->json(['message' => 'A venda não tem um reembolso pendente.'], 409);
            }

            $sale->update(['payment_status' => 'refunded']);
            $this->logActivity($request, 'sale.refund_confirmed', $sale, [
                'payment_status' => 'refunded',
            ]);

            return response()->json([
                'message' => 'Reembolso registado.',
                'sale' => $sale,
            ]);
        });
    }

    #[OA\Get(
        path: '/api/reports/sales',
        tags: ['Reports'],
        summary: 'Relatório de vendas por período (administradores e gerentes)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['completed', 'cancelled'])),
            new OA\Parameter(name: 'payment_method', in: 'query', schema: new OA\Schema(type: 'string', enum: ['dinheiro', 'cartao', 'transferencia'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Agregados e vendas paginadas em AOA')]
    )]
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'status' => ['sometimes', 'string', 'in:completed,cancelled'],
            'payment_method' => ['sometimes', 'string', 'in:dinheiro,cartao,transferencia'],
        ]);

        $query = Sale::query();
        $this->applySaleFilters($query, $validated);
        if (! isset($validated['status'])) {
            $query->where('status', 'completed');
        }

        return response()->json([
            'currency' => 'AOA',
            'count' => (clone $query)->count(),
            'total' => (float) (clone $query)->sum('total'),
            'sales' => $query->with(['user', 'client.user', 'creator', 'seller', 'items.product'])->latest()->paginate(50),
        ]);
    }

    private function visibleSale(Request $request, int $id): Sale
    {
        $query = Sale::query();
        if (! $request->user()->canViewAllSales()) {
            $query->where(function (Builder $sales) use ($request): void {
                $sales->where('user_id', $request->user()->id);
                if ($request->user()->canManageSales()) {
                    $sales->orWhere('created_by', $request->user()->id);
                }
            });
        }

        return $query->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    /**
     * @param  Builder<Sale>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySaleFilters(Builder $query, array $filters): void
    {
        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function logActivity(Request $request, string $action, Sale $sale, array $changes): void
    {
        ActivityLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Sale::class,
            'subject_id' => $sale->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}

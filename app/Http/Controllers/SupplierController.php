<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class SupplierController extends Controller
{
    #[OA\Get(
        path: '/api/suppliers',
        tags: ['Suppliers'],
        summary: 'Listar fornecedores',
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista paginada de fornecedores')]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            Supplier::query()
                ->orderBy('name')
                ->paginate($validated['per_page'] ?? 20)
        );
    }

    #[OA\Post(
        path: '/api/suppliers',
        tags: ['Suppliers'],
        summary: 'Registar fornecedor',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Fornecedor Central'),
                    new OA\Property(property: 'contact_name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Fornecedor registado'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $supplier = DB::transaction(function () use ($validated, $request): Supplier {
            $supplier = Supplier::create($validated);

            ActivityLog::create([
                'actor_id' => $request->user()->id,
                'action' => 'supplier.created',
                'subject_type' => Supplier::class,
                'subject_id' => $supplier->id,
                'changes' => ['name' => $supplier->name],
                'ip_address' => $request->ip(),
            ]);

            return $supplier;
        });

        return response()->json([
            'message' => 'Fornecedor registado com sucesso.',
            'supplier' => $supplier,
        ], 201);
    }

    #[OA\Patch(
        path: '/api/suppliers/{supplier}',
        tags: ['Suppliers'],
        summary: 'Actualizar dados ou estado de um fornecedor',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'supplier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'contact_name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fornecedor actualizado'),
            new OA\Response(response: 404, description: 'Fornecedor não encontrado'),
            new OA\Response(response: 422, description: 'Dados inválidos'),
        ]
    )]
    public function update(Request $request, int $supplier): JsonResponse
    {
        $record = Supplier::query()->findOrFail($supplier);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($record, $validated, $request): Supplier {
            $changes = [];
            foreach ($validated as $key => $value) {
                if ($record->getAttribute($key) !== $value) {
                    $changes[$key] = [
                        'from' => $record->getAttribute($key),
                        'to' => $value,
                    ];
                }
            }

            $record->update($validated);
            if ($changes !== []) {
                ActivityLog::create([
                    'actor_id' => $request->user()->id,
                    'action' => 'supplier.updated',
                    'subject_type' => Supplier::class,
                    'subject_id' => $record->id,
                    'changes' => $changes,
                    'ip_address' => $request->ip(),
                ]);
            }

            return $record->refresh();
        });

        return response()->json([
            'message' => 'Fornecedor actualizado com sucesso.',
            'supplier' => $updated,
        ]);
    }
}

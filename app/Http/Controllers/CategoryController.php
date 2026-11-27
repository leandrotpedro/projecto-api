<?php
// filepath: c:\Users\Lenovo\projeto-api\app\Http\Controllers\CategoryController.php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    #[OA\Get(
        path: '/api/categories',
        operationId: 'listCategories',
        tags: ['Categories'],
        summary: 'Listar categorias',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de categorias',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer', example: 1),
                            new OA\Property(property: 'name', type: 'string', example: 'Bebidas'),
                            new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Bebidas diversas'),
                            new OA\Property(property: 'status', type: 'string', enum: ['activo', 'inactivo'], example: 'activo'),
                            new OA\Property(property: 'products_count', type: 'integer', example: 5),
                        ]
                    )
                )
            )
        ]
    )]
    public function index(): JsonResponse
    {
        return response()->json(Category::withCount('products')->get());
    }

    #[OA\Post(
        path: '/api/categories',
        operationId: 'createCategory',
        tags: ['Categories'],
        summary: 'Criar categoria',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'status'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Bebidas'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Bebidas diversas'),
                    new OA\Property(property: 'status', type: 'string', enum: ['activo', 'inactivo'], example: 'activo'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Categoria criada com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'status' => ['required', Rule::in(['activo', 'inactivo'])],
        ]);

        $category = Category::create($validated);
        $this->logActivity($request, 'category.created', $category, $validated);

        return response()->json([
            'message' => 'Categoria criada com sucesso.',
            'category' => $category,
        ], 201);
    }

    #[OA\Get(
        path: '/api/categories/{id}',
        operationId: 'showCategory',
        tags: ['Categories'],
        summary: 'Consultar categoria',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Categoria encontrada'),
            new OA\Response(response: 404, description: 'Categoria não encontrada')
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $category = Category::with('products')->find($id);

        if (!$category) {
            return response()->json(['message' => 'Categoria não encontrada.'], 404);
        }

        return response()->json($category);
    }

    #[OA\Put(
        path: '/api/categories/{id}',
        operationId: 'updateCategory',
        tags: ['Categories'],
        summary: 'Actualizar categoria',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Bebidas'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Bebidas diversas'),
                    new OA\Property(property: 'status', type: 'string', enum: ['activo', 'inactivo'], example: 'inactivo'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Categoria actualizada com sucesso'),
            new OA\Response(response: 404, description: 'Categoria não encontrada'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['message' => 'Categoria não encontrada.'], 404);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($category->id),
            ],
            'description' => 'sometimes|nullable|string',
            'status' => ['sometimes', 'required', Rule::in(['activo', 'inactivo'])],
        ]);

        $oldAttributes = array_intersect_key($category->getAttributes(), $validated);
        $category->update($validated);
        $changes = [];
        foreach ($validated as $key => $value) {
            $changes[$key] = ['from' => $oldAttributes[$key] ?? null, 'to' => $value];
        }
        if ($changes !== []) {
            $this->logActivity($request, 'category.updated', $category, $changes);
        }

        return response()->json([
            'message' => 'Categoria actualizada com sucesso.',
            'category' => $category,
        ]);
    }

    #[OA\Delete(
        path: '/api/categories/{id}',
        operationId: 'deleteCategory',
        tags: ['Categories'],
        summary: 'Eliminar categoria',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Categoria eliminada com sucesso'),
            new OA\Response(response: 404, description: 'Categoria não encontrada'),
            new OA\Response(response: 422, description: 'Categoria possui produtos associados')
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['message' => 'Categoria não encontrada.'], 404);
        }

        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Não é possível eliminar uma categoria com produtos associados.',
            ], 422);
        }

        $this->logActivity(request(), 'category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return response()->json(['message' => 'Categoria eliminada com sucesso.']);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function logActivity(Request $request, string $action, Category $category, array $changes): void
    {
        ActivityLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Category::class,
            'subject_id' => $category->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
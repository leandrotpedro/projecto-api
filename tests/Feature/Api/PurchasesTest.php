<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_routes_require_an_authenticated_administrator(): void
    {
        $this->getJson('/api/suppliers')->assertUnauthorized();
        $this->getJson('/api/purchases')->assertUnauthorized();

        $client = User::factory()->create();
        $this->actingAs($client, 'sanctum')
            ->postJson('/api/suppliers', ['name' => 'Fornecedor'])
            ->assertForbidden();
    }

    public function test_admin_can_register_supplier_and_received_purchase_updates_stock(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $category = Category::create([
            'name' => 'Acessórios',
            'description' => null,
            'status' => 'activo',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Teclado',
            'description' => null,
            'price' => 25000,
        ]);
        $stock = Stock::create([
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $supplier = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/suppliers', [
                'name' => 'Fornecedor Central',
                'email' => 'fornecedor@example.com',
                'phone' => '923000000',
            ])
            ->assertCreated()
            ->assertJsonPath('supplier.is_active', true)
            ->json('supplier');

        $this->postJson('/api/purchases', [
            'supplier_id' => $supplier['id'],
            'purchase_date' => '2026-11-10',
            'invoice_number' => 'FC-001',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 12000.50],
            ],
        ])->assertCreated()
            ->assertJsonPath('purchase.supplier_name', 'Fornecedor Central')
            ->assertJsonPath('purchase.total', '36001.50')
            ->assertJsonPath('purchase.payment_status', 'pending')
            ->assertJsonPath('purchase.items.0.product_name', 'Teclado')
            ->assertJsonPath('purchase.items.0.subtotal', '36001.50');

        $purchaseId = $this->getJson('/api/purchases')->json('data.0.id');
        $this->getJson('/api/cash-movements?type=supplier_purchase')
            ->assertOk()
            ->assertJsonCount(0, 'movements.data');
        $this->patchJson('/api/purchases/'.$purchaseId.'/payment', [
            'payment_method' => 'transferencia',
        ])->assertOk()
            ->assertJsonPath('purchase.payment_status', 'paid')
            ->assertJsonPath('purchase.payment_method', 'transferencia');
        $this->getJson('/api/cash-movements?type=supplier_purchase')
            ->assertOk()
            ->assertJsonPath('totals.out', 36001.5)
            ->assertJsonPath('movements.data.0.reference_id', $purchaseId)
            ->assertJsonPath('movements.data.0.payment_method', 'transferencia');
        $this->patchJson('/api/purchases/'.$purchaseId.'/payment', [
            'payment_method' => 'transferencia',
        ])->assertStatus(409);

        $this->assertSame(7, $stock->fresh()->quantity);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'purchase.received',
            'subject_type' => 'App\Models\Purchase',
        ]);
        $this->assertDatabaseHas('stock_entries', [
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'quantity' => 3,
            'stock_before' => 4,
            'stock_after' => 7,
            'supplier_name' => 'Fornecedor Central',
        ]);
        $this->getJson('/api/stock/entries')
            ->assertOk()
            ->assertJsonPath('entries.data.0.product_name', 'Teclado')
            ->assertJsonPath('entries.data.0.quantity', 3)
            ->assertJsonPath('entries.data.0.stock_before', 4)
            ->assertJsonPath('entries.data.0.stock_after', 7);
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.0.supplier_id', $supplier['id']);
    }

    public function test_invalid_purchase_does_not_change_stock_or_create_purchase(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $supplier = Supplier::create(['name' => 'Fornecedor']);
        $category = Category::create([
            'name' => 'Acessórios',
            'description' => null,
            'status' => 'activo',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Teclado',
            'description' => null,
            'price' => 25000,
        ]);
        $stock = Stock::create(['product_id' => $product->id, 'quantity' => 4]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 12000],
                    ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10000],
                ],
            ])
            ->assertUnprocessable();

        $this->assertSame(4, $stock->fresh()->quantity);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_inactive_supplier_cannot_be_used_for_a_purchase(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $supplier = Supplier::create(['name' => 'Fornecedor inactivo', 'is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/purchases', [
                'supplier_id' => $supplier->id,
                'items' => [
                    ['product_id' => 1, 'quantity' => 1, 'unit_cost' => 100],
                ],
            ])
            ->assertUnprocessable();
    }
}

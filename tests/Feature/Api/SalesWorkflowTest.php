<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_assign_admin_role(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Utilizador',
            'email' => 'user@example.com',
            'password' => 'password123',
            'role' => 'administrador',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'user@example.com']);

        $this->postJson('/api/users', [
            'name' => 'Vendedor não autorizado',
            'email' => 'seller@example.com',
            'password' => 'password123',
            'role' => 'vendedor',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'seller@example.com']);
    }

    public function test_api_account_deletion_is_logical_and_blocks_future_login(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('account-deletion')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/me')
            ->assertOk()
            ->assertJson(['message' => 'Conta eliminada.']);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.account_deleted',
            'subject_id' => $user->id,
        ]);
    }

    public function test_admin_role_can_only_be_granted_by_the_local_command(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Utilizador',
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'cliente');

        $this->assertSame(0, Artisan::call('users:make-admin', ['email' => 'user@example.com']));
        $this->assertDatabaseHas('users', ['email' => 'user@example.com', 'role' => 'administrador']);
    }

    public function test_client_profile_is_related_to_user_and_tracks_client_role(): void
    {
        $registration = $this->postJson('/api/users', [
            'name' => 'Cliente de teste',
            'email' => 'cliente@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $userId = $registration->json('user.id');
        $this->assertDatabaseHas('clients', ['user_id' => $userId]);

        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'gerente'])->save();

        $client = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.user_id', $userId)
            ->assertJsonPath('0.user.id', $userId)
            ->json('0');
        $this->assertDatabaseHas('clients', ['id' => $client['id'], 'user_id' => $userId]);

        $seller = User::factory()->create();
        $seller->forceFill(['role' => 'vendedor'])->save();

        $this->patchJson('/api/users/'.$seller->id.'/role', ['role' => 'cliente'])
            ->assertOk()
            ->assertJsonPath('user.role', 'cliente');
        $this->assertDatabaseHas('clients', ['user_id' => $seller->id]);

        $this->patchJson('/api/users/'.$userId.'/role', ['role' => 'vendedor'])
            ->assertOk()
            ->assertJsonPath('user.role', 'vendedor');
        $this->assertDatabaseMissing('clients', ['user_id' => $userId]);
    }

    public function test_new_account_reuses_an_existing_standalone_client_with_the_same_phone(): void
    {
        $client = Client::create([
            'name' => 'Cliente presencial',
            'phone' => '923456789',
        ]);

        $user = User::factory()->create([
            'name' => 'Cliente registado',
            'phone' => '923456789',
        ]);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'user_id' => $user->id,
            'name' => 'Cliente registado',
            'phone' => '923456789',
        ]);

        $unrelatedClient = Client::create([
            'name' => 'Outro cliente',
            'phone' => '999999999',
        ]);
        $user->update(['phone' => '999999999']);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'user_id' => $user->id,
            'phone' => '999999999',
        ]);
        $this->assertDatabaseHas('clients', [
            'id' => $unrelatedClient->id,
            'user_id' => null,
        ]);
    }

    public function test_only_administrators_can_create_and_remove_managers_and_sellers(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'gerente'])->save();

        $staffData = [
            'name' => 'João Manuel',
            'email' => 'joao@example.com',
            'password' => 'password123',
            'phone' => '912345678',
            'role' => 'vendedor',
        ];

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/staff', $staffData)
            ->assertForbidden();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/staff', $staffData)
            ->assertCreated()
            ->assertJsonPath('user.name', 'João Manuel')
            ->assertJsonPath('user.role', 'vendedor')
            ->json('user');

        $this->assertDatabaseHas('users', [
            'id' => $created['id'],
            'email' => 'joao@example.com',
            'role' => 'vendedor',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.staff_created',
            'subject_id' => $created['id'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/staff', [...$staffData, 'email' => 'manager@example.com', 'role' => 'gerente'])
            ->assertCreated()
            ->assertJsonPath('user.role', 'gerente');

        $staff = User::findOrFail($created['id']);
        $staff->createToken('staff-access');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/staff/'.$staff->id)
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $staff->id]);
        $this->assertDatabaseHas('activity_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.staff_deleted',
            'subject_id' => $staff->id,
        ]);
    }

    public function test_seller_opens_cash_using_the_authenticated_account_name(): void
    {
        $seller = User::factory()->create(['name' => 'João Manuel']);
        $this->actingAs($seller, 'sanctum');
        $seller->forceFill(['role' => 'vendedor'])->save();
        $product = $this->createProduct(3);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/cash-registers')
            ->assertOk()
            ->assertJsonCount(1);

        $this->postJson('/api/sales', [
            'payment_method' => 'dinheiro',
            'customer_name' => 'Cliente presencial',
            'customer_phone' => '923456789',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cash_session');

        $session = $this->postJson('/api/cash-sessions', [
            'cash_register_id' => 1,
            'opening_amount' => 1000,
        ])
            ->assertCreated()
            ->assertJsonPath('cash_session.opener.name', 'João Manuel')
            ->json('cash_session');

        $createdSale = $this->postJson('/api/sales', [
            'payment_method' => 'dinheiro',
            'customer_name' => 'Cliente presencial',
            'customer_phone' => '923456789',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('sale.cash_session_id', $session['id'])
            ->assertJsonMissingPath('sale.creator')
            ->assertJsonMissingPath('sale.seller')
            ->assertJsonPath('sale.seller_name', 'João Manuel')
            ->assertJsonPath('sale.customer_name', 'Cliente presencial')
            ->assertJsonPath('sale.customer_type', 'individual')
            ->assertJsonPath('sale.customer_nif', null)
            ->assertJsonPath('sale.customer_phone', '923456789')
            ->assertJsonPath('sale.client.name', 'Cliente presencial')
            ->assertJsonPath('sale.client.phone', '923456789')
            ->assertJsonPath('sale.client.id', fn ($id) => is_int($id) && $id > 0)
            ->json('sale');

        $this->assertDatabaseHas('clients', [
            'name' => 'Cliente presencial',
            'phone' => '923456789',
            'customer_type' => 'individual',
            'customer_nif' => null,
            'user_id' => null,
        ]);

        $clientId = $createdSale['client']['id'];
        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('0.id', $clientId)
            ->assertJsonPath('0.customer_type', 'individual')
            ->assertJsonPath('0.customer_nif', null)
            ->assertJsonMissingPath('0.user.email');
        $this->postJson('/api/sales', [
            'client_id' => $clientId,
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('sale.client_id', $clientId)
            ->assertJsonPath('sale.customer_name', 'Cliente presencial')
            ->assertJsonPath('sale.seller_name', 'João Manuel');

        $this->postJson('/api/sales', [
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('sale.client_id', null)
            ->assertJsonPath('sale.customer_name', 'Consumidor final')
            ->assertJsonPath('sale.seller_name', 'João Manuel');

        $this->getJson('/api/expenses')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/staff/'.$seller->id)
            ->assertStatus(409);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/cash-sessions/'.$session['id'].'/close', ['counted_amount' => 4750])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/staff/'.$seller->id)
            ->assertOk();
    }

    public function test_seller_sale_matches_registered_client_by_email_and_invoice_has_sale_details(): void
    {
        $seller = User::factory()->create(['name' => 'Vendedor de teste']);
        $seller->forceFill(['role' => 'vendedor'])->save();
        $product = $this->createProduct(2);
        $customer = $this->postJson('/api/users', [
            'name' => 'Cliente registado',
            'email' => 'cliente.registado@example.com',
            'phone' => '923456789',
            'password' => 'password123',
        ])->assertCreated()->json('user');

        $this->actingAs($seller, 'sanctum')->postJson('/api/cash-sessions', [
            'cash_register_id' => 1,
            'opening_amount' => 0,
        ])->assertCreated();

        $this->postJson('/api/sales', [
            'customer_name' => 'Cliente registado',
            'customer_phone' => '923456789',
            'customer_email' => 'cliente.registado@example.com',
            'seller_name' => 'Outro vendedor',
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('seller_name');

        $sale = $this->postJson('/api/sales', [
            'customer_name' => 'Cliente registado',
            'customer_phone' => '923456789',
            'customer_email' => 'CLIENTE.REGISTADO@example.com',
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('sale.user_id', $customer['id'])
            ->assertJsonPath('sale.client.user_id', $customer['id'])
            ->assertJsonPath('sale.customer_name', 'Cliente registado')
            ->assertJsonPath('sale.customer_nif', null)
            ->assertJsonPath('sale.customer_email', 'cliente.registado@example.com')
            ->assertJsonPath('sale.items.0.product.category.name', $product->category->name)
            ->json('sale');

        $this->assertSame($seller->id, Sale::findOrFail($sale['id'])->seller_id);
        $this->assertSame($seller->id, Sale::findOrFail($sale['id'])->created_by);
        $saleModel = Sale::findOrFail($sale['id']);
        $this->get('/api/sales/'.$sale['id'].'/invoice')
            ->assertOk()
            ->assertSee('Cliente registado')
            ->assertSee('cliente.registado@example.com')
            ->assertSee('923456789')
            ->assertSee($seller->name)
            ->assertSee($product->name)
            ->assertSee($saleModel->created_at->format('d/m/Y H:i'));
    }

    public function test_company_sale_can_omit_nif_or_reuse_saved_company_nif(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $product = $this->createProduct(4);
        $payload = [
            'customer_type' => 'company',
            'customer_name' => 'Empresa de teste',
            'customer_phone' => '923456789',
            'customer_nif' => '5000000000',
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $saleWithoutNif = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sales', array_diff_key($payload, ['customer_nif' => true]))
            ->assertCreated()
            ->assertJsonPath('sale.customer_type', 'company')
            ->assertJsonPath('sale.customer_nif', null)
            ->assertJsonPath('sale.client.customer_nif', null)
            ->json('sale');

        $this->assertDatabaseHas('clients', [
            'id' => $saleWithoutNif['client']['id'],
            'customer_type' => 'company',
            'customer_nif' => null,
        ]);
        $this->get('/sales/'.$saleWithoutNif['id'].'/invoice')
            ->assertOk()
            ->assertSee('NIF:');

        $sale = $this->postJson('/api/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('sale.customer_type', 'company')
            ->assertJsonPath('sale.customer_nif', '5000000000')
            ->assertJsonPath('sale.client.customer_type', 'company')
            ->assertJsonPath('sale.client.customer_nif', '5000000000')
            ->assertJsonPath('sale.items.0.product.category.name', $product->category->name)
            ->assertJsonMissingPath('sale.creator')
            ->assertJsonMissingPath('sale.seller')
            ->json('sale');

        $this->assertDatabaseHas('clients', [
            'id' => $sale['client']['id'],
            'name' => 'Empresa de teste',
            'customer_type' => 'company',
            'customer_nif' => '5000000000',
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $sale['id'],
            'customer_type' => 'company',
            'customer_nif' => '5000000000',
        ]);

        $this->postJson('/api/sales', [
            'client_id' => $sale['client']['id'],
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('sale.client_id', $sale['client']['id'])
            ->assertJsonPath('sale.customer_name', 'Empresa de teste')
            ->assertJsonPath('sale.customer_type', 'company')
            ->assertJsonPath('sale.customer_nif', '5000000000');

        $this->postJson('/api/sales', [...$payload, 'customer_nif' => '5000000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_nif');

        $this->get('/sales/'.$sale['id'].'/invoice')
            ->assertOk()
            ->assertSee('NIF: 5000000000')
            ->assertSee($product->category->name);
    }

    public function test_individual_sale_accepts_an_empty_optional_customer_nif(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $product = $this->createProduct(4);
        $payload = [
            'customer_type' => 'individual',
            'customer_nif' => '',
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('sale.customer_type', 'individual')
            ->assertJsonPath('sale.customer_nif', null);

        $this->postJson('/api/sales', [...$payload, 'customer_nif' => '123456789'])
            ->assertCreated()
            ->assertJsonPath('sale.customer_type', 'individual')
            ->assertJsonPath('sale.customer_nif', null);
    }

    public function test_audit_logs_require_an_authenticated_administrator(): void
    {
        $this->getJson('/api/audit-logs')->assertUnauthorized();

        $client = User::factory()->create();
        $clientToken = $client->createToken('client')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($clientToken)->getJson('/api/audit-logs')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        ActivityLog::create([
            'actor_id' => $admin->id,
            'action' => 'test.audit',
            'subject_type' => User::class,
            'subject_id' => $admin->id,
        ]);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        Auth::forgetGuards();
        $this->flushHeaders()
            ->withToken($adminToken)
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'test.audit');
    }

    public function test_client_can_sell_for_self_and_cannot_access_another_clients_sale(): void
    {
        $client = User::factory()->create();
        $otherClient = User::factory()->create();
        $product = $this->createProduct(6);

        $saleResponse = $this->actingAs($client, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $saleId = $saleResponse->json('sale.id');
        $saleResponse->assertJsonPath('sale.user_id', $client->id)
            ->assertJsonPath('sale.customer_name', $client->name)
            ->assertJsonPath('sale.currency', 'AOA');
        $this->assertSame(4, $product->stock()->firstOrFail()->quantity);

        $this->actingAs($otherClient, 'sanctum')
            ->getJson('/api/sales/'.$saleId)
            ->assertNotFound();
        $this->actingAs($otherClient, 'sanctum')
            ->get('/api/sales/'.$saleId.'/invoice')
            ->assertNotFound();
    }

    public function test_web_session_can_open_owned_invoice_in_browser(): void
    {
        $client = User::factory()->create();
        $product = $this->createProduct(2);
        $sale = $this->actingAs($client, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'dinheiro',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('sale');

        Auth::forgetGuards();
        $this->get('/sales/'.$sale['id'].'/invoice')
            ->assertRedirect('/login');

        $this->actingAs($client, 'web')
            ->get('/sales/'.$sale['id'].'/invoice')
            ->assertOk()
            ->assertSee('Factura')
            ->assertSee('1.250,00 AOA');

        $otherClient = User::factory()->create();
        $this->actingAs($otherClient, 'web')
            ->get('/sales/'.$sale['id'].'/invoice')
            ->assertNotFound();
    }

    public function test_login_token_can_be_used_to_create_a_sale(): void
    {
        $client = User::factory()->create([
            'email' => 'venda@example.com',
            'password' => 'password123',
        ]);
        $product = $this->createProduct(2);

        $login = $this->postJson('/api/login', [
            'email' => 'venda@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.user_id', $client->id);
    }

    public function test_client_cannot_choose_another_customer_for_a_sale(): void
    {
        $client = User::factory()->create();
        $anotherClient = User::factory()->create();
        $product = $this->createProduct(5);

        $this->actingAs($client, 'sanctum')->postJson('/api/sales', [
            'user_id' => $anotherClient->id,
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(5, $product->stock()->firstOrFail()->quantity);
    }

    public function test_only_administrators_can_change_product_prices(): void
    {
        $client = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $product = $this->createProduct(5);

        $this->actingAs($client, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['price' => 999])
            ->assertForbidden();
        $this->assertEquals(1250, (float) $product->fresh()->price);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['price' => 2000])
            ->assertOk();
        $this->assertEquals(2000, (float) $response->json('product.price'));
    }

    public function test_sale_can_include_multiple_products_and_uses_catalog_prices(): void
    {
        $client = User::factory()->create();
        $keyboard = $this->createProduct(5);
        $keyboard->update(['price' => 2500]);
        $beverage = $this->createProduct(8);
        $beverage->update(['name' => 'Bebida', 'price' => 500]);

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [
                    ['product_id' => $keyboard->id, 'quantity' => 1, 'unit_price' => 1],
                ],
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/sales', [
                'payment_method' => 'dinheiro',
                'items' => [
                    ['product_id' => $keyboard->id, 'quantity' => 1],
                    ['product_id' => $beverage->id, 'quantity' => 3],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total', 4000)
            ->assertJsonCount(2, 'sale.items')
            ->assertJsonPath('sale.items.0.unit_price', '2500.00')
            ->assertJsonPath('sale.items.1.unit_price', '500.00');

        $this->assertSame(4, $keyboard->stock()->firstOrFail()->quantity);
        $this->assertSame(5, $beverage->stock()->firstOrFail()->quantity);
    }

    public function test_manager_seller_and_cashier_permissions_and_sale_relationships(): void
    {
        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'gerente'])->save();
        $seller = User::factory()->create();
        $cashier = User::factory()->create();
        $client = User::factory()->create();
        $product = $this->createProduct(5);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['price' => 1500])
            ->assertOk()
            ->assertJsonPath('product.price', 1500);
        $this->getJson('/api/reports/sales')->assertOk();
        $this->getJson('/api/users')
            ->assertOk();
        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['user_id' => $client->id, 'name' => $client->name])
            ->assertJsonMissing(['user_id' => $manager->id]);
        $this->patchJson('/api/users/'.$seller->id.'/role', ['role' => 'vendedor'])
            ->assertOk()
            ->assertJsonPath('user.role', 'vendedor');
        $this->getJson('/api/sellers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $seller->id);
        $this->patchJson('/api/users/'.$cashier->id.'/role', ['role' => 'caixa'])
            ->assertOk()
            ->assertJsonPath('user.role', 'caixa');
        $this->patchJson('/api/users/'.$client->id.'/role', ['role' => 'administrador'])
            ->assertUnprocessable();
        $this->getJson('/api/audit-logs')->assertForbidden();

        $seller->refresh();
        $cashier->refresh();
        $sellerSession = $this->actingAs($seller, 'sanctum')->postJson('/api/cash-sessions', [
            'cash_register_id' => 1,
            'opening_amount' => 0,
        ])->assertCreated()->json('cash_session');
        $sale = $this->actingAs($seller, 'sanctum')
            ->postJson('/api/sales', [
                'user_id' => $client->id,
                'payment_method' => 'dinheiro',
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.user_id', $client->id)
            ->json('sale');

        $this->assertSame($seller->id, Sale::findOrFail($sale['id'])->creator->id);
        $this->actingAs($seller, 'sanctum')
            ->get('/api/sales/'.$sale['id'].'/invoice')
            ->assertOk()
            ->assertSee('Cliente')
            ->assertSee($client->name)
            ->assertSee('Vendedor:')
            ->assertSee($seller->name);
        $this->getJson('/api/sales/'.$sale['id'])
            ->assertOk()
            ->assertJsonPath('creator.id', $seller->id)
            ->assertJsonPath('user.id', $client->id);
        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonPath('data.0.creator.id', $seller->id);
        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sale['id']);
        $this->getJson('/api/reports/sales')->assertForbidden();
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/cash-sessions/'.$sellerSession['id'].'/close', ['counted_amount' => 2500])
            ->assertOk();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/cash-movements')
            ->assertOk();
        $register = $this->getJson('/api/cash-registers')
            ->assertOk()
            ->json('0');
        $this->postJson('/api/cash-sessions', [
            'cash_register_id' => $register['id'],
            'opening_amount' => 5000,
        ])->assertCreated()
            ->assertJsonPath('summary.expected_amount', 5000);
        $this->postJson('/api/expenses', [
            'description' => 'Despesa do caixa',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'dinheiro',
        ])->assertCreated();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/stock')->assertForbidden();

        $seller->forceFill(['role' => 'vendedor'])->save();
        $this->getJson('/api/sellers')
            ->assertOk()
            ->assertJsonFragment(['id' => $seller->id, 'name' => $seller->name, 'role' => 'vendedor']);
        $this->actingAs($seller, 'sanctum');
        $this->postJson('/api/sales', [
            'user_id' => $client->id,
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->postJson('/api/sales', [
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cash_session');
        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/sales', [
            'user_id' => $client->id,
            'seller_name' => $seller->name,
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_admin_can_create_product_stock_and_sale_for_selected_client(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $client = User::factory()->create();
        $category = Category::create([
            'name' => 'Acessórios',
            'description' => null,
            'status' => 'activo',
        ]);

        $product = $this->actingAs($admin, 'sanctum')->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Cabo',
            'price' => 1250,
            'stock' => 8,
        ])->assertCreated()
            ->assertJsonPath('product.stock', 8)
            ->json('product');

        $saleResponse = $this->postJson('/api/sales', [
            'user_id' => $client->id,
            'payment_method' => 'cartao',
            'items' => [['product_id' => $product['id'], 'quantity' => 3]],
        ])->assertCreated()
            ->assertJsonPath('sale.user_id', $client->id)
            ->assertJsonMissingPath('sale.created_by')
            ->assertJsonPath('sale.total', 3750);

        $this->assertSame(5, Stock::where('product_id', $product['id'])->firstOrFail()->quantity);
        $this->getJson('/api/reports/sales?to='.now()->toDateString().'&payment_method=cartao')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('currency', 'AOA');
        $this->get('/api/sales/'.$saleResponse->json('sale.id').'/invoice')
            ->assertOk()
            ->assertSee('3.750,00 AOA');
    }

    public function test_product_can_be_found_by_sku_or_barcode_and_identifiers_must_be_unique(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $category = Category::create([
            'name' => 'Identificadores',
            'description' => null,
            'status' => 'activo',
        ]);
        $product = $this->actingAs($admin, 'sanctum')->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Leitor',
            'sku' => 'LEI-001',
            'barcode' => '5601234567890',
            'price' => 1000,
            'stock' => 3,
        ])->assertCreated()->json('product');

        $this->getJson('/api/products/lookup/LEI-001')
            ->assertOk()
            ->assertJsonPath('id', $product['id'])
            ->assertJsonPath('stock', 3);
        $this->getJson('/api/products/lookup/5601234567890')
            ->assertOk()
            ->assertJsonPath('id', $product['id']);
        $this->getJson('/api/products?search=LEI-001')
            ->assertOk()
            ->assertJsonPath('0.sku', 'LEI-001');

        $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Outro leitor',
            'sku' => 'LEI-002',
            'barcode' => '5601234567890',
            'price' => 1000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
    }

    public function test_admin_cancellation_restores_stock_only_once_and_tracks_refund(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $client = User::factory()->create();
        $product = $this->createProduct(4);
        $sale = $this->actingAs($client, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'transferencia',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()->json('sale');

        $this->actingAs($admin, 'sanctum')->postJson('/api/sales/'.$sale['id'].'/cancel', [
            'reason' => 'Pedido duplicado',
        ])->assertOk()
            ->assertJsonPath('sale.payment_status', 'refund_pending');

        $this->assertSame(4, $product->stock()->firstOrFail()->quantity);
        $this->actingAs($admin, 'sanctum')->postJson('/api/sales/'.$sale['id'].'/cancel', [
            'reason' => 'Outra tentativa',
        ])->assertStatus(409);
        $this->assertSame(4, $product->stock()->firstOrFail()->quantity);

        $this->patchJson('/api/sales/'.$sale['id'].'/refund')
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'refunded');
    }

    public function test_catalog_stock_updates_and_low_stock_alerts_require_admin(): void
    {
        $client = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $product = $this->createProduct(2);

        $this->actingAs($client, 'sanctum')
            ->patchJson('/api/stock/'.$product->id, ['stock' => 10])
            ->assertForbidden();
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/stock/alerts')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/stock/'.$product->id, ['stock' => 1, 'reason' => 'Inventário de conferência'])
            ->assertOk()
            ->assertJsonPath('stock', 1);
        $this->getJson('/api/stock/alerts?threshold=1')
            ->assertOk()
            ->assertJsonPath('threshold', 1)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('alerts.0.quantity', 1);
        $this->getJson('/api/reports/low-stock')->assertNotFound();
    }

    public function test_product_manufacturing_and_expiration_dates_are_stored_and_validated(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $category = Category::create([
            'name' => 'Alimentos',
            'description' => null,
            'status' => 'activo',
        ]);
        $payload = [
            'category_id' => $category->id,
            'name' => 'Produto perecível',
            'price' => 1250,
            'manufacturing_date' => '2026-01-10',
            'expiration_date' => '2026-01-09',
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expiration_date');

        $product = $this->postJson('/api/products', [
            ...$payload,
            'expiration_date' => '2027-01-10',
        ])->assertCreated()
            ->assertJsonPath('product.manufacturing_date', '2026-01-10')
            ->assertJsonPath('product.expiration_date', '2027-01-10')
            ->json('product');

        $this->putJson('/api/products/'.$product['id'], [
            'manufacturing_date' => '2027-01-11',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('expiration_date');

        $this->assertDatabaseHas('products', [
            'id' => $product['id'],
            'manufacturing_date' => '2026-01-10',
            'expiration_date' => '2027-01-10',
        ]);
    }

    public function test_stock_alerts_include_expired_and_expiring_products_for_thirty_days(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'administrador'])->save();
        $expired = $this->createProduct(4);
        $expired->update([
            'manufacturing_date' => today()->subDays(90),
            'expiration_date' => today()->subDay(),
        ]);
        $expiring = $this->createProduct(7);
        $expiring->update([
            'manufacturing_date' => today()->subDays(60),
            'expiration_date' => today()->addDays(30),
        ]);
        $notExpiring = $this->createProduct(8);
        $notExpiring->update(['expiration_date' => today()->addDays(31)]);
        $withoutExpiration = $this->createProduct(9);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/stock/alerts')
            ->assertOk()
            ->assertJsonPath('expiration_alert_days', 30)
            ->assertJsonPath('expiration_alert_count', 2)
            ->assertJsonPath('expiration_alerts.0.product_id', $expired->id)
            ->assertJsonPath('expiration_alerts.0.alert', 'expired')
            ->assertJsonPath('expiration_alerts.0.days_until_expiration', -1)
            ->assertJsonPath('expiration_alerts.1.product_id', $expiring->id)
            ->assertJsonPath('expiration_alerts.1.alert', 'expiring_soon')
            ->assertJsonPath('expiration_alerts.1.days_until_expiration', 30);

        $this->assertDatabaseHas('products', ['id' => $notExpiring->id]);
        $this->assertDatabaseHas('products', ['id' => $withoutExpiration->id, 'expiration_date' => null]);
    }

    public function test_authenticated_sale_requires_a_supported_payment_method(): void
    {
        $client = User::factory()->create();
        $product = $this->createProduct(3);

        $this->actingAs($client, 'sanctum')->postJson('/api/sales', [
            'payment_method' => 'mobile_money',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    private function createProduct(int $quantity): Product
    {
        $category = Category::create([
            'name' => 'Geral '.uniqid(),
            'description' => null,
            'status' => 'activo',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produto',
            'description' => null,
            'price' => 1250,
        ]);
        Stock::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }
}

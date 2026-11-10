<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSupplierImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_upload_converts_supplier_headings_without_ai(): void
    {
        Http::preventStrayRequests();
        $stream = tmpfile();
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile(stream_get_meta_data($stream)['uri']);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Part No.', 'Item Description', 'Dealer Price', 'Retail Price', 'Available Qty']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['000123', 'Brake Pad Set', 125.50, 200, 25]));
        $writer->close();
        $file = UploadedFile::fake()->createWithContent('supplier.xlsx', file_get_contents(stream_get_meta_data($stream)['uri']));
        fclose($stream);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.suppliers.imports.upload'), [
                'supplier_name' => 'Auto Supplier', 'supplier_code' => 'AUTO-SUPPLIER',
                'price_file' => $file,
            ])->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->assertDatabaseHas('supplier_import_rows', [
            'supplier_sku' => '000123', 'product_name' => 'Brake Pad Set',
            'unit_price' => 125.50, 'available_quantity' => 25,
        ]);
    }

    public function test_unfamiliar_standard_upload_automatically_uses_ai_and_keeps_availability(): void
    {
        $this->fakeExtraction([$this->row(['available_quantity' => '25'])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.suppliers.imports.upload'), [
                'supplier_name' => 'Auto AI Supplier', 'supplier_code' => 'AUTO-AI',
                'price_file' => UploadedFile::fake()->createWithContent('supplier.csv',
                    "Reference,Merchandise,Trade Amount\n000123,Brake Pad Set,125.50"),
            ])->assertSessionHasNoErrors();
        $this->assertSame('ai', SupplierImport::firstOrFail()->extraction_method);
        $this->assertDatabaseHas('supplier_import_rows', ['supplier_sku' => '000123', 'available_quantity' => 25]);
        Http::assertSentCount(1);
    }

    private function fakeExtraction(array $rows, bool $complete = true, string $status = 'completed'): void
    {
        config(['openai.api_key' => 'test-only-key', 'openai.ca_bundle' => '', 'openai.base_url' => 'https://api.openai.com/v1', 'openai.supplier_import_model' => 'test-model']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => $status, 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['complete' => $complete, 'rows' => $rows])]],
        ]]])]);
    }

    private function row(array $overrides = []): array
    {
        return $overrides + ['supplier_sku' => '000123', 'product_name' => 'Brake Pad Set', 'unit_price' => '125.50', 'currency' => 'PHP', 'source_reference' => 'Page 1, row 2', 'warning' => null];
    }

    private function upload(): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('admin.suppliers'))->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'AI Supplier', 'supplier_code' => 'AI-SUPPLIER', 'import_mode' => 'ai', 'default_currency' => 'PHP',
            'price_file' => UploadedFile::fake()->createWithContent('supplier.pdf', "%PDF-1.4\nSample supplier document\n%%EOF"),
        ]);
    }

    public function test_ai_rows_are_staged_for_owner_review_and_publishing_never_changes_store_stock_or_cost(): void
    {
        $this->fakeExtraction([$this->row()]);
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => '000123', 'name' => 'Brake Pad Set', 'unit_cost' => 80, 'unit_price' => 200, 'current_stock' => 7]);
        $this->actingAs($owner);
        $this->upload()->assertSessionHasNoErrors();
        $import = SupplierImport::firstOrFail();
        $this->assertSame('pending', $import->status);
        $this->assertSame('ai', $import->extraction_method);
        $this->assertSame(1, $import->valid_count);
        $this->assertSame(0, SupplierPrice::count());
        $this->assertDatabaseHas('supplier_import_rows', ['supplier_sku' => '000123', 'unit_price' => 125.50, 'source_reference' => 'Page 1, row 2']);
        Http::assertSent(fn ($request) => $request['store'] === false && $request['text']['format']['strict'] === true && $request['input'][0]['content'][0]['type'] === 'input_file');
        $this->get(route('admin.suppliers', ['import' => $import->supplier_import_id]))->assertOk()->assertSee('AI extracted')->assertSee('Review / correct');
        $this->post(route('admin.suppliers.imports.approve', $import))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_prices', ['supplier_sku' => '000123', 'unit_price' => 125.50, 'source_type' => 'ai_document']);
        $this->assertSame(7, $product->fresh()->current_stock);
        $this->assertSame('80.00', $product->fresh()->unit_cost);
    }

    public function test_uncertain_or_missing_values_block_approval_until_owner_corrects_and_confirms_them(): void
    {
        $this->fakeExtraction([$this->row(['supplier_sku' => null, 'unit_price' => null, 'warning' => 'SKU and unit price are illegible.'])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->upload()->assertSessionHasNoErrors();
        $import = SupplierImport::firstOrFail();
        $row = $import->rows()->firstOrFail();
        $this->assertSame(1, $import->error_count);
        $this->post(route('admin.suppliers.imports.approve', $import))->assertSessionHasErrors();
        $this->assertSame(0, SupplierPrice::count());
        $this->patch(route('admin.suppliers.imports.rows.update', [$import, $row]), [
            'supplier_sku' => 'CORRECT-001', 'product_name' => 'Corrected Brake Pad', 'unit_price' => '99.50', 'currency' => 'PHP', 'confirm_review' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $import->fresh()->error_count);
        $this->assertNull($row->fresh()->validation_errors);
        $this->post(route('admin.suppliers.imports.approve', $import))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supplier_prices', ['supplier_sku' => 'CORRECT-001', 'unit_price' => 99.50]);
    }

    public function test_incomplete_results_and_provider_failures_leave_no_partial_supplier_imports(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fakeExtraction([$this->row()], false);
        $this->upload()->assertSessionHasErrors('price_file');
        $this->assertSame(0, SupplierImport::count());
        $this->assertSame(0, Supplier::count());
        $this->fakeExtraction([$this->row()], true, 'incomplete');
        $this->upload()->assertSessionHasErrors('price_file');
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unavailable']], 429)]);
        $this->upload()->assertSessionHasErrors('price_file');
        $this->assertSame(0, SupplierImport::count());
        $this->assertSame(0, SupplierPrice::count());
    }

    public function test_duplicate_ai_skus_remain_blocked_until_corrected_and_published_rows_are_read_only(): void
    {
        $this->fakeExtraction([$this->row(), $this->row(['product_name' => 'Other Brake Pad'])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->upload()->assertSessionHasNoErrors();
        $import = SupplierImport::firstOrFail();
        $this->assertSame(1, $import->error_count);
        $row = $import->rows()->orderByDesc('row_number')->firstOrFail();
        $values = ['supplier_sku' => 'SECOND-001', 'product_name' => 'Other Brake Pad', 'unit_price' => 150, 'currency' => 'PHP', 'confirm_review' => '1'];
        $this->patch(route('admin.suppliers.imports.rows.update', [$import, $row]), $values)->assertSessionHasNoErrors();
        $this->assertSame(0, $import->fresh()->error_count);
        $this->post(route('admin.suppliers.imports.approve', $import))->assertSessionHasNoErrors();
        $this->patch(route('admin.suppliers.imports.rows.update', [$import, $row]), $values)->assertForbidden();
    }

    public function test_staff_cannot_submit_documents_or_correct_import_rows(): void
    {
        $this->fakeExtraction([$this->row()]);
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner);
        $this->upload()->assertSessionHasNoErrors();
        $import = SupplierImport::firstOrFail();
        $row = $import->rows()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $this->upload()->assertForbidden();
        $this->patch(route('admin.suppliers.imports.rows.update', [$import, $row]))->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_ai_spreadsheet_extraction_reads_the_sheet_in_full_and_does_not_require_standard_headers(): void
    {
        $this->fakeExtraction([$this->row()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'AI Supplier', 'supplier_code' => 'AI-SUPPLIER', 'import_mode' => 'ai',
            'price_file' => UploadedFile::fake()->createWithContent('supplier.csv', "Part Number,Description,Dealer Price\n000123,Brake Pad Set,125.50\n"),
        ])->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => $request['input'][0]['content'][0]['type'] === 'input_text' && str_contains($request['input'][0]['content'][0]['text'], '000123'));
        $this->assertDatabaseHas('supplier_import_rows', ['supplier_sku' => '000123']);
    }

    public function test_missing_configuration_does_not_send_a_document_or_create_an_import(): void
    {
        config(['openai.api_key' => '']);
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->upload()->assertSessionHasErrors('price_file');
        Http::assertNothingSent();
        $this->assertSame(0, SupplierImport::count());
    }

    public function test_owner_cannot_edit_a_row_through_a_different_import(): void
    {
        $this->fakeExtraction([$this->row()]);
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner);
        $this->upload()->assertSessionHasNoErrors();
        $first = SupplierImport::firstOrFail();
        $other = SupplierImport::create(['supplier_id' => $first->supplier_id, 'source_filename' => 'other.pdf', 'uploaded_by' => $owner->id]);
        $this->patch(route('admin.suppliers.imports.rows.update', [$other, $first->rows()->first()]))->assertNotFound();
    }
}

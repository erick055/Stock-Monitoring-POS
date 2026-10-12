<?php

namespace App\Services;

use App\Models\SupplierImport;
use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class SupplierSpreadsheetImporter
{
    private const REQUIRED_HEADERS = ['supplier_sku', 'product_name', 'unit_price'];

    private const ALLOWED_HEADERS = [
        'supplier_sku', 'product_name', 'internal_sku', 'currency', 'unit_price',
        'available_quantity', 'minimum_order_quantity', 'lead_time_days', 'effective_date',
    ];

    public function __construct(private readonly SupplierProductMatcher $productMatcher) {}

    public function stage(SupplierImport $import, string $path, string $extension): void
    {
        $reader = $this->reader($extension);
        $reader->open($path);

        $headers = [];
        $rowCount = 0;
        $validCount = 0;
        $errorCount = 0;
        $seenSupplierSkus = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $values = array_map($this->stringValue(...), $row->toArray());

                    if ($headers === []) {
                        $headers = array_map($this->normalizeHeader(...), $values);
                        $this->validateHeaders($headers);

                        continue;
                    }

                    if ($this->isEmptyRow($values)) {
                        continue;
                    }

                    if ($rowCount >= 2000) {
                        throw new InvalidArgumentException('The file exceeds the 2,000-row import limit.');
                    }

                    $rowCount++;
                    $data = array_fill_keys(self::ALLOWED_HEADERS, null);
                    foreach ($headers as $columnIndex => $header) {
                        if (in_array($header, self::ALLOWED_HEADERS, true)) {
                            $data[$header] = trim($values[$columnIndex] ?? '');
                        }
                    }

                    $errors = $this->validateRow($data);
                    $supplierSkuKey = mb_strtolower((string) $data['supplier_sku']);
                    if ($supplierSkuKey !== '' && isset($seenSupplierSkus[$supplierSkuKey])) {
                        $errors[] = 'Duplicate supplier_sku in this file.';
                    }
                    $seenSupplierSkus[$supplierSkuKey] = true;

                    $product = $this->productMatcher->match(
                        $data['internal_sku'],
                        $data['supplier_sku'],
                        $data['product_name'],
                    );

                    $import->rows()->create([
                        'row_number' => $rowIndex,
                        'supplier_sku' => mb_substr((string) $data['supplier_sku'], 0, 255),
                        'product_name' => mb_substr((string) $data['product_name'], 0, 255),
                        'internal_sku' => $product?->sku ?? ($data['internal_sku'] ? mb_substr($data['internal_sku'], 0, 255) : null),
                        'product_id' => $product?->product_id,
                        'currency' => strtoupper(mb_substr($data['currency'] ?: 'PHP', 0, 3)),
                        'unit_price' => is_numeric($data['unit_price']) && (float) $data['unit_price'] > 0 && (float) $data['unit_price'] <= 9999999999.99 ? $data['unit_price'] : 0,
                        'available_quantity' => $this->nullableInteger($data['available_quantity']),
                        'minimum_order_quantity' => $this->nullableInteger($data['minimum_order_quantity']),
                        'lead_time_days' => $this->nullableInteger($data['lead_time_days']),
                        'effective_date' => $this->nullableDate($data['effective_date']),
                        'validation_errors' => $errors ?: null,
                    ]);

                    $errors === [] ? $validCount++ : $errorCount++;
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($rowCount === 0) {
            throw new InvalidArgumentException('The spreadsheet contains no price rows.');
        }

        $import->update([
            'row_count' => $rowCount,
            'valid_count' => $validCount,
            'error_count' => $errorCount,
        ]);
    }

    private function reader(string $extension): ReaderInterface
    {
        return match (mb_strtolower($extension)) {
            'csv' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new InvalidArgumentException('Only CSV and XLSX files are supported.'),
        };
    }

    private function validateHeaders(array $headers): void
    {
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing required columns: '.implode(', ', $missing).'.');
        }
    }

    private function validateRow(array $data): array
    {
        $errors = [];

        if (blank($data['supplier_sku'])) {
            $errors[] = 'supplier_sku is required.';
        }
        if (blank($data['product_name'])) {
            $errors[] = 'product_name is required.';
        }
        foreach (['supplier_sku', 'product_name', 'internal_sku'] as $field) {
            if (mb_strlen((string) $data[$field]) > 255) $errors[] = "{$field} cannot exceed 255 characters.";
        }
        if (! is_numeric($data['unit_price']) || (float) $data['unit_price'] <= 0) {
            $errors[] = 'unit_price must be greater than zero.';
        }
        if (is_numeric($data['unit_price']) && (float) $data['unit_price'] > 9999999999.99) {
            $errors[] = 'unit_price exceeds the maximum supported amount.';
        }
        if (filled($data['currency']) && ! preg_match('/^[A-Za-z]{3}$/', $data['currency'])) {
            $errors[] = 'currency must be a three-letter code.';
        }
        foreach (['available_quantity', 'minimum_order_quantity', 'lead_time_days'] as $field) {
            if (filled($data[$field]) && filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
                $errors[] = "{$field} must be a whole number.";
            }
            if (filled($data[$field]) && filter_var($data[$field], FILTER_VALIDATE_INT) !== false
                && ((int) $data[$field] < 0 || (int) $data[$field] > 2147483647)) {
                $errors[] = "{$field} must be between 0 and 2147483647.";
            }
        }
        if (filled($data['effective_date']) && $this->nullableDate($data['effective_date']) === null) {
            $errors[] = 'effective_date must be a valid date.';
        }

        return $errors;
    }

    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_'));
    }

    private function stringValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) ($value ?? ''));
    }

    private function isEmptyRow(array $values): bool
    {
        return count(array_filter($values, fn ($value) => $value !== '')) === 0;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return filled($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value >= 0 && (int) $value <= 2147483647 ? (int) $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return now()->parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

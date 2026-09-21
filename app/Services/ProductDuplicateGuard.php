<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductDuplicateGuard
{
    public function findInactiveBySku(mixed $sku): ?Product
    {
        $normalizedSku = $this->normalize($sku);
        if ($normalizedSku === '') {
            return null;
        }

        return Product::query()
            ->where('is_active', false)
            ->get(['product_id', 'sku', 'name', 'current_stock', 'is_active'])
            ->first(fn (Product $product) => $this->normalize($product->sku) === $normalizedSku);
    }

    public function assertUnique(array $attributes, ?Product $except = null, string $errorBag = 'default'): void
    {
        $sku = $this->normalize($attributes['sku'] ?? null);
        $name = $this->normalize($attributes['name'] ?? null);
        $manufacturer = $this->normalize($attributes['manufacturer'] ?? null);
        $partNumber = $this->normalize($attributes['manufacturer_part_number'] ?? null);
        $errors = [];

        $products = Product::query()
            ->when($except, fn ($query) => $query->where('product_id', '!=', $except->product_id))
            ->get(['product_id', 'sku', 'name', 'manufacturer', 'manufacturer_part_number']);

        foreach ($products as $product) {
            if ($sku !== '' && $sku === $this->normalize($product->sku)) {
                $errors['sku'] = "SKU {$attributes['sku']} already belongs to {$product->name}.";
            }

            if ($name !== '' && $name === $this->normalize($product->name)) {
                $errors['name'] = "A product named {$attributes['name']} already exists as SKU {$product->sku}.";
            }

            $existingPartNumber = $this->normalize($product->manufacturer_part_number);
            $existingManufacturer = $this->normalize($product->manufacturer);
            if ($partNumber !== '' && $partNumber === $existingPartNumber
                && ($manufacturer === '' || $existingManufacturer === '' || $manufacturer === $existingManufacturer)) {
                $errors['manufacturer_part_number'] = "Manufacturer part number {$attributes['manufacturer_part_number']} already belongs to {$product->name}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag($errorBag);
        }
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim((string) $value)), 'UTF-8');
    }
}

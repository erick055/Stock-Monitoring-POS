<?php

namespace App\Services;

use App\Models\Product;

class SupplierProductMatcher
{
    private ?array $skuMap = null;

    private ?array $nameMap = null;

    public function match(?string $internalSku, ?string $supplierSku, ?string $productName): ?Product
    {
        $this->loadMaps();

        foreach ([$internalSku, $supplierSku] as $sku) {
            $key = $this->normalize($sku);
            if ($key !== '' && isset($this->skuMap[$key])) {
                return $this->skuMap[$key];
            }
        }

        $nameKey = $this->normalize($productName);

        return $nameKey !== '' ? ($this->nameMap[$nameKey] ?? null) : null;
    }

    private function loadMaps(): void
    {
        if ($this->skuMap !== null) {
            return;
        }

        $this->skuMap = [];
        $nameGroups = [];

        Product::query()->where('is_active', true)->get(['product_id', 'sku', 'name'])->each(function (Product $product) use (&$nameGroups) {
            $this->skuMap[$this->normalize($product->sku)] = $product;
            $nameGroups[$this->normalize($product->name)][] = $product;
        });

        $this->nameMap = [];
        foreach ($nameGroups as $name => $products) {
            if (count($products) === 1) {
                $this->nameMap[$name] = $products[0];
            }
        }
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)), 'UTF-8');
    }
}

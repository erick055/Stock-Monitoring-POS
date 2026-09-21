<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SupplierPrice;

class SupplierProductMatcher
{
    private ?array $skuMap = null;

    private ?array $partNumberMap = null;

    private ?array $nameMap = null;

    public function match(?string $internalSku, ?string $supplierSku, ?string $productName): ?Product
    {
        $this->loadMaps();

        $internalSkuKey = $this->normalize($internalSku);
        if ($internalSkuKey !== '' && isset($this->skuMap[$internalSkuKey])) {
            return $this->skuMap[$internalSkuKey];
        }

        $supplierSkuKey = $this->normalize($supplierSku);
        if ($supplierSkuKey !== '' && isset($this->skuMap[$supplierSkuKey])) {
            return $this->skuMap[$supplierSkuKey];
        }

        if ($supplierSkuKey !== '' && isset($this->partNumberMap[$supplierSkuKey])) {
            return $this->partNumberMap[$supplierSkuKey];
        }

        $nameKey = $this->normalize($productName);

        return $nameKey !== '' ? ($this->nameMap[$nameKey] ?? null) : null;
    }

    public function matchBySku(?string $sku): ?Product
    {
        $this->loadMaps();
        $key = $this->normalize($sku);

        return $key !== '' ? ($this->skuMap[$key] ?? null) : null;
    }

    public function linkUnmatchedPricesBySku(): int
    {
        $matched = 0;

        SupplierPrice::query()
            ->whereNull('product_id')
            ->where('auto_match_disabled', false)
            ->select(['supplier_price_id', 'supplier_sku'])
            ->chunkById(200, function ($prices) use (&$matched) {
                foreach ($prices as $price) {
                    $product = $this->matchBySku($price->supplier_sku);
                    if (! $product) {
                        continue;
                    }

                    $matched += SupplierPrice::query()
                        ->whereKey($price->supplier_price_id)
                        ->whereNull('product_id')
                        ->update(['product_id' => $product->product_id]);
                }
            }, 'supplier_price_id');

        return $matched;
    }

    private function loadMaps(): void
    {
        if ($this->skuMap !== null) {
            return;
        }

        $skuGroups = [];
        $partNumberGroups = [];
        $nameGroups = [];

        Product::query()->where('is_active', true)
            ->get(['product_id', 'sku', 'name', 'manufacturer_part_number'])
            ->each(function (Product $product) use (&$skuGroups, &$partNumberGroups, &$nameGroups) {
                $skuGroups[$this->normalize($product->sku)][] = $product;

                $partNumber = $this->normalize($product->manufacturer_part_number);
                if ($partNumber !== '') {
                    $partNumberGroups[$partNumber][] = $product;
                }

                $nameGroups[$this->normalize($product->name)][] = $product;
        });

        $this->skuMap = $this->uniqueMap($skuGroups);
        $this->partNumberMap = $this->uniqueMap($partNumberGroups);
        $this->nameMap = $this->uniqueMap($nameGroups);
    }

    private function uniqueMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $key => $products) {
            if ($key !== '' && count($products) === 1) {
                $map[$key] = $products[0];
            }
        }

        return $map;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)), 'UTF-8');
    }
}

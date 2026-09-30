<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class InventoryLiveService
{
    public function products(): Collection
    {
        return Product::query()
            ->with('activePromotion.bundleProduct')
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function version(?Collection $products = null): string
    {
        $products ??= $this->products();

        return hash('sha256', $products->map(fn (Product $product) => [
            $product->product_id,
            $product->updated_at?->format('Y-m-d H:i:s.u'),
            $product->current_stock,
            $product->unit_price,
            $product->is_active,
            $product->activePromotion?->promotion_id,
            $product->activePromotion?->updated_at?->format('Y-m-d H:i:s.u'),
        ])->values()->toJson());
    }

    public function posProducts(?Collection $products = null): Collection
    {
        $products ??= $this->products();
        $labels = [];
        foreach ($products as $product) {
            $label = $this->cleanCategory($product->category);
            $labels[$this->categoryKey($label)] ??= $label;
        }

        return $products->where('current_stock', '>', 0)->map(function (Product $product) use ($labels) {
            $categoryKey = $this->categoryKey($this->cleanCategory($product->category));

            return [
                'id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->selling_price,
                'basePrice' => (float) $product->unit_price,
                'promotion' => $product->activePromotion ? [
                    'label' => $product->activePromotion->action_label,
                    'discount' => (float) $product->activePromotion->discount_percent,
                    'bundleNote' => $product->activePromotion->bundle_note,
                    'bundleProduct' => $product->activePromotion->bundleProduct ? [
                        'id' => $product->activePromotion->bundleProduct->product_id,
                        'name' => $product->activePromotion->bundleProduct->name,
                        'sku' => $product->activePromotion->bundleProduct->sku,
                    ] : null,
                ] : null,
                'category' => $labels[$categoryKey],
                'categoryKey' => $categoryKey,
                'shelfLocation' => $product->shelf_location,
                'stock' => $product->current_stock,
            ];
        })->values();
    }

    private function cleanCategory(?string $category): string
    {
        $category = preg_replace('/\s+/u', ' ', trim((string) $category));

        return $category !== '' ? $category : 'Uncategorized';
    }

    private function categoryKey(string $category): string
    {
        return mb_strtolower($category, 'UTF-8');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\InventoryLiveService;
use Illuminate\Http\JsonResponse;

class InventoryLiveController extends Controller
{
    public function __invoke(InventoryLiveService $inventory): JsonResponse
    {
        $products = $inventory->products();

        return response()->json([
            'version' => $inventory->version($products),
            'products' => $inventory->posProducts($products),
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}

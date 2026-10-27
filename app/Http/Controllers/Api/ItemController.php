<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

class ItemController extends Controller
{
    /** Daftar barang bantuan untuk pilihan di formulir stok. */
    public function index(): JsonResponse
    {
        $items = Item::query()->with('category:id,name')->orderBy('name')->get()
            ->map(fn (Item $i) => [
                'id' => $i->id,
                'sku' => $i->sku,
                'name' => $i->name,
                'unit' => $i->unit,
                'category' => $i->category?->name,
                'is_perishable' => $i->is_perishable,
            ]);

        return response()->json(['data' => $items]);
    }
}

<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TablePagination
{
    public static function size(Request $request, string $name = 'per_page'): int
    {
        $value = $request->integer($name, 20);

        return in_array($value, [20, 50, 100], true) ? $value : 20;
    }

    public static function collection(Collection $items, Request $request, string $name, string $anchor): LengthAwarePaginator
    {
        $size = self::size($request, $name.'_rows');
        $page = LengthAwarePaginator::resolveCurrentPage($name.'_page');

        return (new LengthAwarePaginator($items->forPage($page, $size)->values(), $items->count(), $size, $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $name.'_page']))->fragment($anchor);
    }
}

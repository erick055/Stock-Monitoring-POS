@php
$dashboard = [
    'role_name' => 'Staff',
    'display_name' => auth()->user()->name,
    'first_name' => explode(' ', auth()->user()->name)[0],
    'initials' => 'MS',
    'description' => 'Manage today’s sales and stock operations.',
    'navigation' => [
        ['⌂','Dashboard','#'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
        ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','/staff/compatibility'],
    ],
    'stats' => [
        ['SALES TODAY','₱8,420','32 transactions','cyan'], ['ITEMS SOLD','86','Today','purple'],
        ['LOW STOCK ITEMS','6','Report to admin','red'], ['OPEN ORDERS','4','2 arriving today','orange'],
    ],
    'modules_title' => 'System Modules',
    'modules' => [
        ['▣','Stock Management','Open Module','/staff/stock-management'], ['▤','POS Checkout','Open Module','/staff/pos'],
        ['◇','Return & Damage','Open Module','/staff/returns'], ['⚙','Part Compatibility','Open Module','/staff/compatibility'],
    ],
    'chart_kicker' => 'TODAY',
    'chart_title' => 'Shift Progress',
    'activity' => [
        ['cyan','Sale completed','Receipt #OR-032 · ₱1,250'], ['purple','Stock received','12 Brake Pads added'],
        ['orange','Purchase order sent','PO-2026-018'], ['red','Stock warning','Oil Filter has 4 units left'],
    ],
];
@endphp
@include('layouts.dashboard', ['dashboard' => $dashboard])


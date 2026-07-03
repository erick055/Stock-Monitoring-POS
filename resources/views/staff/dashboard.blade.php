@php
$dashboard = [
    'role_name' => 'Staff',
    'display_name' => auth()->user()->name,
    'first_name' => explode(' ', auth()->user()->name)[0],
    'initials' => 'MS',
    'description' => 'Manage today’s sales and stock operations.',
    'navigation' => [
        ['⌂','Dashboard','#'], ['▤','Point of Sale','/staff/pos'], ['▣','View Stocks','/staff/stocks'],
        ['⇄','Procurement','/staff/procurement'], ['◇','Returns & Damages','/staff/returns'],
        ['◷','Sales History','/staff/sales-history'], ['♙','Account','/staff/account'],
    ],
    'stats' => [
        ['SALES TODAY','₱8,420','32 transactions','cyan'], ['ITEMS SOLD','86','Today','purple'],
        ['LOW STOCK ITEMS','6','Report to admin','red'], ['OPEN ORDERS','4','2 arriving today','orange'],
    ],
    'modules_title' => 'Daily Operations',
    'modules' => [
        ['▤','New Sale','Start a POS transaction','/staff/pos'], ['▣','View Stocks','Check product availability','/staff/stocks'],
        ['⇄','Procurement','Create a purchase order','/staff/procurement'], ['◇','Returns & Damages','Record returned items','/staff/returns'],
        ['◷','Sales History','Review previous transactions','/staff/sales-history'], ['♧','Suppliers','View supplier directory','/staff/suppliers'],
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


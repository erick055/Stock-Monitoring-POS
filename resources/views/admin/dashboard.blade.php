@php
$dashboard = [
    'role_name' => 'Administrator',
    'display_name' => auth()->user()->name,
    'first_name' => explode(' ', auth()->user()->name)[0],
    'initials' => 'EA',
    'description' => 'Monitor inventory, sales, and business performance.',
    'navigation' => [
        ['⌂','Dashboard','#'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '/admin/deadstock'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
    ],
    'stats' => [
        ['TOTAL PRODUCTS','156','+12 this month','purple'], ['TOTAL STOCK','3,456','Across 8 categories','violet'],
        ['LOW STOCK ITEMS','6','Needs attention','red'], ['TOTAL SALES','₱45,320','+12.5% this month','cyan'],
    ],
    'modules_title' => 'System Modules',
    'modules' => [
        ['▣','Stock Management','Monitor quantities and movements','/admin/inventory'], ['□','Product Catalog','Review products and current stock','/admin/products'],
        ['⌁','Data Analytics','Track sales and profitability','/admin/analytics'], ['!','Low Stock Alerts','Review critical inventory','/admin/low-stocks'],
        ['◉','Dead Stock Detection','Find slow-moving products','/admin/dead-stock'], ['◇','Returns & Damages','Manage product exceptions','/admin/returns'],
        ['♧','Supplier Price','Review suppliers and pricing','/admin/suppliers'], ['⚙','Part Compatibility','Track compatible parts and schedules','/admin/compatibility'],
    ],
    'chart_kicker' => 'PERFORMANCE',
    'chart_title' => 'Sales Overview',
    'activity' => [
        ['cyan','Stock-in completed','24 items added by Staff'], ['purple','New user created','Staff account was approved'],
        ['red','Low-stock alert','Engine Oil 1L has 3 units left'], ['orange','Return approved','Return #RT-104 was processed'],
    ],
];
@endphp
@include('layouts.dashboard', ['dashboard' => $dashboard])

<?php

/**
 * Branch color configuration — canonical single source of truth.
 *
 * Consumed by <x-erp.branch-pill> and any view that needs branch-colored
 * surfaces. Read via config('branches.colors.HO') or App\Support\BranchColor::get($code).
 *
 * Confirmed colors:
 *   HO  = Red    #dc2626  (Head Office — "Red Branch")
 *   PAT = Blue   #2563eb  (Paton)
 *   NOW = Green  #16a34a  (Nawabganj)
 *   TAR = Orange #ea580c  (Tangail)
 *
 * NOTE: A future phase may migrate this to a `color_hex` column on the
 * `branches` table so colors are DB-editable. The config remains the fallback.
 */

return [

    'colors' => [
        'HO' => [
            'code' => 'HO',
            'name' => 'Head Office',
            'name_bn' => 'হেড অফিস',
            'color_name' => 'Red',
            'color_hex' => '#dc2626',
            'bg_class' => 'bg-red-100',
            'text_class' => 'text-red-700',
            'border_class' => 'border-red-400',
            'gradient_from' => 'from-red-500',
            'gradient_to' => 'to-red-600',
        ],
        'PAT' => [
            'code' => 'PAT',
            'name' => 'Paton',
            'name_bn' => 'পাটন',
            'color_name' => 'Blue',
            'color_hex' => '#2563eb',
            'bg_class' => 'bg-blue-100',
            'text_class' => 'text-blue-700',
            'border_class' => 'border-blue-400',
            'gradient_from' => 'from-blue-500',
            'gradient_to' => 'to-blue-600',
        ],
        'NOW' => [
            'code' => 'NOW',
            'name' => 'Nawabganj',
            'name_bn' => 'নবাবগঞ্জ',
            'color_name' => 'Green',
            'color_hex' => '#16a34a',
            'bg_class' => 'bg-green-100',
            'text_class' => 'text-green-700',
            'border_class' => 'border-green-400',
            'gradient_from' => 'from-green-500',
            'gradient_to' => 'to-green-600',
        ],
        'TAR' => [
            'code' => 'TAR',
            'name' => 'Tangail',
            'name_bn' => 'টাঙ্গাইল',
            'color_name' => 'Orange',
            'color_hex' => '#ea580c',
            'bg_class' => 'bg-orange-100',
            'text_class' => 'text-orange-700',
            'border_class' => 'border-orange-400',
            'gradient_from' => 'from-orange-500',
            'gradient_to' => 'to-orange-600',
        ],
    ],

    'default' => 'HO',

];

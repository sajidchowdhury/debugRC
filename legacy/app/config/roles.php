<?php
/**
 * Canonical ERP role definitions (stored in employees.role).
 *
 * Tiers:
 *   superadmin — top tier; only superadmin can grant/modify superadmin accounts
 *   admin      — user/employee/permission management (Auth::isAdmin())
 *   operational — scoped by menu permissions + route_roles.php matrix
 *
 * @see docs/ROLE_DEFINITIONS.md
 * @see app/config/route_roles.php
 */
return [
    'superadmin' => [
        'label'       => 'Super Admin',
        'tier'        => 'superadmin',
        'description' => 'Full system control including company-critical actions and superadmin account management.',
        'assignable_by' => ['superadmin'],
    ],
    'admin' => [
        'label'       => 'Admin',
        'tier'        => 'admin',
        'description' => 'User accounts, employees, permissions, and normal administrative work.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'manager' => [
        'label'       => 'Manager',
        'tier'        => 'operational',
        'description' => 'Branch operations: sales oversight, reversals, reports, warehouse coordination.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'accountant' => [
        'label'       => 'Accountant',
        'tier'        => 'operational',
        'description' => 'Payments, GL reconciliation, financial reports, return confirmations.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'salesman' => [
        'label'       => 'Salesman',
        'tier'        => 'operational',
        'description' => 'Sales invoices, challans (read), customer-facing day-to-day sales work.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'warehouse_manager' => [
        'label'       => 'Warehouse Manager',
        'tier'        => 'operational',
        'description' => 'Godown/challan preparation, stock movements, return confirmations.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'dispatcher' => [
        'label'       => 'Dispatcher',
        'tier'        => 'operational',
        'description' => 'Dispatch and challan execution alongside warehouse staff.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'hr' => [
        'label'       => 'HR',
        'tier'        => 'operational',
        'description' => 'Employee master data and HR-related modules (as granted by menu permissions).',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'user' => [
        'label'       => 'User',
        'tier'        => 'operational',
        'description' => 'Generic operational login; access controlled entirely by menu permissions.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
    'other' => [
        'label'       => 'Other',
        'tier'        => 'operational',
        'description' => 'Catch-all operational role for custom access profiles.',
        'assignable_by' => ['superadmin', 'admin'],
    ],
];

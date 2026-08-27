<?php
declare(strict_types=1);

/**
 * Executable-friendly E2E contract for a browser/CI runner.
 * This file intentionally does not fake external payment approval.
 */
$steps = [
    'customer_registration',
    'customer_login',
    'browse_category',
    'open_product',
    'add_product_to_cart',
    'update_cart_quantity',
    'checkout_requires_authenticated_customer',
    'create_order',
    'create_payment',
    'receive_authenticated_webhook',
    'apply_payment_state_once',
    'commit_or_release_stock_once',
    'show_customer_financial_history',
    'admin_reconciliation_view',
];

foreach ($steps as $i => $step) printf("E2E-%02d %s\n", $i + 1, $step);

echo "E2E CONTRACT READY\n";

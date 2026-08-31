<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$order = (string)file_get_contents($root . '/order.php');
$config = (string)file_get_contents($root . '/config.php');
$adminOrders = (string)file_get_contents($root . '/admin/orders.php');
$adminPayments = (string)file_get_contents($root . '/admin/payments.php');
$orderService = (string)file_get_contents($root . '/src/Services/OrderService.php');

$checks = [
    'customer_order_requires_login' => str_contains($order, 'require_login();'),
    'customer_order_scoped_by_authenticated_user' => str_contains($order, 'getOrderForCustomer($orderId, $userId)'),
    'service_enforces_customer_scope' => str_contains($orderService, 'findByIdAndUser($orderId, $userId'),
    'admin_role_gate_exists' => str_contains($config, 'function require_admin(): void'),
    'admin_orders_enforce_role' => str_contains($adminOrders, 'require_admin();'),
    'admin_payments_enforce_role' => str_contains($adminPayments, 'require_admin();'),
    'customer_cancel_uses_authenticated_owner' => str_contains($order, 'cancelByCustomer($orderId, $userId)'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
    if (!$ok) $failed[] = $name;
}
exit($failed ? 1 : 0);

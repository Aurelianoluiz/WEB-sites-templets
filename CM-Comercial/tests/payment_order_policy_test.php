<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_order_policy.php';

$paid = payment_order_decision('paid', 'pending');
if ($paid['allowed'] !== true || $paid['action'] !== 'confirm_order') {
    fwrite(STDERR, "FAIL: paid/pending must confirm order\n");
    exit(1);
}

$failed = payment_order_decision('failed', 'pending');
if ($failed['allowed'] !== true || $failed['action'] !== 'keep_order_pending') {
    fwrite(STDERR, "FAIL: failed/pending must keep order pending\n");
    exit(1);
}

$cancelled = payment_order_decision('cancelled', 'confirmed');
if ($cancelled['allowed'] !== true || $cancelled['action'] !== 'cancel_order') {
    fwrite(STDERR, "FAIL: cancelled/confirmed must cancel order\n");
    exit(1);
}

$refunded = payment_order_decision('refunded', 'confirmed');
if ($refunded['allowed'] !== true || $refunded['action'] !== 'review_refund') {
    fwrite(STDERR, "FAIL: refunded must require explicit review\n");
    exit(1);
}

$noChange = payment_order_decision('paid', 'cancelled');
if ($noChange['allowed'] !== false || $noChange['action'] !== 'no_change') {
    fwrite(STDERR, "FAIL: paid/cancelled must not advance automatically\n");
    exit(1);
}

echo "PASS: payment order policy\n";

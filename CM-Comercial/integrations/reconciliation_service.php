<?php
declare(strict_types=1);

/**
 * Compares normalized gateway information with the internal payment record.
 * No automatic financial mutation is performed by reconciliation.
 */
function reconcile_payment(array $internal, array $gateway): array
{
    $internalStatus = strtolower((string)($internal['status'] ?? ''));
    $gatewayStatus = strtolower((string)($gateway['status'] ?? ''));
    $internalAmount = round((float)($internal['amount'] ?? 0), 2);
    $gatewayAmount = round((float)($gateway['amount'] ?? 0), 2);
    $internalTx = trim((string)($internal['transaction_id'] ?? ''));
    $gatewayTx = trim((string)($gateway['transaction_id'] ?? ''));

    $differences = [];
    if ($internalStatus !== $gatewayStatus) $differences[] = 'status';
    if ($internalAmount !== $gatewayAmount) $differences[] = 'amount';
    if ($internalTx !== '' && $gatewayTx !== '' && $internalTx !== $gatewayTx) $differences[] = 'transaction_id';

    return [
        'matched' => $differences === [],
        'differences' => $differences,
        'internal' => ['status' => $internalStatus, 'amount' => $internalAmount, 'transaction_id' => $internalTx],
        'gateway' => ['status' => $gatewayStatus, 'amount' => $gatewayAmount, 'transaction_id' => $gatewayTx],
    ];
}

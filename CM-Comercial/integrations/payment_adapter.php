<?php
declare(strict_types=1);

/**
 * Gateway-neutral contract for real payment providers.
 * Implement a provider adapter here without putting provider credentials in
 * the application source. Secrets belong in the server environment.
 */
interface PaymentGatewayAdapter
{
    /** @return array{payment_id:string,status:string,transaction_id:?string,raw:array} */
    public function createPayment(array $order, array $customer, array $paymentData): array;

    /** @return array{event_id:string,type:string,payment_id:string,status:string,transaction_id:?string,raw:array} */
    public function parseWebhook(string $rawBody, array $headers): array;

    /** @return array{status:string,transaction_id:?string,raw:array} */
    public function queryPayment(string $transactionId): array;
}

/**
 * Safe dispatcher: adapters are selected by configuration, never by client input.
 */
function payment_gateway_name(): string
{
    $name = getenv('PAYMENT_GATEWAY');
    return $name !== false && $name !== '' ? strtolower(trim($name)) : 'none';
}

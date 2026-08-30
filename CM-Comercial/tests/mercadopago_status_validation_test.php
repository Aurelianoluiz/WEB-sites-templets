<?php
declare(strict_types=1);

$source = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

foreach ([
    "'pending', 'in_process', 'in_mediation' => 'pending'",
    "'approved' => 'paid'",
    "'authorized' => 'authorized'",
    "'rejected' => 'failed'",
    "'cancelled', 'canceled' => 'cancelled'",
    "'refunded', 'charged_back' => 'refunded'",
    "Status de pagamento Mercado Pago não suportado",
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: missing status mapping/guard: $needle\n");
        exit(1);
    }
}

if (!str_contains($source, "default => throw new InvalidArgumentException")) {
    fwrite(STDERR, "FAIL: unsupported gateway statuses must fail closed\n");
    exit(1);
}

echo "PASS: Mercado Pago status validation\n";

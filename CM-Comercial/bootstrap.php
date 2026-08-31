<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Core\Container;
use App\Gateways\MercadoPagoGateway;
use App\Security\CsrfManager;
use App\Security\WebhookValidator;
use App\Services\PaymentService;

$container = new Container();
$container->singleton(Database::class, static fn (): Database => Database::getInstance());
$container->singleton(CsrfManager::class, static fn (): CsrfManager => new CsrfManager());
$container->singleton(WebhookValidator::class, static fn (): WebhookValidator => new WebhookValidator(
    (string)(getenv('MP_WEBHOOK_SECRET') ?: ''),
    (int)(getenv('MP_WEBHOOK_MAX_SKEW') ?: 300)
));
$container->singleton(MercadoPagoGateway::class, static fn (): MercadoPagoGateway => new MercadoPagoGateway());
$container->singleton(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
    $c->get(Database::class),
    $c->get(MercadoPagoGateway::class)
));

return $container;

<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Core\Container;
use App\Gateways\MercadoPagoGateway;
use App\Gateways\PaymentGatewayInterface;
use App\Repositories\OrderRepository;
use App\Repositories\OrderRepositoryInterface;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\PaymentTransactionRepositoryInterface;
use App\Repositories\ProductRepository;
use App\Repositories\ProductRepositoryInterface;
use App\Security\CsrfManager;
use App\Security\WebhookValidator;
use App\Services\AdminOrderService;
use App\Services\FinancialService;
use App\Services\OrderService;
use App\Services\PaymentService;

$container = new Container();
$container->singleton(Database::class, static fn (): Database => Database::getInstance());
$container->singleton(PDO::class, static fn (Container $c): PDO => $c->get(Database::class)->pdo());
$container->singleton(CsrfManager::class, static fn (): CsrfManager => new CsrfManager());
$container->singleton(WebhookValidator::class, static fn (): WebhookValidator => new WebhookValidator(
    (string)(getenv('MP_WEBHOOK_SECRET') ?: ''),
    (int)(getenv('MP_WEBHOOK_MAX_SKEW') ?: 300)
));
$container->singleton(MercadoPagoGateway::class, static fn (): MercadoPagoGateway => new MercadoPagoGateway());
$container->singleton(PaymentGatewayInterface::class, static fn (Container $c): PaymentGatewayInterface => $c->get(MercadoPagoGateway::class));

$container->singleton(OrderRepository::class, static fn (Container $c): OrderRepository => new OrderRepository($c->get(PDO::class)));
$container->singleton(OrderRepositoryInterface::class, static fn (Container $c): OrderRepositoryInterface => $c->get(OrderRepository::class));
$container->singleton(ProductRepository::class, static fn (Container $c): ProductRepository => new ProductRepository($c->get(PDO::class)));
$container->singleton(ProductRepositoryInterface::class, static fn (Container $c): ProductRepositoryInterface => $c->get(ProductRepository::class));
$container->singleton(PaymentTransactionRepository::class, static fn (Container $c): PaymentTransactionRepository => new PaymentTransactionRepository($c->get(PDO::class)));
$container->singleton(PaymentTransactionRepositoryInterface::class, static fn (Container $c): PaymentTransactionRepositoryInterface => $c->get(PaymentTransactionRepository::class));

$container->singleton(OrderService::class, static fn (Container $c): OrderService => new OrderService(
    $c->get(PDO::class),
    $c->get(OrderRepositoryInterface::class),
    $c->get(ProductRepositoryInterface::class)
));
$container->singleton(AdminOrderService::class, static fn (Container $c): AdminOrderService => new AdminOrderService(
    $c->get(PDO::class),
    $c->get(OrderRepositoryInterface::class),
    $c->get(ProductRepositoryInterface::class)
));
$container->singleton(FinancialService::class, static fn (Container $c): FinancialService => new FinancialService(
    $c->get(PDO::class),
    $c->get(PaymentTransactionRepositoryInterface::class)
));
$container->singleton(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
    $c->get(Database::class),
    $c->get(PaymentGatewayInterface::class)
));

return $container;

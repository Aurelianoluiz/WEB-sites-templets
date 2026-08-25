# Integrações externas

A CM Comercial usa adaptadores para manter gateways externos isolados do checkout.

- `PaymentGatewayInterface.php` define o contrato.
- `ManualPaymentGateway.php` mantém o modo demonstração/homologação.
- Um gateway real deve implementar o contrato e usar credenciais via ambiente.

O frete atual usa regras locais e possui ponto de integração em `api/shipping.php`.

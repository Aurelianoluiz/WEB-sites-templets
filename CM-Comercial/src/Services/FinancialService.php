<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentTransactionRepositoryInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Application service for customer financial history and administrative
 * reconciliation read models.
 *
 * The service contains no SQL. Persistence is delegated to the payment
 * repository. PDO is used only to provide an explicit ACID transaction
 * boundary for compound financial operations that require one.
 */
final class FinancialService
{
    private const ALLOWED_STATUSES = [
        'pending',
        'authorized',
        'paid',
        'failed',
        'cancelled',
        'refunded',
    ];

    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly PDO $db,
        private readonly PaymentTransactionRepositoryInterface $paymentRepository
    ) {
    }

    /**
     * Returns the customer's paginated financial history.
     *
     * @return list<array<string, mixed>>
     */
    public function getCustomerFinancialHistory(
        int $customerId,
        int $limit = 20,
        int $offset = 0
    ): array {
        $this->assertPositiveId($customerId, 'customerId');
        [$limit, $offset] = $this->normalizePagination($limit, $offset);

        try {
            return $this->paymentRepository->listWithFilters(
                ['customer_id' => $customerId],
                $limit,
                $offset
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to load customer financial history.',
                0,
                $e
            );
        }
    }

    /**
     * Returns aggregate financial values for the customer dashboard.
     *
     * @return array{count:int,total:float,paid:float,refunded:float,pending:float,failed:float,cancelled:float,authorized:float}
     */
    public function getCustomerFinancialSummary(int $customerId): array
    {
        $this->assertPositiveId($customerId, 'customerId');

        try {
            return $this->paymentRepository->summarize([
                'customer_id' => $customerId,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to calculate customer financial summary.',
                0,
                $e
            );
        }
    }

    /**
     * Returns a paginated administrative reconciliation dataset.
     *
     * Supported filters: status, provider, customer_id, order_id,
     * date_from, date_to and search.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listReconciliation(
        array $filters = [],
        int $limit = 50,
        int $offset = 0
    ): array {
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        $normalized = $this->normalizeFilters($filters);

        try {
            return $this->paymentRepository->listWithFilters(
                $normalized,
                $limit,
                $offset
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to load financial reconciliation records.',
                0,
                $e
            );
        }
    }

    /**
     * Returns aggregate values for an administrative reconciliation view.
     *
     * @param array<string, mixed> $filters
     * @return array{count:int,total:float,paid:float,refunded:float,pending:float,failed:float,cancelled:float,authorized:float}
     */
    public function getReconciliationSummary(array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);

        try {
            return $this->paymentRepository->summarize($normalized);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to calculate reconciliation summary.',
                0,
                $e
            );
        }
    }

    /**
     * Returns customer history and summary in one application-layer response.
     *
     * @return array{
     *     items:list<array<string,mixed>>,
     *     summary:array{count:int,total:float,paid:float,refunded:float,pending:float,failed:float,cancelled:float,authorized:float},
     *     limit:int,
     *     offset:int
     * }
     */
    public function getCustomerFinancialOverview(
        int $customerId,
        int $limit = 20,
        int $offset = 0
    ): array {
        $this->assertPositiveId($customerId, 'customerId');
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        $filters = ['customer_id' => $customerId];

        try {
            return [
                'items' => $this->paymentRepository->listWithFilters($filters, $limit, $offset),
                'summary' => $this->paymentRepository->summarize($filters),
                'limit' => $limit,
                'offset' => $offset,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to load customer financial overview.',
                0,
                $e
            );
        }
    }

    /**
     * Runs a caller-provided compound financial operation atomically.
     *
     * Nested transactions are rejected so callers cannot accidentally create
     * ambiguous commit/rollback boundaries.
     *
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException(
                'Financial transaction cannot start inside an existing PDO transaction.'
            );
        }

        $this->db->beginTransaction();

        try {
            $result = $operation($this->db);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, scalar|null>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (array_key_exists('status', $filters)) {
            $status = is_string($filters['status']) ? trim($filters['status']) : '';
            if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
                throw new InvalidArgumentException('Invalid financial status filter.');
            }
            if ($status !== '') {
                $normalized['status'] = $status;
            }
        }

        foreach (['customer_id', 'order_id'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }
            if (!is_int($filters[$key]) && !ctype_digit((string)$filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = (int)$filters[$key];
            if ($value < 1) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $normalized[$key] = $value;
        }

        foreach (['provider', 'search', 'date_from', 'date_to'] as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            if (!is_string($filters[$key])) {
                throw new InvalidArgumentException("Invalid {$key} filter.");
            }
            $value = trim($filters[$key]);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        if (isset($normalized['date_from']) && !$this->isValidDate((string)$normalized['date_from'])) {
            throw new InvalidArgumentException('Invalid date_from filter. Expected Y-m-d.');
        }
        if (isset($normalized['date_to']) && !$this->isValidDate((string)$normalized['date_to'])) {
            throw new InvalidArgumentException('Invalid date_to filter. Expected Y-m-d.');
        }
        if (isset($normalized['date_from'], $normalized['date_to'])
            && (string)$normalized['date_from'] > (string)$normalized['date_to']) {
            throw new InvalidArgumentException('date_from cannot be greater than date_to.');
        }

        if (isset($normalized['search'])) {
            $normalized['search'] = substr((string)$normalized['search'], 0, 120);
        }
        if (isset($normalized['provider'])) {
            $normalized['provider'] = substr((string)$normalized['provider'], 0, 40);
        }

        return $normalized;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function normalizePagination(int $limit, int $offset): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Pagination limit must be greater than zero.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Pagination offset cannot be negative.');
        }

        return [min($limit, self::MAX_PAGE_SIZE), $offset];
    }

    private function assertPositiveId(int $id, string $name): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}

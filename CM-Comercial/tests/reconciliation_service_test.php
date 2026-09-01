<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\ReconciliationRepositoryInterface;
use App\Services\ReconciliationService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ReconciliationServiceTestFailure extends RuntimeException {}
function assertTrue(bool $ok, string $message): void { if (!$ok) throw new ReconciliationServiceTestFailure($message); }
function assertSameValue(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new ReconciliationServiceTestFailure($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)); }
function assertThrows(callable $callback, string $message): void { try { $callback(); } catch (Throwable) { return; } throw new ReconciliationServiceTestFailure($message); }

final class FakeReconciliationRepository implements ReconciliationRepositoryInterface
{
    public int $listCalls = 0;
    public int $summaryCalls = 0;
    public int $countCalls = 0;
    public function __construct(private readonly array $rows) {}
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array { $this->listCalls++; return array_slice($this->filter($filters), max(0, $offset), max(1, min(100, $limit))); }
    public function summarize(array $filters = []): array { $this->summaryCalls++; $rows=$this->filter($filters); $out=['total'=>count($rows),'count'=>count($rows),'total_amount'=>0.0,'reconciled'=>0,'divergent'=>0,'pending'=>0,'inconsistent'=>0,'amount_mismatches'=>0,'status_mismatches'=>0,'orphan_transactions'=>0,'missing_transactions'=>0]; foreach($rows as $row){$out['total_amount']+=(float)($row['amount']??0);$state=(string)($row['reconciliation_status']??'inconsistent');if(isset($out[$state])&&is_int($out[$state]))$out[$state]++;$reason=(string)($row['divergence_reason']??'');if($reason==='amount_mismatch')$out['amount_mismatches']++;if($reason==='status_mismatch')$out['status_mismatches']++;if($reason==='orphan_transaction')$out['orphan_transactions']++;if($reason==='missing_payment_transaction')$out['missing_transactions']++;}return $out; }
    public function count(array $filters = []): int { $this->countCalls++; return count($this->filter($filters)); }
    private function filter(array $filters): array { return array_values(array_filter($this->rows,static function(array $row)use($filters):bool{foreach(['status','provider','customer_id','order_id'] as $key){if(isset($filters[$key])&&$filters[$key]!==''&&(string)($row[$key]??'')!==(string)$filters[$key])return false;}return true;})); }
}

$rows=[
 ['transaction_id'=>1,'order_id'=>1,'customer_id'=>1,'provider'=>'mercadopago','amount'=>100.0,'payment_status'=>'paid','reconciliation_status'=>'reconciled','divergence_reason'=>null],
 ['transaction_id'=>2,'order_id'=>2,'customer_id'=>1,'provider'=>'mercadopago','amount'=>125.0,'payment_status'=>'paid','reconciliation_status'=>'divergent','divergence_reason'=>'amount_mismatch'],
 ['transaction_id'=>3,'order_id'=>3,'customer_id'=>1,'provider'=>'mercadopago','amount'=>90.0,'payment_status'=>'paid','reconciliation_status'=>'divergent','divergence_reason'=>'status_mismatch'],
 ['transaction_id'=>4,'order_id'=>4,'customer_id'=>2,'provider'=>'mercadopago','amount'=>75.0,'payment_status'=>'pending','reconciliation_status'=>'pending','divergence_reason'=>null],
 ['transaction_id'=>5,'order_id'=>999,'customer_id'=>3,'provider'=>'mercadopago','amount'=>30.0,'payment_status'=>'paid','reconciliation_status'=>'inconsistent','divergence_reason'=>'orphan_transaction'],
 ['transaction_id'=>null,'order_id'=>6,'customer_id'=>4,'provider'=>null,'amount'=>50.0,'payment_status'=>null,'reconciliation_status'=>'inconsistent','divergence_reason'=>'missing_payment_transaction'],
];
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$repo=new FakeReconciliationRepository($rows);$service=new ReconciliationService($pdo,$repo);

$summary=$service->getSummary();assertSameValue(6,$summary['total'],'Summary total failed.');assertSameValue(1,$summary['reconciled'],'Reconciled count failed.');assertSameValue(2,$summary['divergent'],'Divergent count failed.');assertSameValue(1,$summary['pending'],'Pending count failed.');assertSameValue(2,$summary['inconsistent'],'Inconsistent count failed.');assertSameValue(1,$summary['orphan_transactions'],'Orphan count failed.');assertSameValue(1,$summary['missing_transactions'],'Missing transaction count failed.');
$page=$service->getPage([],2,0);assertSameValue(2,count($page['items']),'Page size failed.');assertSameValue(3,$page['total_pages'],'Total pages failed.');assertSameValue(100,$service->getPage([],500,0)['limit'],'Limit cap failed.');assertThrows(static fn():array=>$service->getPage([],0,0),'Zero limit must throw.');assertThrows(static fn():array=>$service->getPage([],50,-1),'Negative offset must throw.');assertThrows(static fn():array=>$service->getPage(['date_from'=>'2026-09-01','date_to'=>'2026-08-01']),'Reversed date range must throw.');
$beforeSummary=$repo->summaryCalls;$beforeList=$repo->listCalls;$first=$service->reconcile('idem-1',[],2,0);$second=$service->reconcile('idem-1',[],2,0);assertSameValue($first,$second,'Idempotent snapshots differ.');assertSameValue($beforeSummary+1,$repo->summaryCalls,'Summary repeated despite idempotency.');assertSameValue($beforeList+1,$repo->listCalls,'List repeated despite idempotency.');
$service->transaction(static function(PDO $db):void{$db->exec('CREATE TABLE probe (value TEXT NOT NULL)');$db->exec("INSERT INTO probe VALUES ('committed')");});assertSameValue(1,(int)$pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn(),'Commit failed.');assertThrows(static function()use($service):void{$service->transaction(static function(PDO $db):never{$db->exec("INSERT INTO probe VALUES ('rollback')");throw new RuntimeException('forced');});},'Rollback did not propagate.');assertSameValue(0,(int)$pdo->query("SELECT COUNT(*) FROM probe WHERE value='rollback'")->fetchColumn(),'Rollback failed.');
$source=(string)file_get_contents(__DIR__.'/../src/Services/ReconciliationService.php');assertTrue(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|JOIN|WHERE)?/i',$source),'Service contains SQL.');assertTrue(str_contains($source,'ReconciliationRepositoryInterface'),'Dedicated repository missing.');assertTrue(!str_contains($source,'PaymentTransactionRepositoryInterface'),'Old repository dependency remains.');

echo "PASS: reconciliation_service_test\n";

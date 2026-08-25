<?php
declare(strict_types=1);

const APP_NAME = 'CM Comercial';
const DB_PATH = __DIR__ . '/data/cm_comercial.sqlite';
const BASE_URL = '';
const SESSION_TIMEOUT = 7200;

// Ambiente: variáveis do servidor têm prioridade; .env local é aceito para desenvolvimento/implantação.
function load_local_env(): void {
    $file = __DIR__ . '/.env';
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}
load_local_env();
$APP_ENV = getenv('APP_ENV') ?: 'production';
$APP_DEBUG = ($APP_ENV !== 'production');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
security_headers();
if (!$APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, from_status TEXT DEFAULT '', to_status TEXT NOT NULL, actor_user_id INTEGER, note TEXT DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL)");
    return $pdo;
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function money(float|int $value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function is_logged_in(): bool { return isset($_SESSION['user']); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return is_logged_in() && (user()['role'] ?? '') === 'admin'; }
function require_login(): void { if (!is_logged_in()) redirect('login.php'); }
function require_admin(): void { if (!is_admin()) { http_response_code(403); include __DIR__ . '/403.php'; exit; } }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Token de segurança inválido. Recarregue a página e tente novamente.'); } }
function shipping_methods(): array { return db()->query('SELECT * FROM shipping_methods WHERE active=1 ORDER BY id')->fetchAll(); }
function shipping_method_active(string $code): bool { $s = db()->prepare('SELECT active FROM shipping_methods WHERE code=? LIMIT 1'); $s->execute([$code]); return (bool)$s->fetchColumn(); }
function calculate_shipping(string $method, string $zip, string $state, float $subtotal): array {
    if ($method === 'pickup') return ['fee'=>0.0,'label'=>'Retirar na loja','eta'=>0];
    $zip = preg_replace('/\D+/', '', $zip) ?? ''; $state = strtoupper(trim($state));
    $rules = db()->query("SELECT * FROM shipping_rules WHERE active=1 AND method_code='delivery' ORDER BY priority DESC, LENGTH(zip_prefix) DESC, id DESC")->fetchAll();
    foreach ($rules as $r) {
        $zipMatch = $r['zip_prefix']==='' || str_starts_with($zip,(string)$r['zip_prefix']);
        $stateMatch = $r['state']==='' || strtoupper((string)$r['state'])===$state;
        if ($zipMatch && $stateMatch) { $fee=(float)$r['fee']; if((float)$r['free_above']>0 && $subtotal >= (float)$r['free_above']) $fee=0.0; return ['fee'=>$fee,'label'=>'Entrega em '.$state,'eta'=>(int)$r['eta_days']]; }
    }
    return ['fee'=>($subtotal>=999?0.0:29.90),'label'=>'Entrega','eta'=>5];
}
function slugify(string $value): string { $value=trim($value); $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value; $value=strtolower($value); $value=preg_replace('/[^a-z0-9]+/','-',$value)??''; return trim($value,'-')?:'produto'; }
function product_image(array $product): string { return trim((string)($product['image'] ?? '')) ?: 'assets/product-placeholder.svg'; }
function record_order_status_change(int $orderId, string $from, string $to, ?int $actorUserId = null, string $note = ''): void {
    if ($from === $to) return;
    $st = db()->prepare('INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id,note) VALUES(?,?,?,?,?)');
    $st->execute([$orderId, $from, $to, $actorUserId, $note]);
}
function valid_order_transition(string $from, string $to): bool {
    if ($from === $to) return true;
    $map = [
        'pending' => ['confirmed','cancelled'],
        'confirmed' => ['preparing','cancelled'],
        'preparing' => ['shipped','cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

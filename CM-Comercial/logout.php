<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'] ?? '',
            (bool)$params['secure'], (bool)$params['httponly']
        );
    }
    session_destroy();
    redirect('index.php');
}

$title = 'Sair — ' . APP_NAME;
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><div><span class="eyebrow">CONTA</span><h1>Sair da conta</h1><p>Confirme para encerrar sua sessão neste dispositivo.</p></div></div>
<div class="panel" style="max-width:560px">
    <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <button class="btn danger" type="submit">Sair da conta</button>
        <a class="btn" href="account.php">Voltar</a>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php';

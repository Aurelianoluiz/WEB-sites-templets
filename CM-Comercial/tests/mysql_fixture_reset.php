<?php
declare(strict_types=1);
$dsn=(string)(getenv('CM_MYSQL_DSN')?:sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('CM_MYSQL_HOST'),(int)(getenv('CM_MYSQL_PORT')?:3306),getenv('CM_MYSQL_DATABASE')));
$pdo=new PDO($dsn,(string)getenv('CM_MYSQL_USER'),(string)(getenv('CM_MYSQL_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['payment_audit_log','order_status_history','stock_movements'] as $table)$pdo->exec('DELETE FROM '.$table);
$pdo->exec("UPDATE payment_transactions SET provider_payment_id=NULL,status='pending',amount=CASE id WHEN 1 THEN 100.00 WHEN 2 THEN 200.00 WHEN 3 THEN 300.00 ELSE amount END WHERE id IN (1,2,3)");
$pdo->exec("UPDATE orders SET status='pending',payment_status='pending',total_amount=CASE id WHEN 1 THEN 100.00 WHEN 2 THEN 200.00 WHEN 3 THEN 300.00 ELSE total_amount END WHERE id IN (1,2,3)");
$pdo->exec("UPDATE products SET stock_quantity=CASE id WHEN 1 THEN 1 WHEN 2 THEN 10 WHEN 3 THEN 10 ELSE stock_quantity END WHERE id IN (1,2,3)");
$pdo->exec("SET GLOBAL innodb_lock_wait_timeout=50");
echo "PASS: mysql fixture reset\n";

<?php
require_once 'config.php';
require_login();
$id=(int)($_GET['id']??0);
$pdo=db();
$stmt=$pdo->prepare('SELECT o.*,u.name user_name,u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? AND o.user_id=? LIMIT 1');
$stmt->execute([$id,user()['id']]); $order=$stmt->fetch();
if(!$order){http_response_code(404);$title='Pedido não encontrado — '.APP_NAME;include 'includes/header.php';echo '<div class="empty"><h1>Pedido não encontrado.</h1><a class="btn primary" href="account.php">Voltar para minha conta</a></div>';include 'includes/footer.php';exit;}
$message=''; $error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 if(($_POST['action']??'')==='cancel'){
  if(!in_array($order['status'],['pending','confirmed'],true)) $error='Este pedido não pode mais ser cancelado pelo cliente.';
  elseif(($order['payment_status']??'pending')==='paid') $error='O pagamento já foi confirmado. Solicite o cancelamento ao atendimento.';
  else { try{
   $pdo->beginTransaction();
   $lock=$pdo->prepare('SELECT status,payment_status FROM orders WHERE id=? AND user_id=? LIMIT 1'); $lock->execute([$id,user()['id']]); $current=$lock->fetch();
   if(!$current || !in_array($current['status'],['pending','confirmed'],true) || $current['payment_status']==='paid') throw new RuntimeException('Pedido não pode ser cancelado.');
   $itemsStmt=$pdo->prepare('SELECT product_id,qty FROM order_items WHERE order_id=?'); $itemsStmt->execute([$id]);
   $updateStock=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?'); $movement=$pdo->prepare("INSERT INTO stock_movements(product_id,type,qty,reason,user_id) VALUES(?,?,?,?,?)");
   foreach($itemsStmt->fetchAll() as $item){$qty=(int)$item['qty'];if($qty<1)continue;$updateStock->execute([$qty,(int)$item['product_id']]);$movement->execute([(int)$item['product_id'],'in',$qty,'Estorno por cancelamento do pedido #'.$id,(int)user()['id']]);}
   $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND user_id=?")->execute([$id,user()['id']]);
   $pdo->commit();$order['status']='cancelled';$message='Pedido cancelado e estoque estornado com sucesso.';
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Não foi possível cancelar o pedido. Tente novamente.';}}
 }
}
$items=db()->prepare('SELECT oi.*,p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');$items->execute([$id]);$items=$items->fetchAll();
$title='Pedido #'.$id.' — '.APP_NAME;include 'includes/header.php';
$statusLabels=['pending'=>'Aguardando confirmação','confirmed'=>'Confirmado','preparing'=>'Em preparação','shipped'=>'Enviado','delivered'=>'Entregue','cancelled'=>'Cancelado'];
?>
<div class="page-head"><div><span class="eyebrow">MEUS PEDIDOS</span><h1>Pedido #<?=$id?></h1><p>Realizado em <?=e(date('d/m/Y H:i',strtotime($order['created_at'])))?></p></div><a class="btn" href="account.php">← Minha conta</a></div>
<?php if($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?><?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="order-detail-grid"><section class="panel"><div class="order-status"><span class="status-pill <?=e($order['status'])?>"><?=e($statusLabels[$order['status']]??$order['status'])?></span><strong><?=money($order['total'])?></strong></div><h2>Itens</h2><div class="order-items"><?php foreach($items as $item): ?><div class="order-item"><div><strong><?=e($item['name'])?></strong><small><?=e((string)$item['qty'])?> × <?=money($item['price'])?></small></div><b><?=money($item['qty']*$item['price'])?></b></div><?php endforeach; ?></div>
<?php if(in_array($order['status'],['pending','confirmed'],true) && ($order['payment_status']??'pending')!=='paid'): ?><div class="order-cancel-box"><strong>Precisa desistir da compra?</strong><p>Você pode cancelar enquanto o pedido ainda não estiver em preparação e o pagamento não tiver sido confirmado.</p><form method="post" onsubmit="return confirm('Tem certeza que deseja cancelar este pedido?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel"><button class="btn danger" type="submit">Cancelar pedido</button></form></div><?php endif; ?></section>
<aside class="panel"><h2>Pagamento</h2><div class="detail-list"><div><span>Método</span><strong><?=($order['payment_method']==='pix'?'Pix':'Cartão')?></strong></div><div><span>Status</span><strong><?=e($order['payment_status']??'pending')?></strong></div></div><h2>Recebimento</h2><div class="detail-list"><div><span>Modalidade</span><strong><?=($order['shipping_method']==='delivery'?'Entrega':'Retirada na loja')?></strong></div><div><span>Cliente</span><strong><?=e($order['customer_name'])?></strong></div><?php if($order['phone']): ?><div><span>Telefone</span><strong><?=e($order['phone'])?></strong></div><?php endif; ?><?php if(!empty($order['shipping_label'])): ?><div><span>Frete</span><strong><?=e($order['shipping_label'])?><?php if((int)($order['shipping_eta']??0)>0): ?> · até <?=e((string)$order['shipping_eta'])?> dias úteis<?php endif; ?></strong></div><?php endif; ?><?php if($order['shipping_method']==='delivery'): ?><div><span>Endereço</span><strong><?=e($order['address'].', '.$order['number'])?></strong></div><div><span>Local</span><strong><?=e($order['neighborhood'].' — '.$order['city'].'/'.$order['state'])?></strong></div><?php if($order['complement']): ?><div><span>Complemento</span><strong><?=e($order['complement'])?></strong></div><?php endif; ?><div><span>CEP</span><strong><?=e($order['zip'])?></strong></div><?php endif; ?></div></aside></div>
<?php include 'includes/footer.php';

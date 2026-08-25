<?php
$admin_layout=true; require_once '../config.php'; require_admin();
$statusLabels=['pending'=>'Aguardando confirmação','confirmed'=>'Confirmado','preparing'=>'Em preparação','shipped'=>'Enviado','delivered'=>'Entregue','cancelled'=>'Cancelado'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $action=$_POST['action']??''; $id=(int)($_POST['id']??0);
 if($action==='status' && isset($statusLabels[$_POST['status']??''])){
  $status=$_POST['status']; $pdo=db();
  $st=$pdo->prepare('SELECT status,payment_status FROM orders WHERE id=? LIMIT 1'); $st->execute([$id]); $current=$st->fetch();
  if(!$current){ redirect('orders.php?error=notfound'); }
  $rank=['pending'=>0,'confirmed'=>1,'preparing'=>2,'shipped'=>3,'delivered'=>4,'cancelled'=>99];
  $from=$current['status'];
  $valid=($status==='cancelled') ? !in_array($from,['delivered','cancelled'],true) : ($from!=='cancelled' && isset($rank[$status],$rank[$from]) && $rank[$status] >= $rank[$from]);
  if(!$valid){ redirect('orders.php?error=transition'); }
  try{
    $pdo->beginTransaction();
    if($status==='cancelled'){
      if(($current['payment_status']??'pending')==='paid') throw new RuntimeException('paid');
      $items=$pdo->prepare('SELECT product_id,qty FROM order_items WHERE order_id=?'); $items->execute([$id]);
      $add=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
      $move=$pdo->prepare("INSERT INTO stock_movements(product_id,type,qty,reason,user_id) VALUES(?,?,?,?,?)");
      foreach($items->fetchAll() as $item){ $qty=(int)$item['qty']; if($qty<1) continue; $add->execute([$qty,(int)$item['product_id']]); $move->execute([(int)$item['product_id'],'in',$qty,'Estorno administrativo do pedido #'.$id,(int)user()['id']]); }
    }
    $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$id]);
    $pdo->commit(); redirect('orders.php?saved=1');
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); redirect('orders.php?error=save'); }
 }
}
$filter=$_GET['status']??''; $sql='SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id'; $args=[]; if(isset($statusLabels[$filter])){$sql.=' WHERE o.status=?';$args[]=$filter;} $sql.=' ORDER BY o.id DESC'; $st=db()->prepare($sql);$st->execute($args);$orders=$st->fetchAll();
$title='Pedidos — Administração'; include '../includes/header.php';
?>
<div class="admin-head"><div><span class="eyebrow">VENDAS</span><h1>Pedidos</h1><p>Acompanhe, atualize e consulte os pedidos dos clientes.</p></div><a href="index.php" class="btn">← Painel</a></div>
<?php if(isset($_GET['saved'])): ?><div class="alert success">Status atualizado com sucesso.</div><?php elseif(isset($_GET['error']) && $_GET['error']==='transition'): ?><div class="alert error">Transição inválida. O fluxo do pedido não pode retroceder e pedidos entregues não podem ser cancelados.</div><?php elseif(isset($_GET['error']) && $_GET['error']==='save'): ?><div class="alert error">Não foi possível atualizar o pedido. Nenhuma alteração foi aplicada.</div><?php elseif(isset($_GET['error']) && $_GET['error']==='notfound'): ?><div class="alert error">Pedido não encontrado.</div><?php endif; ?>
<div class="order-filters"><a class="btn <?=($filter===''?'primary':'')?>" href="orders.php">Todos</a><?php foreach($statusLabels as $key=>$label): ?><a class="btn <?=($filter===$key?'primary':'')?>" href="orders.php?status=<?=$key?>"><?=e($label)?></a><?php endforeach; ?></div>
<div class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Recebimento</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody><?php if(!$orders): ?><tr><td colspan="7">Nenhum pedido encontrado.</td></tr><?php else: foreach($orders as $o): ?><tr><td><strong>#<?=$o['id']?></strong></td><td><strong><?=e($o['customer_name'])?></strong><br><small><?=e($o['email'])?></small></td><td><?=e(date('d/m/Y H:i',strtotime($o['created_at'])))?></td><td><?=($o['shipping_method']==='delivery'?'Entrega':'Retirada')?></td><td><?=money($o['total'])?></td><td><form method="post" class="status-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$o['id']?>"><select name="status" onchange="this.form.submit()"><?php foreach($statusLabels as $key=>$label): ?><option value="<?=$key?>" <?=($o['status']===$key?'selected':'')?>><?=e($label)?></option><?php endforeach; ?></select></form></td><td><a href="order.php?id=<?=$o['id']?>">Ver detalhes</a></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
<?php include '../includes/footer.php'; ?>

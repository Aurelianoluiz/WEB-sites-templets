<?php
declare(strict_types=1);

$source=(string)file_get_contents(__DIR__.'/../webhooks/webhook_handler.php');
$checks=[
 'uses_provider_event_fields'=>str_contains($source,"payload['type']")&&str_contains($source,"payload['action']")&&str_contains($source,"payload['data']['id']"),
 'uses_notification_identity'=>str_contains($source,"$notificationId")&&str_contains($source,"'mp:webhook:'"),
 'fallback_hashes_stable_identity'=>str_contains($source,"hash('sha256', implode('|', [$eventType, $action, $dataId]))"),
 'request_id_not_business_identity'=>str_contains($source,'x-request-id')&&!str_contains($source,"$requestId])"),
 'timestamp_not_business_identity'=>!str_contains($source,'timestampPart'),
];
$failed=array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok));foreach($checks as $name=>$ok)echo($ok?'PASS':'FAIL').': '.$name.PHP_EOL;exit($failed?1:0);

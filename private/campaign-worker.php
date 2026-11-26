<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require __DIR__.'/config.php';
if(empty($c['public_url'])||!filter_var($c['public_url'],FILTER_VALIDATE_URL)||!str_starts_with($c['public_url'],'https://')||empty($c['mail_from'])||!filter_var($c['mail_from'],FILTER_VALIDATE_EMAIL)||empty($c['mail_address']))exit("Configure public_url, mail_from and mail_address first.\n");
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!$db->query("SELECT GET_LOCK('onb_campaign_worker',0)")->fetchColumn())exit;
try{foreach($db->query("SELECT e.*,u.email,COALESCE(n.subscribed,0) AS subscribed FROM onb_email_queue e JOIN users u ON u.id=e.user_id LEFT JOIN onb_newsletter n ON n.user_id=e.user_id WHERE e.status='pending' AND e.kind='marketing' ORDER BY e.id LIMIT 50")->fetchAll() as $r){
$s=$db->prepare("SELECT subscribed FROM onb_newsletter WHERE user_id=?");$s->execute([$r['user_id']]);$status='skipped';
if($s->fetchColumn()&&filter_var($r['email'],FILTER_VALIDATE_EMAIL)&&!preg_match('/[\r\n]/',$r['subject'])){
$body=$r['body']."\n\nOur Next Boarding\n".$c['mail_address']."\nManage or unsubscribe from offers: ".$c['public_url']."?page=travelhub";
$db->prepare("UPDATE onb_email_queue SET status='sending' WHERE id=?")->execute([$r['id']]);
$status=mail($r['email'],$r['subject'],$body,'From: '.$c['mail_from'])?'submitted':'failed';
}$db->prepare("UPDATE onb_email_queue SET status=? WHERE id=?")->execute([$status,$r['id']]);}
}finally{$db->query("SELECT RELEASE_LOCK('onb_campaign_worker')");}

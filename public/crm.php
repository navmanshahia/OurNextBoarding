<?php
declare(strict_types=1);
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
$c=require __DIR__.'/../private/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_once __DIR__.'/../private/security.php';
securityGuard($db);
$s=$db->prepare('SELECT role FROM users WHERE id=?');$s->execute([$_SESSION['uid']??0]);if($s->fetchColumn()!=='admin'){http_response_code(403);exit('Access denied');}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$db->exec('CREATE TABLE IF NOT EXISTS onb_crm (lead_id INT PRIMARY KEY, stage VARCHAR(30) NOT NULL DEFAULT "new", followup DATE NULL, notes TEXT NOT NULL)');
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$stages=['new','contacted','qualified','quote sent','won','lost'];
if($_SERVER['REQUEST_METHOD']==='POST'){
if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit;}
$id=(int)($_POST['id']??0);$stage=$_POST['stage']??'';$date=$_POST['followup']??'';$notes=substr((string)($_POST['notes']??''),0,10000);
if(!in_array($stage,$stages,true)||($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))){http_response_code(400);exit('Invalid input');}
$s=$db->prepare('INSERT INTO onb_crm (lead_id,stage,followup,notes) SELECT id,?,?,? FROM enquiries WHERE id=? ON DUPLICATE KEY UPDATE stage=VALUES(stage),followup=VALUES(followup),notes=VALUES(notes)');$s->execute([$stage,$date?:null,$notes,$id]);header('Location: crm.php');exit;
}
$rows=$db->query('SELECT e.*,c.stage,c.followup,c.notes FROM enquiries e LEFT JOIN onb_crm c ON c.lead_id=e.id ORDER BY c.followup IS NULL,c.followup,e.id DESC')->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer CRM | Our Next Boarding</title><link rel="stylesheet" href="style.css"><link rel="stylesheet" href="boarding-ui.css?v=3"></head><body class="workspace"><header><a href="index.php">OUR NEXT BOARDING</a></header><aside class="side"><small>Management workspace</small><a href="index.php?page=portal">Dashboard</a><a href="index.php?page=admin">Trip management</a><a href="operations.php">Business overview</a><a href="payments.php">Payments & invoices</a><a href="crm.php">Customer CRM</a><a href="index.php?page=agents">Agent management</a><a href="index.php?page=analytics">Business analytics</a><a href="index.php?page=concierge">Concierge inbox</a><a href="index.php">View website</a></aside><main><a href="index.php?page=admin">← Management console</a><h1>Customer CRM</h1><p>Manage your sales pipeline and follow-up dates.</p><div class="metrics"><?php foreach($stages as $stage):?><article><span><?=e(ucfirst($stage))?></span><strong><?=count(array_filter($rows,fn($r)=>($r['stage']??'new')===$stage))?></strong></article><?php endforeach;?></div><?php foreach($rows as $r):?><article><h2><?=e($r['name'])?></h2><p><?=e($r['email'])?></p><p><?=e($r['message'])?></p><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><label>Pipeline stage<select name="stage"><?php foreach($stages as $stage):?><option <?=($r['stage']??'new')===$stage?'selected':''?>><?=e($stage)?></option><?php endforeach;?></select></label><label>Next follow-up<input type="date" name="followup" value="<?=e($r['followup']??'')?>"></label><label>Private sales notes<textarea name="notes"><?=e($r['notes']??'')?></textarea></label><button>Save customer</button></form></article><?php endforeach;?><?php if(!$rows):?><p>No enquiries yet.</p><?php endif;?></main></body></html>
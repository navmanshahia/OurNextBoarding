<?php
declare(strict_types=1);
ini_set('display_errors','0');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']);session_start();
header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
$c=require __DIR__.'/../private/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function q($s,$a=[]){global $db;$p=$db->prepare($s);$p->execute($a);return $p;}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$uid=(int)($_SESSION['uid']??0);
$u=q('SELECT * FROM users WHERE id=?',[$uid])->fetch();
if(!$u){header('Location: index.php?page=login');exit;}
$admin=$u['role']==='admin';
require __DIR__.'/../private/upgrade.php';
if($admin)upgrade($db);
$_SESSION['csrf']??=bin2hex(random_bytes(32));
function form($action){echo '<form method="post"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">';}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
try{
if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??''))throw new Exception('Refresh and retry.');
$a=$_POST['action']??'';$id=(int)($_POST['id']??0);
if($a==='pack'){ $item=trim($_POST['item']??'');if(!$item||strlen($item)>255)throw new Exception('Invalid item');q('INSERT INTO packing(user_id,item) VALUES(?,?)',[$uid,$item]);}
elseif($a==='toggle')q('UPDATE packing SET done=NOT done WHERE id=? AND user_id=?',[$id,$uid]);
elseif($admin){
if($a==='expense'){ $amount=filter_var($_POST['amount']??'',FILTER_VALIDATE_FLOAT);$category=trim($_POST['category']??'');if($amount===false||$amount<0||!$category)throw new Exception('Invalid expense');q('INSERT INTO expenses(trip_id,category,amount,per_person) VALUES(?,?,?,?)',[$id,$category,$amount,isset($_POST['per_person'])?1:0]);}
elseif($a==='remove')q('DELETE FROM expenses WHERE id=?',[$id]);
elseif($a==='announce'){if(!trim($_POST['body']??''))throw new Exception('Message required');q('INSERT INTO announcements(trip_id,body) VALUES(?,?)',[$id,$_POST['body']]);}
else throw new Exception('Unknown action');
q('INSERT INTO audit_log(user_id,event) VALUES(?,?)',[$uid,$a.' #'.$id]);
}else throw new Exception('Access denied');
header('Location: operations.php');exit;
}catch(Throwable $e){$error='Unable to save. Check input and try again.';}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Boarding operations</title><link rel="stylesheet" href="style.css?v=20261126c"><script defer src="app.js?v=20261126c"></script></head><body class="workspace"><header><a href="index.php">OUR NEXT BOARDING</a><nav><a href="index.php?page=portal">My trips</a><?php if($admin):?><a href="index.php?page=admin">Manage trips</a><?php endif;?></nav></header><aside class="side"><small>Our Next Boarding / Workspace</small><?php if($admin):?><a href="index.php?page=admin">Trip management</a><a href="operations.php">Business overview</a><a href="payments.php">Payments & invoices</a><?php else:?><a href="index.php?page=portal">My journeys</a><a href="operations.php">Travel companion</a><a href="payments.php">My payments</a><?php endif;?><a href="index.php?page=home">View website</a></aside><main><h1><?=$admin?'Operations':'Travel companion'?></h1><p><?=h($error)?></p>
<?php if($admin):?><div class="metrics"><?php foreach(['Enquiries'=>'SELECT COUNT(*) FROM enquiries','Travellers'=>'SELECT COUNT(*) FROM travellers','Published trips'=>'SELECT COUNT(*) FROM trips WHERE published=1'] as $label=>$sql):?><article><h3><?=h($label)?></h3><h2><?=h(q($sql)->fetchColumn())?></h2></article><?php endforeach;?></div>
<?php foreach(q('SELECT * FROM trips') as $t): $id=$t['id'];$n=max(1,(int)$t['seats']);$cost=0;?><article><h2><?=h($t['title'])?></h2><p>Planning scenario: <?=$n?> paying travellers + one leader. Not confirmed bookings.</p><?php foreach(q('SELECT * FROM expenses WHERE trip_id=?',[$id]) as $e):$cost+=(float)$e['amount']*($e['per_person']?$n+1:1);?><p><?=h($e['category'])?> · CAD <?=h($e['amount'])?> <?=$e['per_person']?'per person':'fixed'?></p><?php form('remove');?><input type="hidden" name="id" value="<?=$e['id']?>"><button>Remove expense</button></form><?php endforeach;?>
<p>Itemized cost: CAD <?=number_format($cost,2)?> · Planned revenue: CAD <?=number_format($n*$t['price'],2)?> · Estimated surplus: CAD <?=number_format($n*$t['price']-$cost,2)?></p><p>Only entered expenses are included. Taxes and contingencies must be entered separately.</p>
<?php form('expense');?><input type="hidden" name="id" value="<?=$id?>"><label>Expense category<input name="category" required></label><label>CAD amount<input name="amount" type="number" min="0" step=".01" required></label><label><input type="checkbox" name="per_person">Per person including leader</label><button>Add expense</button></form>
<?php form('announce');?><input type="hidden" name="id" value="<?=$id?>"><label>Trip announcement<textarea name="body" required></textarea></label><button>Publish to assigned travellers</button></form></article><?php endforeach;?>
<h2>Recent activity</h2><?php foreach(q('SELECT * FROM audit_log ORDER BY id DESC LIMIT 50') as $a):?><p><?=h($a['created_at'].' · '.$a['event'])?></p><?php endforeach;?>
<?php else:?><h2>Announcements</h2><?php foreach(q('SELECT a.body,a.created_at,t.title FROM announcements a JOIN travellers v ON v.trip_id=a.trip_id JOIN trips t ON t.id=a.trip_id WHERE v.user_id=? ORDER BY a.id DESC',[$uid]) as $a):?><article><h3><?=h($a['title'])?></h3><p><?=h($a['body'])?></p></article><?php endforeach;?>
<h2>Packing checklist</h2><?php foreach(q('SELECT * FROM packing WHERE user_id=?',[$uid]) as $i):form('toggle');?><input type="hidden" name="id" value="<?=$i['id']?>"><p><?=$i['done']?'Packed: ':'To pack: '?><?=h($i['item'])?></p><button>Toggle status</button></form><?php endforeach;form('pack');?><label>New item<input name="item" required maxlength="255"></label><button>Add item</button></form><?php endif;?></main></body></html>
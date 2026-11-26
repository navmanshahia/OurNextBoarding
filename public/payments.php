<?php
declare(strict_types=1);
ini_set('display_errors','0');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']);session_start();
header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
$c=require __DIR__.'/../private/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function q($s,$a=[]){global $db;$p=$db->prepare($s);$p->execute($a);return $p;}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($n){return 'CAD $'.number_format($n/100,2);}
function cents($s){if(!preg_match('/^\d{1,7}(\.\d{1,2})?$/D',(string)$s))throw new Exception('Invalid amount');return (int)round((float)$s*100);}
$uid=(int)($_SESSION['uid']??0);
$u=q('SELECT * FROM users WHERE id=?',[$uid])->fetch();
if(!$u){header('Location: index.php?page=login');exit;}
$admin=$u['role']==='admin';
if($admin){
$db->exec("CREATE TABLE IF NOT EXISTS pay_settings(id INT PRIMARY KEY,email VARCHAR(254) NOT NULL DEFAULT '',enabled TINYINT NOT NULL DEFAULT 0)");
$db->exec("INSERT IGNORE INTO pay_settings(id) VALUES(1)");
$db->exec("CREATE TABLE IF NOT EXISTS pay_invoices(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,trip_id INT NOT NULL,ref VARCHAR(40) UNIQUE NOT NULL,total INT NOT NULL,due_date DATE NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS pay_entries(id INT AUTO_INCREMENT PRIMARY KEY,invoice_id INT NOT NULL,amount INT NOT NULL,bank_ref VARCHAR(190) NOT NULL,status ENUM('pending','verified','rejected') DEFAULT 'pending',verified_by INT NULL,verified_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(bank_ref),FOREIGN KEY(invoice_id) REFERENCES pay_invoices(id))");
}
try{$settings=q('SELECT * FROM pay_settings WHERE id=1')->fetch();}catch(Throwable $e){exit('Payments are not configured yet.');}
$_SESSION['pay_csrf']??=bin2hex(random_bytes(32));
function form($a){echo '<form method="post"><input type="hidden" name="csrf" value="'.h($_SESSION['pay_csrf']).'"><input type="hidden" name="action" value="'.h($a).'">';}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
try{
if(!hash_equals($_SESSION['pay_csrf'],(string)($_POST['csrf']??'')))throw new Exception('Invalid session');
$a=$_POST['action']??'';$id=(int)($_POST['id']??0);
if($admin&&$a==='settings'){
$email=trim($_POST['email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new Exception('Invalid email');
q('UPDATE pay_settings SET email=?,enabled=? WHERE id=1',[$email,isset($_POST['enabled'])&&isset($_POST['compliance'])?1:0]);
}elseif($admin&&$a==='invoice'){
$person=(int)($_POST['person']??0);$trip=(int)($_POST['trip']??0);$total=cents($_POST['amount']??'');$due=$_POST['due']??'';
if(!$total||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$due)||!q('SELECT 1 FROM travellers WHERE user_id=? AND trip_id=?',[$person,$trip])->fetchColumn())throw new Exception('Invalid invoice');
q('INSERT INTO pay_invoices(user_id,trip_id,ref,total,due_date) VALUES(?,?,?,?,?)',[$person,$trip,'ONB-'.strtoupper(bin2hex(random_bytes(8))),$total,$due]);
}elseif($a==='submit'&&!$admin&&$settings['enabled']){
$db->beginTransaction();
$i=q('SELECT * FROM pay_invoices WHERE id=? AND user_id=? FOR UPDATE',[$id,$uid])->fetch();
$amount=cents($_POST['amount']??'');$ref=trim($_POST['bank_ref']??'');
if(!$i||!$amount||strlen($ref)<4||strlen($ref)>190)throw new Exception('Invalid submission');
$used=(int)q("SELECT COALESCE(SUM(amount),0) FROM pay_entries WHERE invoice_id=? AND status IN ('pending','verified')",[$id])->fetchColumn();
if($amount>$i['total']-$used)throw new Exception('Amount exceeds remaining balance');
q('INSERT INTO pay_entries(invoice_id,amount,bank_ref) VALUES(?,?,?)',[$id,$amount,$ref]);$db->commit();
}elseif($admin&&in_array($a,['verify','reject'],true)){
if($a==='verify'&&!isset($_POST['bank_checked']))throw new Exception('Bank verification required');
q("UPDATE pay_entries SET status=?,verified_by=?,verified_at=NOW() WHERE id=? AND status='pending'",[$a==='verify'?'verified':'rejected',$uid,$id]);
}else throw new Exception('Action unavailable');
header('Location: payments.php');exit;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error='Unable to save. Check details, remaining balance and duplicate references.';}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Our Next Boarding | Payments</title><link rel="stylesheet" href="style.css?v=20261126c"><script defer src="app.js?v=20261126c"></script><link rel="stylesheet" href="boarding-ui.css?v=3"></head><body class="workspace"><header><a href="index.php">OUR NEXT BOARDING</a><nav><a href="index.php?page=portal">Traveller portal</a></nav></header><aside class="side"><small>Our Next Boarding / Workspace</small><?php if($admin):?><a href="index.php?page=portal">Dashboard</a><a href="index.php?page=admin">Trip management</a><a href="operations.php">Business overview</a><a href="payments.php">Payments & invoices</a><a href="crm.php">Customer CRM</a><a href="index.php?page=agents">Agent management</a><a href="index.php?page=analytics">Business analytics</a><a href="index.php?page=concierge">Concierge inbox</a><?php else:?><a href="index.php?page=portal">My journeys</a><a href="operations.php">Travel companion</a><a href="payments.php">My payments</a><?php endif;?><a href="index.php?page=home">View website</a></aside><main><a href="index.php?page=portal">Traveller portal</a> · <a href="operations.php">Operations</a><h1>Interac e-Transfer</h1><p role="alert"><?=h($error)?></p>
<?php if($admin):?><h2>Payment settings</h2><?php form('settings');?><label>Autodeposit recipient email<input type="email" name="email" value="<?=h($settings['email'])?>" required></label><label><input type="checkbox" name="enabled" <?=$settings['enabled']?'checked':''?>>Enable payment instructions</label><label><input type="checkbox" name="compliance">I confirm applicable licensing and customer-fund requirements are satisfied</label><button>Save settings</button></form>
<h2>Create invoice</h2><?php form('invoice');?><label>Assigned traveller<select name="assignment" id="assignment" disabled><option>Enter IDs below</option></select></label><label>Traveller ID<input type="number" name="person" required></label><label>Trip ID<input type="number" name="trip" required></label><label>Amount CAD<input type="number" step=".01" min=".01" name="amount" required></label><label>Due date<input type="date" name="due" required></label><button>Create invoice</button></form>
<p>Assigned traveller IDs:</p><?php foreach(q('SELECT v.user_id,v.trip_id,u.email,t.title FROM travellers v JOIN users u ON u.id=v.user_id JOIN trips t ON t.id=v.trip_id') as $v):?><p><?=h($v['email'].' — '.$v['title'].' — traveller '.$v['user_id'].' / trip '.$v['trip_id'])?></p><?php endforeach;endif;?>
<h2>Invoices</h2><?php foreach(q('SELECT i.*,t.title FROM pay_invoices i JOIN trips t ON t.id=i.trip_id'.($admin?'':' WHERE i.user_id=?').' ORDER BY i.id DESC',$admin?[]:[$uid]) as $i):
$paid=(int)q("SELECT COALESCE(SUM(amount),0) FROM pay_entries WHERE invoice_id=? AND status='verified'",[$i['id']])->fetchColumn();?><article><h3><?=h($i['title'].' · '.$i['ref'])?></h3><progress class="progress" max="<?=max(1,(int)$i['total'])?>" value="<?=$paid?>"></progress><p>Total <?=money($i['total'])?> | Verified <?=money($paid)?> | Outstanding <?=money($i['total']-$paid)?> | Due <?=h($i['due_date'])?></p>
<?php if(!$admin&&$settings['enabled']&&$paid<$i['total']):?><p>Send e-Transfer to <?=h($settings['email'])?> with invoice reference <?=h($i['ref'])?>. Confirm the recipient in your bank app. Partial payments accepted.</p><?php form('submit');?><input type="hidden" name="id" value="<?=$i['id']?>"><label>Amount sent CAD<input name="amount" type="number" min=".01" step=".01" required></label><label>Bank confirmation reference<input name="bank_ref" maxlength="190" required></label><button>Report payment sent</button></form><?php endif;?>
<?php foreach(q('SELECT * FROM pay_entries WHERE invoice_id=? ORDER BY id DESC',[$i['id']]) as $e):?><p><?=money($e['amount'])?> · <?=h($e['status'])?> · <?=h($e['bank_ref'])?><?php if($e['status']==='verified'):?> · Receipt ONB-R-<?=$e['id']?> · <?=h($e['verified_at'])?><?php endif;?></p><?php if($admin&&$e['status']==='pending'):form('verify');?><input type="hidden" name="id" value="<?=$e['id']?>"><label><input type="checkbox" name="bank_checked" required>I verified this exact transfer in the bank account</label><button>Verify payment</button></form><?php form('reject');?><input type="hidden" name="id" value="<?=$e['id']?>"><button>Reject submission</button></form><?php endif;endforeach;?></article><?php endforeach;?></main></body></html>
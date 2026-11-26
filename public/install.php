<?php
declare(strict_types=1);
ini_set('display_errors','0');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$dir=__DIR__.'/../private';
if(is_file($dir.'/config.php')){http_response_code(403);exit('Already installed.');}
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){exit('HTTPS required.');}
if(!is_file($dir.'/install-key.txt'))exit('Create private/install-key.txt with a random secret of at least 32 characters before installation.');
$_SESSION['install_csrf']??=bin2hex(random_bytes(32));
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
try{
if(!hash_equals($_SESSION['install_csrf'],(string)($_POST['csrf']??'')))throw new Exception('Refresh the page.');
$key=trim(file_get_contents($dir.'/install-key.txt'));
if(strlen($key)<32||!hash_equals($key,(string)($_POST['key']??'')))throw new Exception('Invalid installation key.');
$email=trim($_POST['email']??'');$pw=$_POST['admin_password']??'';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<12)throw new Exception('Valid email and password of at least 12 characters required.');
$name=$_POST['database']??'';if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new Exception('Invalid database name.');
$cfg=['dsn'=>'mysql:host=localhost;dbname='.$name.';charset=utf8mb4','user'=>$_POST['user']??'','password'=>$_POST['db_password']??''];
$lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new Exception('Installation in progress.');
if(is_file($dir.'/config.php'))throw new Exception('Already installed.');
$db=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SHOW TABLES')->fetch())throw new Exception('Use a new empty database. Existing data will not be overwritten.');
$sql=file_get_contents(__DIR__.'/../schema.sql');if(!$sql)throw new Exception('Schema file missing.');
$db->exec($sql);
$db->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,'admin')")->execute(['Administrator',strtolower($email),password_hash($pw,PASSWORD_DEFAULT)]);
$tmp=tempnam($dir,'config-');if(!$tmp)throw new Exception('Configuration directory not writable.');
chmod($tmp,0600);
if(file_put_contents($tmp,'<?php return '.var_export($cfg,true).';')===false||!rename($tmp,$dir.'/config.php'))throw new Exception('Could not save configuration.');
unlink($dir.'/install-key.txt');unset($_SESSION['install_csrf']);header('Location: index.php?page=login');exit;
}catch(PDOException $e){$code=(int)($e->errorInfo[1]??0);$error=match($code){1045=>'Database login denied. Check the full cPanel username and password.',1044,1142=>'Database permission denied. Grant the database user all privileges.',1049=>'Database not found. Check the full cPanel database name.',1064=>'SQL syntax error during setup. Use the latest installer and a new empty database.',default=>'Database setup failed (MySQL error '.$code.'). Check hosting error logs and permissions.'};}catch(Throwable $e){$error=$e->getMessage();}
}
function esc($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Our Next Boarding setup</title><link rel="stylesheet" href="style.css"><main><h1>Welcome aboard.</h1><p>Install your travel platform.</p><?php if($error):?><p role="alert"><?=esc($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['install_csrf'])?>"><label>Installation key<input name="key" type="password" required></label><label>cPanel database name<input name="database" required></label><label>Database username<input name="user" required></label><label>Database password<input name="db_password" type="password" required></label><label>Administrator email<input name="email" type="email" required></label><label>Administrator password<input name="admin_password" type="password" minlength="12" required></label><button>Install website</button></form></main></html>
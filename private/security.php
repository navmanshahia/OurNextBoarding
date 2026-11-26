<?php
// Shared session guard. Password changes invalidate every existing session.
function securityGuard(PDO $db): void {
 if(empty($_SESSION['uid']))return;
 $s=$db->prepare('SELECT password,role FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);$a=$s->fetch(PDO::FETCH_ASSOC);
 if(!$a||!hash_equals(hash('sha256',$a['password']), (string)($_SESSION['auth_stamp']??''))||($a['role']==='admin'&&empty($_SESSION['admin_factor']))){
 $_SESSION=[];session_regenerate_id(true);header('Location: index.php?page=login');exit;
 }
}
function securitySetup(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS onb_email_verification(user_id INT PRIMARY KEY,digest CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,verified_at DATETIME NULL) ENGINE=InnoDB");
}
function securityMail(array $c,string $email,string $subject,string $body):bool{
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||empty($c['mail_from'])||!filter_var($c['mail_from'],FILTER_VALIDATE_EMAIL))return false;
 return mail($email,$subject,$body,'From: '.$c['mail_from']);
}
function securityVerifySend(PDO $db,array $c,array $a):void{
 securitySetup($db);if(empty($c['public_url'])||!str_starts_with($c['public_url'],'https://'))throw new RuntimeException('Email service not configured.');
 $raw=bin2hex(random_bytes(32));$s=$db->prepare("INSERT INTO onb_email_verification(user_id,digest,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE digest=VALUES(digest),expires_at=VALUES(expires_at)");$s->execute([$a['id'],hash('sha256',$raw)]);
 if(!securityMail($c,$a['email'],'Verify your Our Next Boarding email',$c['public_url'].'?page=verify&code='.$raw))throw new RuntimeException('Email could not be submitted.');
}
function securityLogin(array $a):void{
 session_regenerate_id(true);unset($_SESSION['pending_factor']);$_SESSION['uid']=$a['id'];$_SESSION['auth_stamp']=hash('sha256',$a['password']);$_SESSION['admin_factor']=$a['role']==='admin';$_SESSION['csrf']=bin2hex(random_bytes(32));
}

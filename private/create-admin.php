<?php
if(PHP_SAPI!=='cli')exit;
$c=require __DIR__.'/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$email=$argv[1]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))exit("Supply admin email.\n");
fwrite(STDOUT,"Password (12+ characters, input visible): ");$pw=rtrim(fgets(STDIN),"\r\n");if(strlen($pw)<12)exit("Too short.\n");
$db->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,'admin')")->execute(['Administrator',strtolower($email),password_hash($pw,PASSWORD_DEFAULT)]);echo "Admin created.\n";
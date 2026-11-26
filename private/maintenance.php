<?php
if(PHP_SAPI!=='cli')exit;
$c=require __DIR__.'/config.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("DELETE FROM attempts WHERE created_at < NOW()-INTERVAL 1 DAY");
echo "Expired rate-limit records removed.\n";

<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=require __DIR__.'/config.php';require __DIR__.'/mailer.php';
if(empty($c['public_url'])||!str_starts_with($c['public_url'],'https://'))exit("Configure public_url first.\n");
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE IF NOT EXISTS onb_booking_mail(booking_id INT NOT NULL,kind VARCHAR(24) NOT NULL,period VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL,PRIMARY KEY(booking_id,kind,period)) ENGINE=InnoDB");
if(!$db->query("SELECT GET_LOCK('onb_booking_mail',0)")->fetchColumn())exit;
try{
$rows=$db->query("SELECT b.*,u.email,t.title,COALESCE(m.net,0) AS net FROM onb_bookings b JOIN users u ON u.id=b.user_id JOIN trips t ON t.id=b.trip_id LEFT JOIN (SELECT booking_id,SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END) AS net FROM onb_booking_money GROUP BY booking_id) m ON m.booking_id=b.id WHERE b.stage='confirmed'")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){
$due=round((float)$r['price']-(float)$r['net'],2);
$events=['confirmation'=>'once'];if($due>0)$events['balance_reminder']=(new DateTimeImmutable('now',new DateTimeZone('America/Vancouver')))->format('o-W');
foreach($events as $kind=>$period){
$s=$db->prepare("INSERT IGNORE INTO onb_booking_mail VALUES(?,?,?,'sending')");$s->execute([$r['id'],$kind,$period]);if(!$s->rowCount())continue;
$subject=$kind==='confirmation'?'Your booking is confirmed':'Your outstanding booking balance';
$body="Journey: ".$r['title']."\nBooking reference: ".$r['ref']."\nTotal CAD: ".number_format((float)$r['price'],2)."\nNet paid CAD: ".number_format((float)$r['net'],2)."\nOutstanding CAD: ".number_format(max(0,$due),2)."\nView your booking: ".$c['public_url']."?page=bookings";
$ok=onbMail($c,$r['email'],$subject,$body);$db->prepare("UPDATE onb_booking_mail SET status=? WHERE booking_id=? AND kind=? AND period=?")->execute([$ok?'submitted':'failed',$r['id'],$kind,$period]);
}}
}finally{$db->query("SELECT RELEASE_LOCK('onb_booking_mail')");}

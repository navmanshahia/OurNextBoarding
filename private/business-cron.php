<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('America/Vancouver');
$c=require __DIR__.'/config.php';
require_once __DIR__.'/mailer.php';
$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function q($sql,$args=[]){global $db;$s=$db->prepare($sql);$s->execute($args);return $s;}
foreach(['agent-module','booking-module','growth-module','departure-module','premium-module','customer-rewards','business-module'] as $file)require_once __DIR__.'/../public/'.$file.'.php';
function sendOnce($key,$to,$subject,$body){global $c;
 if(q("INSERT IGNORE INTO onb_business_mail(event_key,status) VALUES(?,'sending')",[$key])->rowCount()!==1)return;
 try{$ok=onbMail($c,$to,$subject,$body,'info@ournextboarding.com');q('UPDATE onb_business_mail SET status=? WHERE event_key=?',[$ok?'submitted':'failed',$key]);if(!$ok)error_log('Business mail failed: '.$key);}catch(Throwable $e){q("UPDATE onb_business_mail SET status='failed' WHERE event_key=?",[$key]);error_log('Business mail failed: '.$key);}
}
if((int)q("SELECT GET_LOCK('onb_business_cron_v1',0)")->fetchColumn()!==1)exit;
try{
 businessSetup();$today=businessToday();
 $base=rtrim((string)($c['public_url']??'https://ournextboarding.com'),'/');
 $adminUrl=$base.'/?page=business';$travelUrl=$base.'/?page=departures';
 // Schedule new leads for a follow-up two calendar days after the enquiry.
 q("INSERT IGNORE INTO onb_crm(lead_id,stage,followup,notes) SELECT id,'new',DATE_ADD(DATE(created_at),INTERVAL 2 DAY),'' FROM enquiries WHERE status='new' AND created_at>=NOW()-INTERVAL 30 DAY");
 $due=(int)q("SELECT COUNT(*) FROM onb_crm WHERE followup<=? AND stage NOT IN ('won','lost')",[$today])->fetchColumn();
 $agent=(int)q("SELECT COUNT(*) FROM onb_agent_leads WHERE followup<=? AND stage NOT IN ('won','lost')",[$today])->fetchColumn();
 $incomplete=(int)q("SELECT COUNT(*) FROM onb_departure_requests r JOIN onb_checkout x ON x.request_id=r.id JOIN onb_departures d ON d.id=r.departure_id WHERE r.status IN ('requested','reserved') AND x.booking_id IS NULL AND r.created_at<=NOW()-INTERVAL 1 DAY AND d.start_date>=?",[$today])->fetchColumn();
 $wait=(int)q("SELECT COUNT(*) FROM onb_departure_requests r JOIN onb_departures d ON d.id=r.departure_id WHERE r.status='waitlist' AND d.active=1 AND d.start_date>=?",[$today])->fetchColumn();
 if($due+$agent+$incomplete+$wait>0)sendOnce('team-digest:'.$today,'info@ournextboarding.com','Daily sales follow-up digest',"CRM leads due: $due\nAgent follow-ups due: $agent\nIncomplete checkout requests: $incomplete\nWaitlisted reservations: $wait\n\nReview your secure dashboard: ".$adminUrl);
 // Only customers who expressly requested a reminder, single email per request.
 foreach(q("SELECT r.id,u.email,t.title,d.start_date FROM onb_checkout_reminders m JOIN onb_departure_requests r ON r.id=m.request_id JOIN onb_checkout x ON x.request_id=r.id JOIN onb_departures d ON d.id=r.departure_id JOIN trips t ON t.id=d.trip_id JOIN users u ON u.id=r.user_id LEFT JOIN onb_business_mail l ON l.event_key=CONCAT('checkout:',r.id) WHERE m.enabled=1 AND r.status='reserved' AND r.adults=1 AND r.children=0 AND x.booking_id IS NULL AND d.active=1 AND t.published=1 AND d.start_date>? AND r.created_at<=NOW()-INTERVAL 1 DAY AND l.event_key IS NULL ORDER BY r.id LIMIT 25",[$today]) as $r){
 if(!q("SELECT 1 FROM onb_checkout_reminders m JOIN onb_checkout x ON x.request_id=m.request_id JOIN onb_departure_requests r ON r.id=x.request_id WHERE m.request_id=? AND m.enabled=1 AND x.booking_id IS NULL AND r.status='reserved'",[$r['id']])->fetchColumn())continue;
 sendOnce('checkout:'.$r['id'],$r['email'],'Your requested checkout reminder',"Your approved reservation for ".$r['title']." (".$r['start_date'].") is awaiting checkout. Sign in to review the quotation and terms: ".$travelUrl."\n\nYou requested this one-time reminder. No payment has been collected. You can disable reminders in your quotation requests or contact info@ournextboarding.com.");
 }
 foreach(q("SELECT n.booking_id,b.ref,b.price,b.stage,u.email,t.title FROM onb_booking_notifications n JOIN onb_bookings b ON b.id=n.booking_id JOIN users u ON u.id=b.user_id JOIN trips t ON t.id=b.trip_id LEFT JOIN onb_business_mail l ON l.event_key=CONCAT('confirmed:',b.id) WHERE b.stage='confirmed' AND l.event_key IS NULL ORDER BY n.created_at LIMIT 25") as $b){
 $net=bookingNet($b['booking_id']);if($net<min(500,(float)$b['price']))continue;
 sendOnce('confirmed:'.$b['booking_id'],$b['email'],'Booking confirmation '.$b['ref'],"Your booking for ".$b['title']." is confirmed.\nReference: ".$b['ref']."\nTotal: CAD ".number_format((float)$b['price'],2)."\nNet recorded payments: CAD ".number_format($net,2)."\n\nSign in to view the payment milestones, itinerary and booking details: ".$base."/?page=bookings");
 }
}finally{q("SELECT RELEASE_LOCK('onb_business_cron_v1')");}

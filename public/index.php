<?php
declare(strict_types=1);
ini_set('display_errors','0');
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https:; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
if(!is_file(__DIR__.'/../private/config.php')){http_response_code(503);exit('Configuration required. See README.');}
try{$c=require __DIR__.'/../private/config.php';$db=new PDO($c['dsn'],$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable $e){http_response_code(503);exit('Service temporarily unavailable.');}
function q($sql,$args=[]){global $db;$s=$db->prepare($sql);$s->execute($args);return $s;}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function p($k){return trim((string)($_POST[$k]??''));}
function go($page){header('Location: ?'.$page);exit;}
function token(){return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
function field($n,$label,$v='',$type='text'){echo '<label>'.h($label).'<input name="'.h($n).'" type="'.h($type).'" value="'.h($v).'" required></label>';}
function area($n,$label,$v=''){echo '<label>'.h($label).'<textarea name="'.h($n).'">'.h($v).'</textarea></label>';}
function form($action){echo '<form method="post">'.token().'<input type="hidden" name="action" value="'.h($action).'">';}
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$u=isset($_SESSION['uid'])?q('SELECT id,name,email,role FROM users WHERE id=?',[$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC):false;
$admin=$u&&$u['role']==='admin';
$error='';
require_once __DIR__.'/agent-module.php';
agentReferralCapture();
if(($_GET['page']??'')==='agent_statement')agentStatement($u,$admin);
require_once __DIR__.'/boarding-module.php';
require_once __DIR__.'/booking-module.php';
if(($_GET['page']??'')==='booking_document')bookingDocument($u,$admin);
if($admin){$db->exec("CREATE TABLE IF NOT EXISTS trip_details(trip_id INT PRIMARY KEY,activities TEXT NOT NULL,optional_activities TEXT NOT NULL,exclusions TEXT NOT NULL,FOREIGN KEY(trip_id) REFERENCES trips(id))");}
function noticeDefaults(){return ['Booking and availability'=>'Enquiries do not confirm a reservation. Final availability and pricing must be confirmed.','Passports and visas'=>'Entry requirements depend on nationality and travel documents. Confirm requirements before departure.','Cancellation and refunds'=>'Written cancellation and refund terms will be provided before any booking commitment.','Travel insurance'=>'Consider suitable medical and cancellation coverage.','Itinerary changes'=>'Activities and timing may change due to weather, availability or operational requirements.'];}
function journeyNotices($id){try{$r=q('SELECT label,body FROM onb_notices WHERE trip_id=?',[$id])->fetchAll(PDO::FETCH_KEY_PAIR);return array_replace(noticeDefaults(),$r);}catch(PDOException $e){return noticeDefaults();}}
function tripImage($t){return $t['image']?: (stripos($t['route'],'Cebu')!==false?'https://images.unsplash.com/photo-1518509562904-e7ef99cdcc86?auto=format&fit=crop&w=1600&q=85':'https://images.unsplash.com/photo-1493976040374-85c8e12f0c0e?auto=format&fit=crop&w=1600&q=85');}
function tripDetails($id){try{return q('SELECT * FROM trip_details WHERE trip_id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:[];}catch(PDOException $e){return [];}}
function renderTripDetails($id){$d=tripDetails($id);foreach(['activities'=>'Included activities','optional_activities'=>'Optional activities (not included unless stated)','exclusions'=>'What is not included'] as $k=>$label){echo '<section><h2>'.h($label).'</h2>'; $items=preg_split('/\r\n|\r|\n/',trim($d[$k]??''));if(empty($d[$k]))echo '<p>Details to be confirmed.</p>';else{echo '<ul>';foreach($items as $item)if(trim($item)!=='')echo '<li>'.h($item).'</li>';echo '</ul>';}echo '</section>';}}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
 if(!hash_equals($_SESSION['csrf'],p('csrf')))throw new RuntimeException('Please refresh and try again.');
 $action=p('action');
 if($action==='logout'){session_destroy();go('page=home');}
 if(in_array($action,['login','enquire','register'],true)){
 $ip=$_SERVER['REMOTE_ADDR']??'unknown';
 if(q('SELECT COUNT(*) FROM attempts WHERE ip=? AND created_at > NOW()-INTERVAL 15 MINUTE',[$ip])->fetchColumn()>=15)throw new RuntimeException('Too many requests. Try later.');
 q('INSERT INTO attempts(ip) VALUES(?)',[$ip]);
 }
 if($action==='login'){
 $a=q('SELECT * FROM users WHERE email=?',[strtolower(p('email'))])->fetch(PDO::FETCH_ASSOC);
 if(!$a||!password_verify(p('password'),$a['password']))throw new RuntimeException('Invalid credentials.');
 session_regenerate_id(true);$_SESSION['uid']=$a['id'];go(agentActive($a['id'])?'page=agents':'page=portal');
 }
 if($action==='register'){
 if(!filter_var(p('email'),FILTER_VALIDATE_EMAIL)||strlen(p('password'))<12||!p('name'))throw new RuntimeException('Valid email, name and 12-character password required.');
 q('INSERT INTO users(name,email,password) VALUES(?,?,?)',[p('name'),strtolower(p('email')),password_hash(p('password'),PASSWORD_DEFAULT)]);go('page=login');
 }
 if($action==='enquire'){
 if(!filter_var(p('email'),FILTER_VALIDATE_EMAIL)||!p('name')||!p('message')||strlen(p('message'))>5000)throw new RuntimeException('Please complete all fields.');
 $id=(int)p('trip_id');if($id&&!q('SELECT id FROM trips WHERE id=? AND published=1',[$id])->fetchColumn())throw new RuntimeException('Trip unavailable.');
 if(isset($_SESSION['agent_ref']))agentExtrasSetup();$db->beginTransaction();try{q('INSERT INTO enquiries(trip_id,name,email,message) VALUES(?,?,?,?)',[$id?:null,p('name'),p('email'),p('message')]);agentAttribute((int)$db->lastInsertId(),$id);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}go('page=thanks');
 }
 if(str_starts_with($action,'concierge_'))conciergeAction($action,$u,$admin);
 if(str_starts_with($action,'booking_'))bookingAction($action,$admin);
 if(str_starts_with($action,'agent_'))agentAction($action,$u,$admin);
 if(!$admin)throw new RuntimeException('Access denied.');
 if($action==='save_notices'){
$id=(int)p('id');if(!q('SELECT id FROM trips WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('Invalid journey.');
$db->exec('CREATE TABLE IF NOT EXISTS onb_notices(trip_id INT NOT NULL,label VARCHAR(100) NOT NULL,body TEXT NOT NULL,PRIMARY KEY(trip_id,label))');
$db->beginTransaction();foreach(array_keys(noticeDefaults()) as $i=>$label){q('INSERT INTO onb_notices(trip_id,label,body) VALUES(?,?,?) ON DUPLICATE KEY UPDATE body=VALUES(body)',[$id,$label,p('notice_'.$i)]);}$db->commit();go('page=admin');
}
if($action==='itinerary_pdf'){
$id=(int)p('id');if(!q('SELECT id FROM trips WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('Invalid journey.');
$dir=__DIR__.'/../private/itineraries';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create PDF storage.');
$f=$_FILES['pdf']??[];if(($f['error']??1)!==UPLOAD_ERR_OK||$f['size']>15000000||$f['size']<5||!is_uploaded_file($f['tmp_name'])||file_get_contents($f['tmp_name'],false,null,0,5)!=='%PDF-')throw new RuntimeException('Upload a valid PDF under 15 MB.');
if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$id.'.pdf'))throw new RuntimeException('Unable to store PDF.');
go('page=admin');
}
if($action==='trip'){
 $data=[p('title'),p('route'),p('dates'),p('description'),p('itinerary'),p('inclusions'),max(0,(int)p('seats')),max(0,(float)p('price')),max(0,(float)p('cost')),p('image'),isset($_POST['published'])?1:0];
 if(!p('title'))throw new RuntimeException('Title required.');
 $db->beginTransaction();
 if((int)p('id')){ $data[]=(int)p('id');q('UPDATE trips SET title=?,route=?,dates=?,description=?,itinerary=?,inclusions=?,seats=?,price=?,cost=?,image=?,published=? WHERE id=?',$data);}
 else q('INSERT INTO trips(title,route,dates,description,itinerary,inclusions,seats,price,cost,image,published) VALUES(?,?,?,?,?,?,?,?,?,?,?)',$data);
 $tripId=(int)p('id')?: (int)$db->lastInsertId();
 q('INSERT INTO trip_details(trip_id,activities,optional_activities,exclusions) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE activities=VALUES(activities),optional_activities=VALUES(optional_activities),exclusions=VALUES(exclusions)',[$tripId,p('activities'),p('optional_activities'),p('exclusions')]);$db->commit();
 go('page=admin');
 }
 if($action==='lead'){if(!in_array(p('status'),['new','contacted','closed'],true))throw new RuntimeException('Invalid status');q('UPDATE enquiries SET status=? WHERE id=?',[p('status'),(int)p('id')]);go('page=admin');}
 if($action==='assign'){
 $uid=q("SELECT id FROM users WHERE email=? AND role='traveller'",[strtolower(p('email'))])->fetchColumn();if(!$uid)throw new RuntimeException('Traveller must register first.');
 q('INSERT INTO travellers(user_id,trip_id,status,payment,details) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),payment=VALUES(payment),details=VALUES(details)',[$uid,(int)p('trip_id'),p('status'),p('payment'),p('details')]);go('page=admin');
 }
 throw new RuntimeException('Unknown action.');
 }catch(PDOException $e){if($db->inTransaction())$db->rollBack();$error='Unable to save. Check the information or try again.';}catch(RuntimeException $e){$error=$e->getMessage();}
}
$page=$_GET['page']??'home';
if($page==='itinerary-pdf'){
$id=(int)($_GET['id']??0);$file=__DIR__.'/../private/itineraries/'.$id.'.pdf';
if(!q('SELECT id FROM trips WHERE id=? AND published=1',[$id])->fetchColumn()||!is_file($file)){http_response_code(404);exit('Itinerary unavailable.');}
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="Our-Next-Boarding-itinerary.pdf"');header('Content-Length: '.filesize($file));readfile($file);exit;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Our Next Boarding | Extraordinary journeys</title><link rel="stylesheet" href="style.css?v=20261126g"><script defer src="app.js?v=20261126g"></script><link rel="stylesheet" href="journey.css?v=1"><script defer src="journey.js?v=1"></script><link rel="stylesheet" href="discover.css?v=1"><script defer src="discover.js?v=1"></script><link rel="stylesheet" href="brand.css?v=1"><link rel="stylesheet" href="auth-premium.css?v=1"><link rel="stylesheet" href="boarding-ui.css?v=3"></head><body class="<?=($page==='admin'&&$admin)||(in_array($page,['portal','agents','analytics','concierge','bookings'],true)&&$u)?'workspace':(in_array($page,['login','register','portal'])?'auth':'public-site')?>"><header><a class="onb-brand" href="?page=home" aria-label="Our Next Boarding home"><?php if(is_file(__DIR__."/brand-logo.webp")): ?><img src="brand-logo.webp" width="150" height="100" alt="Our Next Boarding"><?php else: ?>OUR NEXT BOARDING<?php endif; ?></a><nav><a href="?page=trips">Journeys</a><a href="?page=about">About</a><a href="?page=faq">FAQ</a><a href="?page=contact">Contact</a><a href="?page=portal">Traveller portal</a></nav></header><?php if($u&&in_array($page,['admin','portal','agents','analytics','concierge','bookings'],true)):?><aside class="side"><small>Our Next Boarding / Workspace</small><?php if($admin):?><a href="index.php?page=portal">Dashboard</a><a href="index.php?page=admin">Trip management</a><a href="operations.php">Business overview</a><a href="?page=bookings">Bookings & finance</a><a href="payments.php">Payments & invoices</a><a href="crm.php">Customer CRM</a><a href="index.php?page=agents">Agent management</a><a href="index.php?page=analytics">Business analytics</a><a href="index.php?page=concierge">Concierge inbox</a><?php else:?><a href="index.php?page=portal">My journeys</a><a href="?page=bookings">My bookings</a><?php if(agentActive($u["id"])):?><a href="?page=agents">Agent workspace</a><?php endif;?><a href="operations.php">Travel companion</a><a href="?page=concierge">Boarding Concierge</a><a href="payments.php">My payments</a><?php endif;?><a href="index.php?page=home">View website</a></aside><?php endif;?><div class="journey-progress" aria-hidden="true"></div><main>
<?php if($error):?><p class="notice" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(in_array($page,['home','trips','saved'])): require __DIR__.'/discover.php'; ?>
<?php elseif($page==='trip'): $t=q('SELECT * FROM trips WHERE published=1 AND id=?',[(int)($_GET['id']??0)])->fetch();if(!$t){http_response_code(404);echo '<h2>Journey unavailable</h2>';}else{require __DIR__.'/journey-view.php';}
elseif($page==='contact'):?><h1>Where to next?</h1><?php form('enquire');field('name','Full name',$u['name']??'');field('email','Email',$u['email']??'','email');?><label>Journey<select name="trip_id"><option value="0">General / custom trip</option><?php foreach(q('SELECT id,title FROM trips WHERE published=1') as $t):?><option value="<?=$t['id']?>" <?=((int)($_GET['id']??0)==$t['id'])?'selected':''?>><?=h($t['title'])?></option><?php endforeach;?></select></label><?php area('message','Tell us about your travel plans');?><p>We use your contact details to respond to your enquiry. This is not a booking or payment.</p><button>Send enquiry</button></form>
<?php elseif($page==='thanks'):?><h2>Thank you for your interest.</h2><p>Your enquiry has been received.</p>
<?php elseif($page==='about'):?><h1>Travel together.</h1><p>Our Next Boarding is developing curated group journeys built around culture, discovery and shared experiences.</p><a class="btn" href="?page=faq">Explore frequently asked questions</a><?php elseif($page==='faq'): require __DIR__.'/faq-content.php'; ?>
<?php elseif($page==='login'||$page==='register'||($page==='portal'&&!$u)): $reg=$page==='register';?><div class="auth-editorial"><div class="auth-story"><span>OUR NEXT BOARDING / YOUR STORY</span><h1>Every journey begins somewhere.</h1><p>Keep your next adventure close. Your journeys, details and travel plans, together in one place.</p></div><div class="auth-panel"><span class="eyebrow">YOUR TRAVEL SPACE</span><h2><?=$reg?'Begin your story':'Welcome back'?></h2><p><?=$reg?'Create your traveller account.':'Sign in to your traveller account.'?></p><?php form($reg?'register':'login');if($reg)field('name','Full name');field('email','Email','','email');field('password','Password','','password');if($reg):?><small>Use at least 12 characters.</small><?php endif;?><button><?=$reg?'Create my account':'Sign in'?> →</button></form><p><?=$reg?'Already a member?':'New here?'?> <a href="?page=<?=$reg?'login':'register'?>"><?=$reg?'Sign in':'Create an account'?></a></p></div></div>
<?php elseif($page==='portal'&&$admin):?><div class="dash-hero"><span>MANAGEMENT CONSOLE</span><h1>Welcome, <?=h($u['name'])?></h1><p>Manage your travel business from one workspace.</p></div><div class="admin-shortcuts"><?php foreach(['admin'=>'Trip management','bookings'=>'Bookings & finance','agents'=>'Agent network','analytics'=>'Business analytics','concierge'=>'Concierge inbox'] as $k=>$v):?><a href="?page=<?=h($k)?>"><strong><?=h($v)?></strong><span>Open workspace →</span></a><?php endforeach;?><a href="operations.php"><strong>Business overview</strong><span>Open workspace →</span></a><a href="payments.php"><strong>Payments & invoices</strong><span>Open workspace →</span></a><a href="crm.php"><strong>Customer CRM</strong><span>Open workspace →</span></a></div><?php form('logout');?><button>Sign out</button></form><?php elseif($page==='portal'):?><h2>Welcome, <?=h($u['name'])?></h2><p><a href="operations.php">Travel companion</a> · <a href="payments.php">Payments</a></p><?php foreach(q('SELECT t.id,t.title,t.dates,t.itinerary,t.inclusions,v.status,v.payment,v.details FROM travellers v JOIN trips t ON t.id=v.trip_id WHERE v.user_id=?',[$u['id']]) as $v):?><article><h3><?=h($v['title'])?></h3><p><?=h($v['status'])?> · <?=h($v['payment'])?></p><pre><?=h($v['itinerary'])?></pre><h3>What is included</h3><pre><?=h($v['inclusions'])?></pre><?php renderTripDetails($v['id']);?><pre><?=h($v['details'])?></pre></article><?php endforeach;?><?php form('logout');?><button>Sign out</button></form>
<?php elseif($page==='bookings'): bookingView($u,$admin); ?>
<?php elseif($page==='analytics'): analyticsView($admin); ?>
<?php elseif($page==='concierge'): conciergeView($u,$admin); ?>
<?php elseif($page==='agents'): agentView($u,$admin); ?>
<?php elseif($page==='admin'&&$admin):?><span class="eyebrow">Management console</span><h1>Trip management</h1><p class="muted">Manage departures, enquiries and assigned travellers in one place.</p><div class="metrics"><?php foreach(['Total journeys'=>'SELECT COUNT(*) FROM trips','Published journeys'=>'SELECT COUNT(*) FROM trips WHERE published=1','New enquiries'=>"SELECT COUNT(*) FROM enquiries WHERE status='new'",'Traveller assignments'=>'SELECT COUNT(*) FROM travellers'] as $label=>$sql):?><article><span><?=h($label)?></span><strong><?=h(q($sql)->fetchColumn())?></strong></article><?php endforeach;?></div><h2>Trips</h2><?php $ts=q('SELECT * FROM trips ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);foreach(array_merge([[]],$ts) as $t):?><details><summary><?=h($t['title']??'Create a new trip')?></summary><?php form('trip');?><input type="hidden" name="id" value="<?=h($t['id']??0)?>"><?php foreach(['title'=>'Title','route'=>'Route','dates'=>'Dates','image'=>'Image URL','seats'=>'Seat capacity','price'=>'Internal price CAD','cost'=>'Estimated total cost CAD'] as $k=>$label) {if($k==='image'){echo '<label>Image URL<input name="image" value="'.h($t[$k]??'').'"></label>';}else field($k,$label,$t[$k]??(in_array($k,['seats','price','cost'])?0:''));}foreach(['description'=>'Overview','itinerary'=>'Day-by-day itinerary: one day per line. Format: Day 1 — Osaka | Arrival, activities, meals, overnight hotel. Repeat for each day.','inclusions'=>'What is included (one item per line)'] as $k=>$label)area($k,$label,$t[$k]??'');$d=empty($t['id'])?[]:tripDetails($t['id']);foreach(['activities'=>'Included activities (one per line)','optional_activities'=>'Optional activities and extra costs (one per line)','exclusions'=>'What is not included (one per line)'] as $k=>$label)area($k,$label,$d[$k]??'');?><label><input type="checkbox" name="published" <?=!empty($t['published'])?'checked':''?>> Publish</label><button>Save trip</button></form><?php if(!empty($t["id"])): ?><h3>Before you go — booking notices</h3><?php form("save_notices");?><input type="hidden" name="id" value="<?=(int)$t["id"]?>"><?php $i=0;foreach(journeyNotices($t["id"]) as $label=>$body)area("notice_".$i++,$label,$body);?><button>Save booking notices</button></form><form method="post" enctype="multipart/form-data"><?=token()?><input type="hidden" name="action" value="itinerary_pdf"><input type="hidden" name="id" value="<?=(int)$t["id"]?>"><label>Upload or replace itinerary PDF (max 15 MB)<input type="file" name="pdf" accept="application/pdf,.pdf" required></label><button>Upload PDF</button><?php if(is_file(__DIR__."/../private/itineraries/".(int)$t["id"].".pdf")): ?><p>PDF uploaded.</p><?php endif; ?></form><?php endif; ?></details><?php endforeach;?>
<h2>Enquiries</h2><?php foreach(q('SELECT * FROM enquiries ORDER BY id DESC') as $e):?><article><strong><?=h($e['name'])?></strong><p><?=h($e['email'])?> · <?=h($e['status'])?></p><pre><?=h($e['message'])?></pre><?php form('lead');?><input type="hidden" name="id" value="<?=$e['id']?>"><select name="status"><?php foreach(['new','contacted','closed'] as $s):?><option <?=$s===$e['status']?'selected':''?>><?=$s?></option><?php endforeach;?></select><button>Update</button></form></article><?php endforeach;?>
<h2>Assign or update traveller</h2><?php form('assign');field('email','Registered traveller email','','email');?><label>Trip<select name="trip_id"><?php foreach($ts as $t):?><option value="<?=$t['id']?>"><?=h($t['title'])?></option><?php endforeach;?></select></label><?php field('status','Status','Enquiry');field('payment','Payment record','Not collected');area('details','Flight, hotel and packing details');?><button>Save traveller</button></form>
<h2>Traveller register</h2><?php foreach(q('SELECT u.name,u.email,t.title,v.status FROM travellers v JOIN users u ON u.id=v.user_id JOIN trips t ON t.id=v.trip_id') as $v):?><p><?=h(implode(' · ',$v))?></p><?php endforeach;?>
<?php else:http_response_code(404);?><h2>Page unavailable</h2><?php endif;?></main><footer><?php if(is_file(__DIR__."/brand-logo.webp")): ?><img class="onb-footer-logo" src="brand-logo.webp" width="180" height="120" alt="Our Next Boarding"><?php else: ?><strong>OUR NEXT BOARDING</strong><?php endif; ?><p>Extraordinary destinations. Unforgettable journeys.</p><nav><a href="?page=trips">Journeys</a><a href="?page=faq">FAQ</a><a href="?page=contact">Contact</a></nav></footer></body></html>
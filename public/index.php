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
if($admin){$db->exec("CREATE TABLE IF NOT EXISTS trip_details(trip_id INT PRIMARY KEY,activities TEXT NOT NULL,optional_activities TEXT NOT NULL,exclusions TEXT NOT NULL,FOREIGN KEY(trip_id) REFERENCES trips(id))");}
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
 session_regenerate_id(true);$_SESSION['uid']=$a['id'];go('page=portal');
 }
 if($action==='register'){
 if(!filter_var(p('email'),FILTER_VALIDATE_EMAIL)||strlen(p('password'))<12||!p('name'))throw new RuntimeException('Valid email, name and 12-character password required.');
 q('INSERT INTO users(name,email,password) VALUES(?,?,?)',[p('name'),strtolower(p('email')),password_hash(p('password'),PASSWORD_DEFAULT)]);go('page=login');
 }
 if($action==='enquire'){
 if(!filter_var(p('email'),FILTER_VALIDATE_EMAIL)||!p('name')||!p('message')||strlen(p('message'))>5000)throw new RuntimeException('Please complete all fields.');
 $id=(int)p('trip_id');if($id&&!q('SELECT id FROM trips WHERE id=? AND published=1',[$id])->fetchColumn())throw new RuntimeException('Trip unavailable.');
 q('INSERT INTO enquiries(trip_id,name,email,message) VALUES(?,?,?,?)',[$id?:null,p('name'),p('email'),p('message')]);go('page=thanks');
 }
 if(!$admin)throw new RuntimeException('Access denied.');
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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Our Next Boarding | Extraordinary journeys</title><link rel="stylesheet" href="style.css?v=20261126b"></head><body class="<?=($page==='admin'&&$admin)||($page==='portal'&&$u)?'workspace':(in_array($page,['login','register','portal'])?'auth':'public-site')?>"><header><a href="?page=home">OUR NEXT BOARDING</a><nav><a href="?page=trips">Journeys</a><a href="?page=about">About</a><a href="?page=contact">Contact</a><a href="?page=portal">Traveller portal</a><?php if($admin):?><a href="?page=admin">Admin</a><?php endif;?></nav></header><?php if(($page==='admin'&&$admin)||($page==='portal'&&$u)):?><aside class="side"><small>Our Next Boarding / Workspace</small><?php if($admin):?><a href="index.php?page=admin">Trip management</a><a href="operations.php">Business overview</a><a href="payments.php">Payments & invoices</a><?php else:?><a href="index.php?page=portal">My journeys</a><a href="operations.php">Travel companion</a><a href="payments.php">My payments</a><?php endif;?><a href="index.php?page=home">View website</a></aside><?php endif;?><main>
<?php if($error):?><p class="notice" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(in_array($page,['home','trips'])):?>
<?php if($page==='home'):?><section class="hero"><span class="eyebrow">Curated small-group journeys</span><h1>Somewhere<br>extraordinary.</h1><p>New places. Shared moments. Your next unforgettable journey.</p><a class="btn" href="?page=trips">Explore journeys</a></section><?php endif;?>
<h2>Upcoming journeys</h2><?php if(!q('SELECT COUNT(*) FROM trips WHERE published=1')->fetchColumn()):?><p class="empty">New departures are being curated. Contact us to register your interest.</p><?php endif;?><div class="grid"><?php foreach(q('SELECT * FROM trips WHERE published=1')->fetchAll() as $t):?><article class="card"><?php if($t['image']):?><img src="<?=h($t['image'])?>" alt="Destination"><?php endif;?><span class="muted"><?=h($t['dates'])?></span><h3><?=h($t['title'])?></h3><p><?=h($t['route'])?></p><p><?=h($t['description'])?></p><a class="btn" href="?page=trip&id=<?=$t['id']?>">Discover journey</a></article><?php endforeach;?></div><a href="?page=contact">Ask about upcoming departures or custom trips</a>
<?php elseif($page==='trip'): $t=q('SELECT * FROM trips WHERE published=1 AND id=?',[(int)($_GET['id']??0)])->fetch();if(!$t){http_response_code(404);echo '<h2>Journey unavailable</h2>';}else{?><?php if($t['image']):?><img class="trip-cover" src="<?=h($t['image'])?>" alt="Journey destination"><?php endif;?><h1><?=h($t['title'])?></h1><p><?=h($t['route'])?> · <?=h($t['dates'])?></p><p><?=h($t['description'])?></p><h2>Day-by-day itinerary</h2><pre><?=h($t['itinerary'])?></pre><h2>What is included</h2><pre><?=h($t['inclusions'])?></pre><?php renderTripDetails($t['id']);?><a class="btn" href="?page=contact&id=<?=$t['id']?>">Request pricing</a><?php }
elseif($page==='contact'):?><h1>Where to next?</h1><?php form('enquire');field('name','Full name',$u['name']??'');field('email','Email',$u['email']??'','email');?><label>Journey<select name="trip_id"><option value="0">General / custom trip</option><?php foreach(q('SELECT id,title FROM trips WHERE published=1') as $t):?><option value="<?=$t['id']?>" <?=((int)($_GET['id']??0)==$t['id'])?'selected':''?>><?=h($t['title'])?></option><?php endforeach;?></select></label><?php area('message','Tell us about your travel plans');?><p>We use your contact details to respond to your enquiry. This is not a booking or payment.</p><button>Send enquiry</button></form>
<?php elseif($page==='thanks'):?><h2>Thank you for your interest.</h2><p>Your enquiry has been received.</p>
<?php elseif($page==='about'):?><h1>Travel together.</h1><p>Our Next Boarding is developing curated group journeys built around culture, discovery and shared experiences.</p><h2>Frequently asked questions</h2><details><summary>How do I join?</summary>Submit an enquiry to discuss proposed trip details.</details><details><summary>Are dates guaranteed?</summary>Dates and inclusions are subject to confirmation.</details><details><summary>Can I pay online?</summary>No. Online payments are not currently offered.</details>
<?php elseif($page==='login'||$page==='register'||($page==='portal'&&!$u)): $reg=$page==='register';?><h2><?=$reg?'Create account':'Sign in'?></h2><?php form($reg?'register':'login');if($reg)field('name','Full name');field('email','Email','','email');field('password','Password','','password');?><button>Continue</button></form><a href="?page=<?=$reg?'login':'register'?>"><?=$reg?'Sign in instead':'Create an account'?></a>
<?php elseif($page==='portal'):?><h2>Welcome, <?=h($u['name'])?></h2><p><a href="operations.php">Travel companion</a> · <a href="payments.php">Payments</a></p><?php foreach(q('SELECT t.id,t.title,t.dates,t.itinerary,t.inclusions,v.status,v.payment,v.details FROM travellers v JOIN trips t ON t.id=v.trip_id WHERE v.user_id=?',[$u['id']]) as $v):?><article><h3><?=h($v['title'])?></h3><p><?=h($v['status'])?> · <?=h($v['payment'])?></p><pre><?=h($v['itinerary'])?></pre><h3>What is included</h3><pre><?=h($v['inclusions'])?></pre><?php renderTripDetails($v['id']);?><pre><?=h($v['details'])?></pre></article><?php endforeach;?><?php form('logout');?><button>Sign out</button></form>
<?php elseif($page==='admin'&&$admin):?><span class="eyebrow">Management console</span><h1>Trip management</h1><p class="muted">Manage departures, enquiries and assigned travellers in one place.</p><div class="metrics"><?php foreach(['Total journeys'=>'SELECT COUNT(*) FROM trips','Published journeys'=>'SELECT COUNT(*) FROM trips WHERE published=1','New enquiries'=>"SELECT COUNT(*) FROM enquiries WHERE status='new'",'Traveller assignments'=>'SELECT COUNT(*) FROM travellers'] as $label=>$sql):?><article><span><?=h($label)?></span><strong><?=h(q($sql)->fetchColumn())?></strong></article><?php endforeach;?></div><h2>Trips</h2><?php $ts=q('SELECT * FROM trips ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);foreach(array_merge([[]],$ts) as $t):?><details><summary><?=h($t['title']??'Create a new trip')?></summary><?php form('trip');?><input type="hidden" name="id" value="<?=h($t['id']??0)?>"><?php foreach(['title'=>'Title','route'=>'Route','dates'=>'Dates','image'=>'Image URL','seats'=>'Seat capacity','price'=>'Internal price CAD','cost'=>'Estimated total cost CAD'] as $k=>$label) {if($k==='image'){echo '<label>Image URL<input name="image" value="'.h($t[$k]??'').'"></label>';}else field($k,$label,$t[$k]??(in_array($k,['seats','price','cost'])?0:''));}foreach(['description'=>'Overview','itinerary'=>'Full day-by-day itinerary (Day 1, Day 2, etc. Include city, activities, meals and overnight stay)','inclusions'=>'What is included (one item per line)'] as $k=>$label)area($k,$label,$t[$k]??'');$d=empty($t['id'])?[]:tripDetails($t['id']);foreach(['activities'=>'Included activities (one per line)','optional_activities'=>'Optional activities and extra costs (one per line)','exclusions'=>'What is not included (one per line)'] as $k=>$label)area($k,$label,$d[$k]??'');?><label><input type="checkbox" name="published" <?=!empty($t['published'])?'checked':''?>> Publish</label><button>Save trip</button></form></details><?php endforeach;?>
<h2>Enquiries</h2><?php foreach(q('SELECT * FROM enquiries ORDER BY id DESC') as $e):?><article><strong><?=h($e['name'])?></strong><p><?=h($e['email'])?> · <?=h($e['status'])?></p><pre><?=h($e['message'])?></pre><?php form('lead');?><input type="hidden" name="id" value="<?=$e['id']?>"><select name="status"><?php foreach(['new','contacted','closed'] as $s):?><option <?=$s===$e['status']?'selected':''?>><?=$s?></option><?php endforeach;?></select><button>Update</button></form></article><?php endforeach;?>
<h2>Assign or update traveller</h2><?php form('assign');field('email','Registered traveller email','','email');?><label>Trip<select name="trip_id"><?php foreach($ts as $t):?><option value="<?=$t['id']?>"><?=h($t['title'])?></option><?php endforeach;?></select></label><?php field('status','Status','Enquiry');field('payment','Payment record','Not collected');area('details','Flight, hotel and packing details');?><button>Save traveller</button></form>
<h2>Traveller register</h2><?php foreach(q('SELECT u.name,u.email,t.title,v.status FROM travellers v JOIN users u ON u.id=v.user_id JOIN trips t ON t.id=v.trip_id') as $v):?><p><?=h(implode(' · ',$v))?></p><?php endforeach;?>
<?php else:http_response_code(404);?><h2>Page unavailable</h2><?php endif;?></main><footer>Our Next Boarding · Extraordinary destinations. Unforgettable journeys.</footer></body></html>
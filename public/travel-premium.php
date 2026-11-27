<?php
declare(strict_types=1);
/* Page-specific presentation and server-side autocomplete. */
function onbPremiumPages(): array {return ['flights','hotels','hotel_details','hotel_checkout','flight_checkout'];}
function onbTravelLookup(bool $admin): void {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    $kind=p('kind');$query=p('query');
    if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf']??'',p('csrf'))||!in_array($kind,['flights','hotels'],true)||!onbTravelCanSearch($kind,$admin)||strlen($query)<2||strlen($query)>100){http_response_code(400);echo '[]';exit;}
    $bucket=$_SESSION['onb_lookup_limit']??[0,0];if($bucket[0]<time()-60)$bucket=[time(),0];if($bucket[1]>=25){http_response_code(429);echo '[]';exit;}$bucket[1]++;$_SESSION['onb_lookup_limit']=$bucket;
    $cacheKey=hash('sha256',$kind.'|'.strtolower($query));$cached=$_SESSION['onb_lookup_cache'][$cacheKey]??null;if(is_array($cached)&&$cached['expires']>time()){echo json_encode($cached['items']);exit;}
    $path=$kind==='flights'?'/data/flights/airports?'.http_build_query(['q'=>$query]):'/data/places?'.http_build_query(['textQuery'=>$query]);
    $ch=curl_init('https://api.liteapi.travel/v3.0'.$path);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-Key: '.onbTravelSettings()['key']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($status!==200||!is_string($raw)){http_response_code(502);echo '[]';exit;}
    $data=json_decode($raw,true);$rows=$data['data']??[];$out=[];
    foreach(array_slice(is_array($rows)?$rows:[],0,12) as $row){
        if(!is_array($row))continue;
        if($kind==='flights'){
            $id=$row['iataCode']??$row['iata']??$row['code']??'';
            if(!is_string($id)||!preg_match('/^[A-Z]{3}$/D',$id))continue;
            $name=$row['name']??$row['airportName']??$id;$city=$row['cityName']??$row['city']??'';$country=$row['countryName']??$row['country']??'';
            $label=implode(' · ',array_filter([$id,is_string($name)?$name:'',is_string($city)?$city:'',is_string($country)?$country:'']));
        }else{$id=$row['placeId']??'';$label=$row['displayName']??'';if(is_array($label))$label=$label['text']??'';if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$id)||!is_string($label)||$label==='')continue;}
        $out[]=['id'=>$id,'label'=>substr($label,0,300)];
    }
    if(count($_SESSION['onb_lookup_cache']??[])>=30)$_SESSION['onb_lookup_cache']=[];
    $_SESSION['onb_lookup_cache'][$cacheKey]=['expires'=>time()+300,'items'=>$out];echo json_encode($out,JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
function onbPremiumDateLabel($value): string {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/',$value))return 'Time not supplied';
    try{return (new DateTimeImmutable($value))->format('D, M j · H:i');}catch(Throwable $e){return 'Time not supplied';}
}
function onbPremiumTime($value): string {return is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T(\d{2}:\d{2})/',$value,$m)?$m[1]:'—';}
function onbPremiumDuration($value): ?int {
    if(is_array($value))$value=$value['minutes']??null;
    return is_numeric($value)&&is_finite((float)$value)&&(float)$value>0?(int)$value:null;
}
function onbPremiumDurationText(?int $minutes): string {return $minutes===null?'Duration not supplied':intdiv($minutes,60).'h '.($minutes%60).'m';}
function onbPremiumPrice($value): ?float {
    if(is_array($value)&&isset($value[0])&&is_array($value[0]))$value=$value[0];
    if(!is_array($value)||($value['currency']??'')!=='CAD'||!is_numeric($value['amount']??null)||!is_finite((float)$value['amount'])||(float)$value['amount']<=0)return null;
    return round((float)$value['amount'],2);
}
function onbPremiumHotelPrice(array $room): ?float {
    $retail=onbPremiumPrice($room['offerRetailRate']??null);
    $ssp=onbPremiumPrice($room['suggestedSellingPrice']??null);
    $type=strtolower((string)($room['rateType']??''));
    if($type!==''&&$type!=='standard')return null;
    foreach(($room['rates']??[]) as $rate){$type=strtolower((string)($rate['rateType']??''));
        if(($type!==''&&$type!=='standard')||!empty($rate['requiresLoyaltyMembership']))return null;}
    if($retail===null)return null;
    return max($retail,$ssp??$retail);
}
function onbPremiumImage($url,string $alt,string $class=''): void {
    if(is_string($url)&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https')echo '<img class="'.h($class).'" src="'.h($url).'" alt="'.h($alt).'" loading="lazy" decoding="async">';
}
function onbPremiumLookupField(string $name,string $label,string $kind,string $placeholder): void {
    $id=p($name);$display=p($name.'_label')?:$id;
    echo '<label class="onb-lookup"><span>'.h($label).'</span><input type="text" data-lookup="'.h($kind).'" name="'.h($name).'_label" value="'.h($display).'" placeholder="'.h($placeholder).'" autocomplete="off" aria-autocomplete="list" aria-expanded="false" required><input type="hidden" name="'.h($name).'" value="'.h($id).'"><span class="onb-suggestions" hidden></span></label>';
}
function onbPremiumSearchForm(string $kind): void {
    $hotel=$kind==='hotels';$today=(new DateTimeImmutable('today'))->format('Y-m-d');
    echo '<form class="onb-travel-search" method="post" action="?page='.h($kind).'" data-travel-search>'.token().'<input type="hidden" name="action" value="nuitee_search">';
    if(!$hotel){
        echo '<div class="onb-trip-type"><label><input type="radio" name="trip_type" value="round" '.(p('trip_type')!=='one'?'checked':'').'> Round trip</label><label><input type="radio" name="trip_type" value="one" '.(p('trip_type')==='one'?'checked':'').'> One way</label></div>';
    }
    echo '<div class="onb-travel-fields">';
    if($hotel){onbPremiumLookupField('place_id','Where are you staying?','hotels','City, landmark or property');echo '<label>Guest nationality (ISO country code)<input name="nationality" maxlength="2" value="'.h(p('nationality')?:'CA').'" required></label>';}
    else{onbPremiumLookupField('origin','From','flights','City or airport');onbPremiumLookupField('destination','To','flights','City or airport');}
    echo '<label>'.($hotel?'Check-in':'Departure').'<input type="date" name="start" required min="'.h($today).'" value="'.h(p('start')).'"></label>';
    echo '<label data-return-field>'.($hotel?'Check-out':'Return').'<input type="date" name="end" '.($hotel||p('trip_type')!=='one'?'required ':'').'min="'.h($today).'" value="'.h(p('end')).'"></label>';
    echo '<label>Adults<input type="number" name="adults" required min="1" max="9" value="'.h(p('adults')?:'2').'"></label>';
    if($hotel)echo '<label>Rooms<input type="number" name="rooms" required min="1" max="5" value="'.h(p('rooms')?:'1').'"></label>';
    else{echo '<label>Cabin<select name="cabin">';foreach(['ECONOMY'=>'Economy','PREMIUM_ECONOMY'=>'Premium economy','BUSINESS'=>'Business','FIRST'=>'First'] as $k=>$v)echo '<option value="'.$k.'" '.(p('cabin')===$k?'selected':'').'>'.$v.'</option>';echo '</select></label>';}
    echo '</div><div class="onb-search-bottom"><span>CAD · Adult travellers · Live supplier availability</span><button type="submit">Search '.($hotel?'stays':'flights').' <span aria-hidden="true">→</span></button></div><p class="onb-search-status" role="status" hidden>Searching available offers. This may take a moment.</p></form>';
}
function onbPremiumHero(string $kind): void {
    $hotel=$kind==='hotels';
    echo '<section class="onb-travel-hero '.($hotel?'onb-hero-hotels':'onb-hero-flights').'"><div class="onb-hero-copy"><span class="onb-kicker">OUR NEXT BOARDING / DISCOVER THE WORLD</span><h1>'.($hotel?'Stay somewhere<br>extraordinary.':'The world is<br>within reach.').'</h1><p>'.($hotel?'Find a stay that feels like part of the journey.':'Find your next departure, on your terms.').'</p><nav class="onb-travel-tabs" aria-label="Travel products"><a '.(!$hotel?'aria-current="page" ':'').'href="?page=flights">✈ Flights</a><a '.($hotel?'aria-current="page" ':'').'href="?page=hotels">▤ Hotels</a></nav></div></section>';
}
function onbPremiumFilters(string $kind): void {
    echo '<aside class="onb-filter-panel"><details open><summary>Refine results</summary><div class="onb-filter-controls"><label>Sort by<select data-sort><option value="price">Lowest price</option>'.($kind==='flights'?'<option value="duration">Shortest known duration</option>':'').'</select></label><label>Maximum total (CAD)<input type="number" min="0" step="1" data-max-price placeholder="Any price"></label>';
    if($kind==='flights')echo '<label>Airline<input data-airline placeholder="Any airline"></label><label class="onb-check"><input type="checkbox" data-nonstop> Nonstop itineraries only</label>';
    else echo '<label>Hotel name<input data-airline placeholder="Any hotel"></label><label class="onb-check"><input type="checkbox" data-refundable> Refundable rates only</label>';
    echo '<button class="onb-reset" type="button" data-reset>Clear filters</button></div></details></aside>';
}
function onbPremiumFlightLegs(array $journey): array {
    $legs=[];foreach(($journey['segments']??[]) as $seg){if(!is_array($seg)||empty($seg['originCode'])||empty($seg['destinationCode']))continue;$direction=(string)($seg['direction']??'OUTBOUND');$legs[$direction][]=$seg;}return $legs;
}
function onbPremiumFlightTimeline(array $legs): void {
    foreach($legs as $direction=>$segments){
        $first=$segments[0];$last=$segments[count($segments)-1];$connections=max(0,count($segments)-1);$technical=0;$duration=0;$known=true;
        foreach($segments as $seg){$stops=$seg['technicalStops']??null;if(is_array($stops))$technical+=count($stops);elseif(is_numeric($stops))$technical+=(int)$stops;$d=onbPremiumDuration($seg['duration']??null);if($d===null)$known=false;else $duration+=$d;}
        echo '<div class="onb-leg"><div class="onb-leg-label">'.h($direction==='INBOUND'?'Return':'Outbound').'</div><div class="onb-timeline"><div><strong>'.h(onbPremiumTime($first['departureTime']??null)).'</strong><b>'.h($first['originCode']).'</b><small>'.h(onbPremiumDateLabel($first['departureTime']??null)).'</small></div><div class="onb-route"><small>'.h($connections+$technical===0?'Nonstop reported':($connections+$technical).' stop(s)').'</small><span></span><small>'.h($known?onbPremiumDurationText($duration).' in air':'').'</small></div><div><strong>'.h(onbPremiumTime($last['arrivalTime']??null)).'</strong><b>'.h($last['destinationCode']).'</b><small>'.h(onbPremiumDateLabel($last['arrivalTime']??null)).'</small></div></div></div>';
    }
}
function onbPremiumResults(string $kind,array $search): void {
    $r=$search['response'];$summary=$search['summary'];$count=0;
    echo '<section class="onb-results-head"><span class="onb-kicker">CURATED BY YOUR SEARCH</span><h2>Explore available '.($kind==='hotels'?'stays':'flights').'</h2><p>'.h($summary).'</p><small>Supplier estimates · Confirm final price, availability and terms before payment.</small></section><div class="onb-results-layout">';
    onbPremiumFilters($kind);echo '<div class="onb-results-main"><p role="status" data-result-count></p><div class="onb-travel-results" data-results>';
    if($kind==='hotels'){
        $_SESSION['onb_hotel_offers']=[];$hotels=[];
        foreach(($r['hotels']??[]) as $hotel)if(is_array($hotel)&&isset($hotel['id']))$hotels[$hotel['id']]=$hotel;
        $grouped=[];foreach(($r['data']??[]) as $entry){if(!is_array($entry)||!isset($entry['hotelId']))continue;$id=(string)$entry['hotelId'];if(!isset($grouped[$id]))$grouped[$id]=$entry;else $grouped[$id]['roomTypes']=array_merge($grouped[$id]['roomTypes']??[],$entry['roomTypes']??[]);}
        foreach($grouped as $entry){
            $hotel=$hotels[$entry['hotelId']]??[];$name=(string)($hotel['name']??$entry['hotelName']??'Hotel '.$entry['hotelId']);$offers=[];
            foreach(($entry['roomTypes']??[]) as $room){if(!is_array($room)||empty($room['rates']))continue;$price=onbPremiumHotelPrice($room);if($price!==null)$offers[]=['room'=>$room,'price'=>$price];}
            if(!$offers)continue;usort($offers,fn($a,$b)=>$a['price']<=>$b['price']);$min=$offers[0]['price'];$ref=false;
            foreach($offers as $of)if(($of['room']['rates'][0]['cancellationPolicies']['refundableTag']??'')==='RFN')$ref=true;
            echo '<article class="onb-travel-result onb-hotel-result" data-result data-price="'.h($min).'" data-name="'.h($name).'" data-refundable="'.($ref?'1':'0').'"><div class="onb-property-image">';
            onbPremiumImage($hotel['main_photo']??null,$name);echo '</div><div class="onb-property-content"><div class="onb-property-heading"><div><span class="onb-kicker">STAY / '.h($hotel['city']??'').'</span><h3>'.h($name).'</h3><p>'.h($hotel['address']??'').'</p></div><div class="onb-price"><small>Stay from</small><strong>'.h(onbTravelMoney($min,'CAD')).'</strong></div></div>';
            if(is_numeric($hotel['starRating']??null))echo '<span class="onb-chip">'.h($hotel['starRating']).' star category</span>';
            if(is_numeric($hotel['rating']??null))echo '<span class="onb-chip">Guest rating '.h($hotel['rating']).'</span>';
            $nights=(new DateTimeImmutable($search['start']))->diff(new DateTimeImmutable($search['end']))->days;
            foreach(array_slice($offers,0,10) as $of){
                $room=$of['room'];$rate=$room['rates'][0];$price=$of['price'];$money=onbTravelMoney($price,'CAD');
                echo '<div class="onb-room-offer" data-room-price="'.h($price).'" data-room-refundable="'.(($rate['cancellationPolicies']['refundableTag']??'')==='RFN'?'1':'0').'"><div><h4>'.h($rate['name']??'Room offer').'</h4><p>'.h($rate['boardName']??'Meal plan not supplied').'</p><details class="onb-rate-details"><summary>Room, taxes &amp; policies</summary>';onbHotelRateDetails($room['rates']);echo '</details></div><div class="onb-room-actions"><strong>'.h($money).'</strong><small>'.h($nights?onbTravelMoney($price/$nights,'CAD').' / night, all selected rooms':'').'</small>';onbHotelOfferButton($room,$hotel,$entry,$search);echo '</div></div>';
            }
            echo '</div></article>';if(++$count>=30)break;
        }
    }else{
        $_SESSION['onb_flight_offers']=[];$items=[];
        foreach(($r['data']??[]) as $batch)foreach(($batch['journeys']??[]) as $journey){
            $legs=onbPremiumFlightLegs($journey);if(!$legs)continue;$offers=$journey['offers']??[];if(!$offers&&!empty($journey['cheapestOffer']))$offers=[$journey['cheapestOffer']];
            foreach($offers as $offer){if(!is_array($offer))continue;$p=$offer['pricing']['display']??[];if(($p['currency']??'')!=='CAD'||!is_numeric($p['total']??null)||(float)$p['total']<=0||!is_finite((float)$p['total']))continue;$items[]=['price'=>(float)$p['total'],'journey'=>$journey,'offer'=>$offer,'legs'=>$legs];}
        }
        usort($items,fn($a,$b)=>$a['price']<=>$b['price']);
        foreach(array_slice($items,0,100) as $it){
            $legs=$it['legs'];$j=$it['journey'];$o=$it['offer'];$first=reset($legs)[0];$airline=(string)($first['carrier']['marketingName']??$first['carrier']['marketingCode']??'Airline not supplied');
            $stops=0;$names=[];$duration=onbPremiumDuration($j['duration']??null);
            foreach($legs as $segments){$stops+=max(0,count($segments)-1);foreach($segments as $seg){$t=$seg['technicalStops']??null;$stops+=is_array($t)?count($t):(is_numeric($t)?(int)$t:0);$names[]=(string)($seg['carrier']['marketingName']??'');}}
            echo '<article class="onb-travel-result onb-flight-result" data-result data-price="'.h($it['price']).'" data-duration="'.h($duration??'').'" data-name="'.h(implode(' ',$names)).'" data-stops="'.h($stops).'"><div class="onb-airline">';onbPremiumImage($first['carrier']['marketingLogo']??null,$airline,'onb-airline-logo');echo '<div><b>'.h($airline).'</b><small>'.h($o['fare']['family']??$o['cabinClass']??'Flight offer').'</small></div></div><div class="onb-flight-itinerary">';onbPremiumFlightTimeline($legs);echo '<details class="onb-rate-details"><summary>View flight details &amp; fare rules</summary>';onbFlightSearchDetails($j,$o);echo '</details></div><div class="onb-flight-actions"><div class="onb-price"><small>Total for '.(int)$search['adults'].' adult(s)</small><strong>'.h(onbTravelMoney($it['price'],'CAD')).'</strong></div>';
            onbFlightOfferButton($o,$summary,(int)$search['adults']);
            onbTravelQuote("Flight enquiry\n".$summary."\n".$airline."\n".onbTravelMoney($it['price'],'CAD'),'Request quote');echo '</div></article>';$count++;
        }
    }
    echo '</div><p data-empty-results hidden>No offers match these filters.</p>';
    if(!$count)echo '<p class="notice">No eligible CAD offers returned. Try different dates or request a quote.</p>';
    echo '</div></div>';
}
function onbCheckoutProgress(array $s): void {
    $state=$s['state']??'';$step=$state==='CONFIRMED'?5:(!empty($s['checkout_id'])?4:2);
    echo '<ol class="onb-checkout-steps" aria-label="Booking progress">';
    foreach(['Review','Traveller details','Verify price','Payment','Confirmation'] as $i=>$label)echo '<li '.($i+1===$step?'aria-current="step"':'').' class="'.($i+1<$step?'done':'').'"><span>'.($i+1<$step?'✓':$i+1).'</span>'.h($label).'</li>';
    echo '</ol>';
}

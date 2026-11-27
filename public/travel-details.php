<?php
declare(strict_types=1);

/* Send one confirmation attempt per confirmed supplier booking. Never send a
   real confirmation for sandbox, pending, failed, or merely authorized payments. */
function onbTravelConfirmation(string $kind, array $s, array $booking): void {
    global $c;
    if (($booking['status']??'')!=='CONFIRMED' || !is_string($booking['bookingId']??null) || $booking['bookingId']==='') return;
    if (!empty($s['sandbox'])) return;
    $email=(string)($s['contact_email']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)return;
    try {
        q("CREATE TABLE IF NOT EXISTS onb_travel_confirmation_mail (
          product VARCHAR(10) NOT NULL, booking_id VARCHAR(255) NOT NULL,
          recipient VARCHAR(254) NOT NULL, state VARCHAR(20) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(product,booking_id)
        ) ENGINE=InnoDB");
        $id=substr($booking['bookingId'],0,255);
        if(!q("INSERT IGNORE INTO onb_travel_confirmation_mail(product,booking_id,recipient,state) VALUES(?,?,?,'SENDING')",[$kind,$id,$email])->rowCount())return;
        $body="Your ".($kind==='hotel'?'hotel reservation':'flight reservation')." has been confirmed by our booking supplier.\n\n";
        $body.="Booking ID: ".$id."\n";
        if($kind==='hotel'){
            $body.="Hotel: ".($booking['hotel']['name']??$s['name']??'')."\n";
            $body.="Check-in: ".($booking['checkin']??$s['start']??'')."\nCheck-out: ".($booking['checkout']??$s['end']??'')."\n";
            $body.="Hotel confirmation code: ".(!empty($booking['hotelConfirmationCode'])?$booking['hotelConfirmationCode']:'Awaiting property reference')."\n";
            foreach(($booking['bookedRooms']??[]) as $room){$name=$room['roomType']['name']??$room['roomType']['roomName']??'';if(is_string($name)&&$name!=='')$body.="Room: ".$name."\n";}
            foreach(onbHotelPolicyLines($booking['cancellationPolicies']??[]) as $line)$body.=$line."\n";
        } else {
            $body.="Booking reference: ".($booking['bookingRef']??'')."\n";
            foreach(($booking['airlineLocators']??[]) as $locator){if(!empty($locator['airlinePnr']))$body.="Airline reference: ".($locator['airlineCode']??'').' '.$locator['airlinePnr']."\n";}
            foreach(($s['verified']['segments']??[]) as $seg)$body.=onbFlightSegmentLine($seg)."\n";
            foreach(onbFlightBagLines($s['verified']['baggage']??[]) as $line)$body.=$line."\n";
            $body.="Airline ticket issuance is separate from reservation confirmation. Contact us if your ticket number is not yet available.\n";
        }
        $body.="\nTrip: ".($s['summary']??'')."\nCheckout total: ".onbTravelMoney($s['payment_price']??null,'CAD')."\n\nFor assistance or cancellation requests, contact info@ournextboarding.com. Keep this email for your records.\nOur Next Boarding\nhttps://ournextboarding.com/";
        $ok=onbMail($c,$email,($kind==='hotel'?'Hotel':'Flight').' reservation confirmed | Our Next Boarding',$body,'info@ournextboarding.com');
        q('UPDATE onb_travel_confirmation_mail SET state=? WHERE product=? AND booking_id=?',[$ok?'SENT':'FAILED',$kind,$id]);
        if(!$ok)error_log('Travel confirmation email failed: '.$kind.' '.$id);
    } catch(Throwable $e) {error_log('Travel confirmation email could not be processed: '.$kind);}
}
function onbFlightSegmentLine(array $seg): string {
    $carrier=$seg['carrier']['marketingName']??$seg['carrier']['marketingCode']??'';
    $flight=$seg['flightNumber']??$seg['marketingFlightNumber']??'';
    return trim(($seg['originCode']??'').' → '.($seg['destinationCode']??'').' · '.$carrier.' '.$flight.' · '.($seg['departureTime']??'').' → '.($seg['arrivalTime']??''));
}
function onbFlightBagLines(array $baggage): array {
    $out=[];
    foreach(($baggage['included']??[]) as $bag)if(is_array($bag))$out[]=($bag['bagType']??'Bag').' · '.($bag['pieces']??'?').' piece(s)'.(isset($bag['weightKg'])?' · '.$bag['weightKg'].' kg':'');
    return $out;
}
function onbHotelPolicyLines(array $policy): array {
    $out=[];
    if(isset($policy['refundableTag']))$out[]=$policy['refundableTag']==='NRFN'?'Non-refundable or partly non-refundable':($policy['refundableTag']==='RFN'?'Refundable subject to the deadlines below':'Refundability: '.$policy['refundableTag']);
    foreach(($policy['cancelPolicyInfos']??[]) as $rule)if(is_array($rule)){
        $penalty=($rule['type']??'')==='amount'?onbTravelMoney($rule['amount']??null,$rule['currency']??''):(string)($rule['amount']??'').' '.($rule['type']??'');
        $out[]='Cancellation charge from '.($rule['cancelTime']??'unspecified time').' '.($rule['timezone']??'').': '.$penalty;
    }
    foreach(($policy['hotelRemarks']??[]) as $remark)if(is_string($remark))$out[]=$remark;
    return $out;
}
function onbHotelRateDetails(array $rates): void {
    foreach($rates as $i=>$rate){
        if(!is_array($rate))continue;
        echo '<h3>'.h($rate['name']??('Room '.($i+1))).'</h3><p>'.h($rate['boardName']??'Meal plan not stated').'</p>';
        $lines=onbHotelPolicyLines(is_array($rate['cancellationPolicies']??null)?$rate['cancellationPolicies']:[]);
        echo '<h4>Cancellation policy</h4>';
        if(!$lines)echo '<p>Supplier has not provided cancellation details.</p>';
        foreach($lines as $line)echo '<p>'.h($line).'</p>';
        echo '<h4>Taxes and property fees</h4>';
        $fees=$rate['retailRate']['taxesAndFees']??[];
        if(!$fees)echo '<p>No separate fee breakdown supplied. Confirm at checkout.</p>';
        foreach($fees as $fee)if(is_array($fee))echo '<p>'.h(($fee['description']??'Fee').' — '.onbTravelMoney($fee['amount']??null,$fee['currency']??'').' ('.(($fee['included']??null)===true?'included':(($fee['included']??null)===false?'not included / payable separately':'inclusion not stated')).')').'</p>';
        if(is_string($rate['remarks']??null)&&$rate['remarks']!=='')echo '<p>'.h($rate['remarks']).'</p>';
    }
}
function onbHotelMetadata(string $id): array {
    if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id))throw new RuntimeException('Invalid hotel.');
    $s=onbTravelSettings();
    $curl=curl_init('https://api.liteapi.travel/v3.0/data/hotel?'.http_build_query(['hotelId'=>$id]));
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-Key: '.$s['key']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $d=is_string($raw)?json_decode($raw,true):null;
    if($status!==200||!is_array($d['data']??null))throw new RuntimeException('Full property details are temporarily unavailable.');
    return $d['data'];
}
function onbHotelDetailView(array $s): void {
    $hotel=$s['hotel']??[];
    try{$hotel=array_replace($hotel,onbHotelMetadata($s['hotel_id']));}catch(RuntimeException $e){echo '<p class="notice">'.h($e->getMessage()).' Showing search details instead.</p>';}
    echo '<section><span class="eyebrow">PROPERTY DETAILS</span><h2>'.h($hotel['name']??$s['name']).'</h2><p>'.h($hotel['address']??'').'</p>';
    if(!empty($hotel['starRating']))echo '<p>Hotel category: '.h($hotel['starRating']).' stars</p>';
    if(is_string($hotel['hotelDescription']??null))echo '<p>'.h(strip_tags($hotel['hotelDescription'])).'</p>';
    elseif(is_string($hotel['description']??null))echo '<p>'.h(strip_tags($hotel['description'])).'</p>';
    $photos=$hotel['hotelImages']??[];
    if(!$photos&&!empty($hotel['main_photo']))$photos=[['url'=>$hotel['main_photo']]];
    echo '<div class="onb-detail-gallery">';
    foreach(array_slice(is_array($photos)?$photos:[],0,12) as $photo){$url=$photo['url']??'';if(is_string($url)&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https')echo '<a href="'.h($url).'" target="_blank" rel="noopener noreferrer"><img loading="lazy" src="'.h($url).'" alt="'.h($photo['caption']??'Hotel photo').'"></a>';}
    echo '</div><h3>Facilities</h3><ul class="onb-detail-facilities">';
    foreach(array_slice($hotel['hotelFacilities']??[],0,60) as $facility)if(is_string($facility))echo '<li>'.h($facility).'</li>';
    echo '</ul>';
    foreach(['checkin'=>'Check-in','checkout'=>'Check-out'] as $key=>$label)if(is_string($hotel['checkinCheckoutTimes'][$key]??null))echo '<p>'.h($label.': '.$hotel['checkinCheckoutTimes'][$key]).'</p>';
    echo '</section>';
}

function onbFlightSearchDetails(array $journey,array $offer): void {
    echo '<h3>Flight schedule</h3>';
    $counts=[];
    foreach(($journey['segments']??[]) as $seg){
        if(!is_array($seg))continue;
        $direction=(string)($seg['direction']??'');$counts[$direction]=($counts[$direction]??0)+1;
        echo '<p>'.h(onbFlightSegmentLine($seg)).'</p>';
        foreach(['duration'=>'Duration','operatingCarrierName'=>'Operated by','aircraft'=>'Aircraft'] as $key=>$label)if(is_scalar($seg[$key]??null))echo '<p class="muted">'.h($label.': '.$seg[$key]).'</p>';
    }
    foreach($counts as $direction=>$count)echo '<p>'.h(ucfirst(strtolower($direction)).': '.max(0,$count-1).' connection(s)').'</p>';
    $bags=$offer['baggage']??$journey['baggage']??[];$lines=onbFlightBagLines(is_array($bags)?$bags:[]);
    echo '<h3>Baggage</h3>';if(!$lines)echo '<p>Allowance not supplied in the search response. Verify before payment.</p>';
    foreach($lines as $line)echo '<p>'.h($line).'</p>';
    $terms=$offer['terms']??$journey['terms']??[];
    echo '<h3>Fare rules</h3>';
    foreach(['refundable'=>'Refundable','changeable'=>'Changeable'] as $key=>$label){$v=$terms[$key]??null;echo '<p>'.h($label.': '.($v===true?'Yes, subject to conditions':($v===false?'No':'Not stated'))).'</p>';}
    foreach(($terms['summary']??[]) as $term)if(is_string($term['message']??null))echo '<p>'.h($term['message']).'</p>';
    echo '<p class="muted">All times are supplier-reported; the complete fare is reverified before checkout.</p>';
}

<?php
declare(strict_types=1);

/* Direct flight checkout. No real payment unless explicitly enabled after testing. */
function onbFlightPaymentAllowed(bool $admin): bool {
    global $c;
    $s=onbTravelSettings();
    return onbTravelCanSearch('flights',$admin) && ($s['sandbox'] ? $admin : (($c['nuitee']['direct_booking_enabled']??false)===true));
}
function onbFlightOfferButton(array $offer, string $summary, int $adults): void {
    global $u;
    $id=$offer['offerId']??null;
    if(!is_string($id)||$id===''||strlen($id)>32768||$adults<1||$adults>9) return;
    if(!$u){echo '<p><a class="btn" href="?page=login">Sign in to book</a></p>';return;}
    $token=bin2hex(random_bytes(24));
    $_SESSION['onb_flight_offers'][$token]=['offerId'=>$id,'summary'=>$summary,'adults'=>$adults,'expires'=>time()+600,'uid'=>(int)$u['id'],'sandbox'=>onbTravelSettings()['sandbox']];
    echo '<form class="onb-direct-select" method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_select"><input type="hidden" name="offer_token" value="'.h($token).'"><button>Book Now</button></form>';
}
function onbFlightCheckoutState(): array {
    global $u;
    $s=$_SESSION['onb_flight_checkout']??[];
    if(!$u||($s['uid']??0)!==(int)$u['id']||($s['sandbox']??null)!==onbTravelSettings()['sandbox']) throw new RuntimeException('Your checkout session has ended. Search again.');
    return $s;
}
function onbFlightVerify(array $s): array {
    if(($s['expires']??0)<time()) throw new RuntimeException('This flight offer has expired. Search again.');
    $res=onbTravelRequest('/flights/verify',['offerId'=>$s['offerId']]);
    $j=$res['data'][0]['journey']??null;
    if(!is_array($j)) throw new RuntimeException('The selected fare could not be verified. Search again.');
    $price=$j['pricing']['display']??[];
    if(!is_numeric($price['total']??null)||!is_finite((float)$price['total'])||(float)$price['total']<=0||($price['currency']??'')!=='CAD') throw new RuntimeException('A valid CAD fare was not returned. Search again.');
    if(isset($j['passengers']['adults'])&&(int)$j['passengers']['adults']!==$s['adults']) throw new RuntimeException('Passenger count changed. Search again.');
    $s['verified']=$j;$s['price']=round((float)$price['total'],2);
    if(!empty($j['expiration'])&&is_string($j['expiration'])){
        $expiry=strtotime($j['expiration']);if($expiry!==false)$s['expires']=min($s['expires'],$expiry);
    }
    if($s['expires']<time())throw new RuntimeException('This flight offer has expired. Search again.');
    return $s;
}
function onbFlightCheckoutTable(): void {
    q("CREATE TABLE IF NOT EXISTS onb_flight_checkouts (
      checkout_id CHAR(48) PRIMARY KEY, user_id INT NOT NULL, sandbox TINYINT NOT NULL,
      prebook_id VARCHAR(255) NOT NULL, transaction_id VARCHAR(255) NOT NULL,
      amount DECIMAL(12,2) NOT NULL, currency CHAR(3) NOT NULL,
      state VARCHAR(40) NOT NULL, booking_id VARCHAR(255) NULL, booking_ref VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(user_id), UNIQUE KEY(prebook_id)
    ) ENGINE=InnoDB");
}
function onbFlightInput(string $key, string $pattern, string $label): string {
    $v=p($key);
    if(!preg_match($pattern,$v))throw new RuntimeException('Check '.$label.'.');
    return $v;
}
function onbFlightPersonDate(string $key): DateTimeImmutable {
    $v=p($key);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Check the passenger dates.');
    return $d;
}
function onbFlightCheckoutAction(string $action, bool $admin): void {
    global $u,$c;
    if(!$u||!onbTravelCanSearch('flights',$admin))throw new RuntimeException('Sign in to access flight checkout.');
    if($action==='nuitee_select'){
        $prior=$_SESSION['onb_flight_checkout']??[];
        if(($prior['uid']??0)===(int)$u['id']&&($prior['sandbox']??null)===onbTravelSettings()['sandbox']&&!empty($prior['checkout_id'])&&($prior['state']??'')!=='CONFIRMED'){
            throw new RuntimeException('You have an existing checkout. Open the checkout page to finish or contact support before starting another.');
        }
        $id=p('offer_token');
        $s=$_SESSION['onb_flight_offers'][$id]??[];
        if(($s['uid']??0)!==(int)$u['id']||($s['sandbox']??null)!==onbTravelSettings()['sandbox'])throw new RuntimeException('Select a fresh flight offer.');
        $s=onbFlightVerify($s);
        $_SESSION['onb_flight_checkout']=$s;
        go('page=flight_checkout');
    }
    $s=onbFlightCheckoutState();
    if(!onbFlightPaymentAllowed($admin))throw new RuntimeException('Direct payments are not enabled. No payment has been taken.');
    if($action==='nuitee_prebook'){
        if(!empty($s['checkout_id']))throw new RuntimeException('A checkout already exists. Do not create another payment.');
        if(p('accept_fare')!=='1')throw new RuntimeException('Review and accept the fare and booking terms first.');
        $priorPrice=$s['price'];
        $s=onbFlightVerify($s);
        $_SESSION['onb_flight_checkout']=$s;
        if($s['price']!==$priorPrice||!empty($s['verified']['changes']))throw new RuntimeException('The fare has changed. Review the updated fare and try again.');
        $namePattern='/^[\p{L}\p{M} .\'-]{1,80}$/uD';
        $email=p('contact_email');
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid contact email.');
        $contact=['firstName'=>onbFlightInput('contact_first',$namePattern,'contact first name'),'lastName'=>onbFlightInput('contact_last',$namePattern,'contact last name'),'email'=>$email,'phoneCountryCode'=>onbFlightInput('phone_code','/^[0-9]{1,4}$/D','phone country code'),'phoneNumber'=>onbFlightInput('phone_number','/^[0-9]{5,15}$/D','phone number')];
        $passengers=[];
        for($i=0;$i<$s['adults'];$i++){
            $prefix='p'.$i.'_';
            $birth=onbFlightPersonDate($prefix.'birth');$expiry=onbFlightPersonDate($prefix.'expiry');
            if($birth>new DateTimeImmutable('-18 years')||$expiry<=new DateTimeImmutable('today'))throw new RuntimeException('Only adult travellers with unexpired passports are supported by this checkout.');
            $passengers[]=['passengerType'=>0,'firstName'=>onbFlightInput($prefix.'first',$namePattern,'passenger first name'),'lastName'=>onbFlightInput($prefix.'last',$namePattern,'passenger last name'),'birthday'=>$birth->format('Y-m-d'),'gender'=>onbFlightInput($prefix.'gender','/^[MF]$/D','gender'),'nationality'=>onbFlightInput($prefix.'nationality','/^[A-Z]{2}$/D','nationality'),'documentType'=>'passport','documentNumber'=>onbFlightInput($prefix.'passport','/^[A-Z0-9]{4,25}$/D','passport number'),'documentExpiry'=>$expiry->format('Y-m-d'),'documentIssueCountry'=>onbFlightInput($prefix.'issuer','/^[A-Z]{2}$/D','passport issuing country')];
        }
        if($s['sandbox']&&p('test_data')!=='1')throw new RuntimeException('Use fictional passenger details and a Stripe test card in sandbox.');
        onbFlightCheckoutTable();
        $pk=(string)($c['nuitee'][$s['sandbox']?'stripe_test_publishable_key':'stripe_live_publishable_key']??'');
        $expected=$s['sandbox']?'pk_test_':'pk_live_';
        if(!str_starts_with($pk,$expected))throw new RuntimeException('Configure the matching Nuitée Stripe publishable key before starting payment.');
        $res=onbTravelRequest('/flights/prebooks',['offerId'=>$s['offerId'],'usePaymentSdk'=>true,'contact'=>$contact,'passengers'=>$passengers]);
        $data=$res['data'][0]??$res['data']??[];
        $prebook=$data['prebookId']??'';$transaction=$data['transactionId']??'';$secret=$data['secretKey']??'';
        if(!is_string($prebook)||!$prebook||strlen($prebook)>255||!is_string($transaction)||!$transaction||strlen($transaction)>255)throw new RuntimeException('The supplier did not return checkout references. Contact support.');
        $amount=$data['price']??null;$currency=$data['currency']??'';
        if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||$currency!=='CAD')throw new RuntimeException('The supplier did not return a valid CAD payment amount. No payment was taken.');
        $checkout=bin2hex(random_bytes(24));
        q('INSERT INTO onb_flight_checkouts(checkout_id,user_id,sandbox,prebook_id,transaction_id,amount,currency,state) VALUES(?,?,?,?,?,?,?,?)',[$checkout,(int)$u['id'],(int)$s['sandbox'],$prebook,$transaction,$amount,$currency,'PREBOOKED']);
        $s['checkout_id']=$checkout;$s['payment_price']=round((float)$amount,2);$s['state']='PREBOOKED';
        $s['secret']=is_string($secret)&&str_starts_with($secret,'pi_')&&str_contains($secret,'_secret_')?$secret:'';
        $s['publishable']=$pk;
        $_SESSION['onb_flight_checkout']=$s;
        if(!$s['secret'])throw new RuntimeException('Payment form is unavailable. Contact support with the checkout reference. No payment was taken.');
        go('page=flight_checkout');
    }
    if($action==='nuitee_finalize'){
        if(empty($s['checkout_id']))throw new RuntimeException('No checkout to finalize.');
        $record=q('SELECT * FROM onb_flight_checkouts WHERE checkout_id=? AND user_id=? AND sandbox=?',[$s['checkout_id'],(int)$u['id'],(int)$s['sandbox']])->fetch(PDO::FETCH_ASSOC);
        if(!$record)throw new RuntimeException('Checkout record unavailable. Contact support.');
        if($record['state']==='CONFIRMED'){ $s['state']='CONFIRMED';$_SESSION['onb_flight_checkout']=$s;go('page=flight_checkout'); }
        // Supplier validates its own payment transaction. Never accept client-supplied IDs.
        q("UPDATE onb_flight_checkouts SET state='FINALIZING' WHERE checkout_id=?",[$s['checkout_id']]);
        try{
            $res=onbTravelRequest('/flights/bookings',['prebookId'=>$record['prebook_id'],'payment'=>['method'=>'TRANSACTION_ID','transactionId'=>$record['transaction_id']]]);
            $d=$res['data'][0]??$res['data']??[];
            $status=(string)($d['status']??'UNKNOWN');$bookingId=(string)($d['bookingId']??'');$bookingRef=(string)($d['bookingRef']??'');
            if(!in_array($status,['CONFIRMED','PENDING_CONFIRMATION','CREATED','FAILED','CANCELLED'],true))$status='UNKNOWN';
            if($status==='CONFIRMED'&&!$bookingId)$status='UNKNOWN';
            q('UPDATE onb_flight_checkouts SET state=?,booking_id=?,booking_ref=? WHERE checkout_id=?',[$status,substr($bookingId,0,255),substr($bookingRef,0,255),$s['checkout_id']]);
            $s['state']=$status;$s['booking_ref']=$bookingRef;$s['booking_id']=$bookingId;
            $_SESSION['onb_flight_checkout']=$s;
            go('page=flight_checkout');
        }catch(Throwable $e){
            q("UPDATE onb_flight_checkouts SET state='REVIEW_REQUIRED' WHERE checkout_id=?",[$s['checkout_id']]);
            $s['state']='REVIEW_REQUIRED';$_SESSION['onb_flight_checkout']=$s;
            throw new RuntimeException('Booking confirmation is not yet verified. Do not pay again. Retry confirmation or contact support with your checkout reference.');
        }
    }
    throw new RuntimeException('Unknown checkout action.');
}
function onbFlightCheckoutView(bool $admin): void {
    global $u;
    if(!$u){echo '<h1>Sign in to book</h1><a class="btn" href="?page=login">Sign in</a>';return;}
    try{$s=onbFlightCheckoutState();}catch(RuntimeException $e){echo '<h1>Flight checkout</h1><p>'.h($e->getMessage()).'</p><a class="btn" href="?page=flights">Search flights</a>';return;}
    echo '<span class="eyebrow">OUR NEXT BOARDING / FLIGHT CHECKOUT</span><h1>Review your flight</h1>';
    if($s['sandbox'])echo '<p class="notice">Sandbox test only. Use fictional passenger information and Stripe test cards. No real flight ticket.</p>';
    echo '<section><p>'.h($s['summary']).'</p><ul>';
    foreach(($s['verified']['segments']??[]) as $seg){
        echo '<li>'.h(($seg['originCode']??'').' → '.($seg['destinationCode']??'').' · '.($seg['carrier']['marketingName']??'').' · '.($seg['departureTime']??'').' → '.($seg['arrivalTime']??'')).'</li>';
    }
    echo '</ul><p class="onb-travel-price">'.h(onbTravelMoney($s['price'],'CAD')).' total for '.(int)$s['adults'].' adult(s)</p>';
    echo '<h3>Fare conditions</h3><p>'.h($s['verified']['fare']['family']??'Fare family not stated').'</p>';
    foreach(['refundable'=>'Refundable','changeable'=>'Changeable'] as $key=>$label){$v=$s['verified']['terms'][$key]??null;echo '<p>'.h($label.': '.($v===true?'Yes — conditions may apply':($v===false?'No':'Not stated'))).'</p>';}
    foreach(($s['verified']['terms']['summary']??[]) as $term)if(is_string($term['message']??null))echo '<p>'.h($term['message']).'</p>';
    echo '<h3>Included baggage</h3>';
    $bags=$s['verified']['baggage']['included']??[];
    if(!$bags)echo '<p>Allowance not stated; confirm before paying.</p>';
    foreach($bags as $bag)echo '<p>'.h(($bag['bagType']??'Bag').' · '.($bag['pieces']??'?').' piece(s)'.(isset($bag['weightKg'])?' · '.$bag['weightKg'].' kg':'')).'</p>';
    echo '</section>';
    if(!onbFlightPaymentAllowed($admin)){echo '<p class="notice">Direct payment is not enabled yet. No reservation has been made.</p>';onbTravelQuote("Flight enquiry\n".$s['summary']."\nVerified price CAD ".$s['price']);return;}
    if(!empty($s['checkout_id'])){
        echo '<section><h2>Payment and confirmation</h2><p>Checkout reference: '.h($s['checkout_id']).'</p><p>Final amount: '.h(onbTravelMoney($s['payment_price'],'CAD')).'</p>';
        $state=$s['state']??'PREBOOKED';
        if($state==='CONFIRMED'){echo '<h3>'.($s['sandbox']?'Sandbox booking confirmed':'Flight booking confirmed').'</h3><p>Booking reference: '.h($s['booking_ref']??'').'</p><p>Booking ID: '.h($s['booking_id']??'').'</p><a class="btn" href="?page=flights">Search another flight</a></section>';return;}
        if($state!=='PREBOOKED')echo '<p class="notice">Status: '.h($state).'. Do not make another payment. Contact support if confirmation remains pending.</p>';
        if(!empty($s['secret'])){
            echo '<div id="onb-flight-payment" data-key="'.h($s['publishable']).'" data-secret="'.h($s['secret']).'" data-recover="'.($state==='PREBOOKED'?'0':'1').'">';
            echo '<form id="onb-flight-card-form"><label>Card details</label><div id="onb-flight-card"></div><p id="onb-flight-pay-message" role="status"></p><label><input type="checkbox" id="onb-flight-pay-accept"> I agree to the final total above and the stated fare terms.</label><button type="submit" id="onb-flight-pay-button" disabled>'.($s['sandbox']?'Test payment':'Authorize payment').'</button></form>';
            echo '<form id="onb-flight-finalize" method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_finalize"></form>';
            echo '<button type="button" id="onb-flight-recover" disabled>Check payment / retry booking confirmation</button></div>';
            echo '<script src="https://js.stripe.com/v3/" defer></script><script src="flight-checkout.js?v=1" defer></script>';
        }else echo '<p>Payment form unavailable. Contact support.</p>';
        echo '</section>';return;
    }
    if($s['expires']<time()){echo '<p>Offer expired.</p><a class="btn" href="?page=flights">Search again</a>';return;}
    echo '<h2>Passenger and contact details</h2><p>Use the exact names shown on the passport. Details are submitted securely to the booking supplier; do not send passport data by email.</p>';
    echo '<form method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_prebook"><div class="onb-travel-fields">';
    foreach(['contact_first'=>'Contact first name','contact_last'=>'Contact last name','phone_code'=>'Phone country code (digits)','phone_number'=>'Phone number (digits)'] as $k=>$label)field($k,$label);
    field('contact_email','Contact email',$s['sandbox']?'':(string)$u['email'],'email');
    echo '</div>';
    for($i=0;$i<$s['adults'];$i++){
        $p='p'.$i.'_';echo '<h3>Passenger '.($i+1).'</h3><div class="onb-travel-fields">';
        field($p.'first','First name');field($p.'last','Last name');field($p.'birth','Date of birth','','date');
        echo '<label>Gender as required by airline<select name="'.h($p).'gender" required><option value="">Select</option><option value="M">M</option><option value="F">F</option></select></label>';
        field($p.'nationality','Nationality (2-letter code)');field($p.'passport','Passport number');field($p.'issuer','Issuing country (2-letter code)');field($p.'expiry','Passport expiry','','date');echo '</div>';
    }
    if($s['sandbox'])echo '<label><input type="checkbox" name="test_data" value="1" required> I am using fictional test passenger information.</label>';
    echo '<label><input type="checkbox" name="accept_fare" value="1" required> I reviewed the fare, baggage and cancellation terms and agree to proceed to price confirmation.</label><button>Continue to payment</button></form>';
}

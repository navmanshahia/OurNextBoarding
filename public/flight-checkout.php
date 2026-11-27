<?php
declare(strict_types=1);

/* Direct flight checkout. No real payment unless explicitly enabled after testing. */
function onbFlightPaymentAllowed(bool $admin): bool {
    global $c;
    $s=onbTravelSettings();
    return onbTravelCanSearch('flights',$admin) && ($s['sandbox'] ? $admin : (($c['nuitee']['direct_booking_enabled']??false)===true&&($c['nuitee']['checkout_verified']??false)===true));
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
    $s['fare_hash']=hash('sha256',json_encode([$j['segments']??[],$j['fare']??[],$j['terms']??[],$j['baggage']??[]],JSON_THROW_ON_ERROR));
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
/* Validate only the documented payment fields; never log or display credential values. */
function onbFlightPaymentCredentials(array $data,bool $sandbox): array {
    global $c;
    $expected=$sandbox?'pk_test_':'pk_live_';
    $supplierKey=is_string($data['publishableKey']??null)?trim($data['publishableKey']):'';
    $configuredKey=trim((string)($c['nuitee'][$sandbox?'stripe_test_publishable_key':'stripe_live_publishable_key']??''));
    $key=$supplierKey!==''?$supplierKey:$configuredKey;
    $secret=is_string($data['secretKey']??null)?trim($data['secretKey']):'';
    $issues=[];
    if($key==='')$issues[]='Nuitée did not supply a publishable key and the matching dashboard key is not configured.';
    elseif(!str_starts_with($key,$expected)||preg_match('/\s/',$key))$issues[]='The payment publishable key is invalid or does not match the API environment.';
    if(!str_starts_with($secret,'pi_')||!str_contains($secret,'_secret_')||preg_match('/\s/',$secret))$issues[]='The supplier did not return a usable Stripe PaymentIntent client secret.';
    $types=$data['paymentTypes']??null;
    if(is_array($types)&&$types&&!in_array('TRANSACTION_ID',$types,true))$issues[]='The supplier did not list TRANSACTION_ID as an available payment method.';
    return ['secret'=>$issues?'':$secret,'publishable'=>$issues?'':$key,'payment_issue'=>implode(' ',$issues)];
}
function onbFlightCheckoutAction(string $action, bool $admin): void {
    global $u,$c;
    if(!$u||!onbTravelCanSearch('flights',$admin))throw new RuntimeException('Sign in to access flight checkout.');
    if(in_array($action,['nuitee_select','nuitee_prebook','nuitee_recover_payment'],true)){
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        if((int)q('SELECT COUNT(*) FROM attempts WHERE ip=? AND created_at > NOW()-INTERVAL 15 MINUTE',[$ip])->fetchColumn()>=15)throw new RuntimeException('Too many requests. Try again later.');
        q('INSERT INTO attempts(ip) VALUES(?)',[$ip]);
    }
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
    if($action==='nuitee_recover_payment'){
        if(empty($s['checkout_id'])||($s['state']??'')!=='PREBOOKED')throw new RuntimeException('There is no recoverable checkout. Contact support.');
        $record=q('SELECT * FROM onb_flight_checkouts WHERE checkout_id=? AND user_id=? AND sandbox=? AND state=?',[$s['checkout_id'],(int)$u['id'],(int)$s['sandbox'],'PREBOOKED'])->fetch(PDO::FETCH_ASSOC);
        if(!$record)throw new RuntimeException('Checkout status could not be verified. Do not pay again.');
        $res=onbTravelRequest('/flights/prebooks/'.$record['prebook_id'],[]);
        $data=$res['data'][0]??$res['data']??[];
        if(!is_array($data)||($data['prebookId']??null)!==$record['prebook_id']||($data['transactionId']??null)!==$record['transaction_id']||($data['currency']??'')!==$record['currency']||!is_numeric($data['price']??null)||round((float)$data['price'],2)!==round((float)$record['amount'],2))throw new RuntimeException('The original prebook or payment amount could not be matched. Do not start a new payment; contact support.');
        $s=array_replace($s,onbFlightPaymentCredentials($data,(bool)$s['sandbox']));
        $_SESSION['onb_flight_checkout']=$s;
        if($s['payment_issue']!=='')throw new RuntimeException('Payment form is unavailable. '.($admin?$s['payment_issue'].' ':'').'No new payment was initiated. Contact support if this persists.');
        go('page=flight_checkout');
    }
    if($action==='nuitee_prebook'){
        if(!empty($s['checkout_id']))throw new RuntimeException('A checkout already exists. Do not create another payment.');
        if(p('accept_fare')!=='1')throw new RuntimeException('Review and accept the fare and booking terms first.');
        $priorPrice=$s['price'];$priorFare=$s['fare_hash']??'';
        $s=onbFlightVerify($s);
        $_SESSION['onb_flight_checkout']=$s;
        if($s['price']!==$priorPrice||$s['fare_hash']!==$priorFare)throw new RuntimeException('The fare has changed. Review the updated fare and try again.');
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
        $res=onbTravelRequest('/flights/prebooks',['offerId'=>$s['offerId'],'usePaymentSdk'=>true,'contact'=>$contact,'passengers'=>$passengers]);
        $data=$res['data'][0]??$res['data']??[];
        $prebook=$data['prebookId']??'';$transaction=$data['transactionId']??'';
        if(!is_string($prebook)||!$prebook||strlen($prebook)>255||!is_string($transaction)||!$transaction||strlen($transaction)>255)throw new RuntimeException('The supplier did not return checkout references. Contact support.');
        $amount=$data['price']??null;$currency=$data['currency']??'';
        if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||$currency!=='CAD')throw new RuntimeException('The supplier did not return a valid CAD payment amount. No payment was taken.');
        $checkout=bin2hex(random_bytes(24));
        q('INSERT INTO onb_flight_checkouts(checkout_id,user_id,sandbox,prebook_id,transaction_id,amount,currency,state) VALUES(?,?,?,?,?,?,?,?)',[$checkout,(int)$u['id'],(int)$s['sandbox'],$prebook,$transaction,$amount,$currency,'PREBOOKED']);
        $s['checkout_id']=$checkout;$s['payment_price']=round((float)$amount,2);$s['contact_email']=$email;$s['state']='PREBOOKED';
        $s=array_replace($s,onbFlightPaymentCredentials($data,(bool)$s['sandbox']));
        $_SESSION['onb_flight_checkout']=$s;
        if($s['payment_issue']!=='')throw new RuntimeException('Payment form is unavailable. '.($admin?$s['payment_issue'].' ':'').'No payment was initiated by this page. Use Recover secure payment below.');
        go('page=flight_checkout');
    }
    if($action==='nuitee_finalize'){
        if(empty($s['checkout_id']))throw new RuntimeException('No checkout to finalize.');
        $record=q('SELECT * FROM onb_flight_checkouts WHERE checkout_id=? AND user_id=? AND sandbox=?',[$s['checkout_id'],(int)$u['id'],(int)$s['sandbox']])->fetch(PDO::FETCH_ASSOC);
        if(!$record)throw new RuntimeException('Checkout record unavailable. Contact support.');
        if($record['state']==='CONFIRMED'){ $s['state']='CONFIRMED';$_SESSION['onb_flight_checkout']=$s;go('page=flight_checkout'); }
        // Supplier validates its own payment transaction. Never accept client-supplied IDs.
        if(!q("UPDATE onb_flight_checkouts SET state='FINALIZING' WHERE checkout_id=? AND state='PREBOOKED'",[$s['checkout_id']])->rowCount())throw new RuntimeException('Booking requires review. Do not make another payment. Contact support with the checkout reference.');
        try{
            $res=onbTravelRequest('/flights/bookings',['prebookId'=>$record['prebook_id'],'payment'=>['method'=>'TRANSACTION_ID','transactionId'=>$record['transaction_id']]]);
            $d=$res['data'][0]??$res['data']??[];
            $status=(string)($d['status']??'UNKNOWN');$bookingId=(string)($d['bookingId']??'');$bookingRef=(string)($d['bookingRef']??'');
            if(!in_array($status,['CONFIRMED','PENDING_CONFIRMATION','CREATED','FAILED','CANCELLED'],true))$status='UNKNOWN';
            if($status==='CONFIRMED'&&!$bookingId)$status='UNKNOWN';
            q('UPDATE onb_flight_checkouts SET state=?,booking_id=?,booking_ref=? WHERE checkout_id=?',[$status,substr($bookingId,0,255),substr($bookingRef,0,255),$s['checkout_id']]);
            $s['state']=$status;$s['booking_ref']=$bookingRef;$s['booking_id']=$bookingId;
            $s['airline_locators']=[];
            foreach(($d['airlineLocators']??[]) as $locator)if(is_string($locator['airlinePnr']??null)&&$locator['airlinePnr']!=='')$s['airline_locators'][]=substr((string)($locator['airlineCode']??'').' '.$locator['airlinePnr'],0,100);
            if($status==='CONFIRMED')unset($s['secret'],$s['publishable']);
            $_SESSION['onb_flight_checkout']=$s;
            if($status==='CONFIRMED')onbTravelConfirmation('flight',$s,$d);
            go('page=flight_checkout');
        }catch(Throwable $e){
            q("UPDATE onb_flight_checkouts SET state='REVIEW_REQUIRED' WHERE checkout_id=? AND state='FINALIZING'",[$s['checkout_id']]);
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
    $allowed=onbFlightPaymentAllowed($admin);onbCheckoutHeader('flight',$s,$allowed);
    if($s['sandbox'])echo '<p class="notice">Sandbox test only. Use fictional passenger information and Stripe test cards. No real flight ticket.</p>';
    echo '<div class="onb-checkout-layout onb-refined-checkout"><div class="onb-checkout-primary">';
    try{
    echo '<section class="onb-checkout-card onb-itinerary-card">';
    onbCheckoutSectionTitle('01','Your flight','Flight times are local to each airport.');
    onbPremiumFlightTimeline(onbPremiumFlightLegs($s['verified']),$s['verified']);
    echo '<details class="onb-rate-details"><summary>Full itinerary and fare conditions</summary>';onbFlightSearchDetails($s['verified'],$s['verified']);echo '</details></section>';
    if(($s['state']??'')!=='CONFIRMED'&&!$allowed){onbCheckoutUnavailable('flight',$s,$admin);return;}
    if(!empty($s['checkout_id'])){
        echo '<section class="onb-checkout-card">';onbCheckoutSectionTitle('03','Secure payment','Review the final amount before authorizing your payment.');echo '<p class="onb-ref">Checkout reference: '.h($s['checkout_id']).'</p><p class="onb-payment-amount">'.h(onbTravelMoney($s['payment_price'],'CAD')).'</p>';
        $state=$s['state']??'PREBOOKED';
        if($state==='CONFIRMED'){echo '<div class="onb-confirmed"><h2>'.($s['sandbox']?'Sandbox booking confirmed':'Flight reservation confirmed').'</h2><p>Ticket issuance is separate from reservation confirmation.</p><p>Booking reference: '.h($s['booking_ref']??'').'</p><p>Booking ID: '.h($s['booking_id']??'').'</p>';foreach(($s['airline_locators']??[]) as $locator)echo '<p>Airline confirmation code: '.h($locator).'</p>';echo '<a class="btn" href="?page=flights">Search another flight</a></div></section>';return;}
        if($state!=='PREBOOKED')echo '<p class="notice">Status: '.h($state).'. Do not make another payment. Contact support if confirmation remains pending.</p>';
        if($state==='PREBOOKED'&&!empty($s['secret'])&&!empty($s['publishable'])){
            echo '<div id="onb-flight-payment" data-key="'.h($s['publishable']).'" data-secret="'.h($s['secret']).'" data-amount="'.h((int)round($s['payment_price']*100)).'">';
            echo '<form id="onb-flight-card-form"><label>Card details</label><div id="onb-flight-card"></div><p id="onb-flight-pay-message" role="status"></p><label><input type="checkbox" id="onb-flight-pay-accept"> I agree to the final total above and the stated fare terms.</label><button type="submit" id="onb-flight-pay-button" disabled>'.($s['sandbox']?'Test payment':'Authorize payment').'</button></form>';
            echo '<form id="onb-flight-finalize" method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_finalize"></form>';
            echo '<button type="button" id="onb-flight-recover" disabled>Check payment / retry booking confirmation</button></div>';
            echo '<script src="https://js.stripe.com/v3/" defer></script><script src="flight-checkout.js?v=3" defer></script>';
        }else{
            echo '<p>Payment form unavailable. Do not create a second booking until this checkout has been reviewed.</p>';
            if($state==='PREBOOKED'){
                if($admin&&!empty($s['payment_issue']))echo '<p class="notice">'.h($s['payment_issue']).'</p>';
                echo '<form method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_recover_payment"><button type="submit">Recover secure payment</button></form><p class="muted">Retrieves the existing supplier prebook without creating another payment intent.</p>';
            }
        }
        echo '</section>';return;
    }
    if($s['expires']<time()){echo '<p>Offer expired.</p><a class="btn" href="?page=flights">Search again</a>';return;}
    echo '<section class="onb-checkout-card onb-traveller-intro">';onbCheckoutSectionTitle('02','Traveller details','Enter names exactly as they appear on travel documents.');echo '</section>';
    echo '<form class="onb-traveller-form" method="post" action="?page=flight_checkout">'.token().'<input type="hidden" name="action" value="nuitee_prebook"><h3>Contact information</h3><div class="onb-travel-fields">';
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
    echo '<label><input type="checkbox" name="accept_fare" value="1" required> I reviewed the fare, baggage and cancellation terms and agree to proceed to price confirmation.</label><div class="onb-continue"><span>Next: confirm the final fare</span><button>Continue to payment →</button></div></form>';
    }finally{
        echo '</div><aside class="onb-checkout-aside onb-checkout-summary"><span class="onb-kicker">BOOKING SUMMARY</span><h2>Your journey</h2><p>'.h($s['summary']).'</p>';
        onbCheckoutPrice($s,'flight');
        $terms=$s['verified']['terms']??[];$bags=onbFlightBagLines($s['verified']['baggage']??[]);
        echo '<div class="onb-summary-rules"><h3>Fare &amp; baggage</h3>';
        foreach(['refundable'=>'Refundable','changeable'=>'Changes allowed'] as $key=>$label){$value=$terms[$key]??null;echo '<p><span>'.h($label).'</span><strong>'.($value===true?'Yes':($value===false?'No':'Not stated')).'</strong></p>';}
        foreach($bags as $bag)echo '<p>'.h($bag).'</p>';if(!$bags)echo '<p>Baggage allowance not supplied.</p>';
        echo '</div><p class="onb-secure-note">Your booking is confirmed only after supplier confirmation.</p></aside></div>';
    }
}

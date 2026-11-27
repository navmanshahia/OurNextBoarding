<?php
declare(strict_types=1);
function onbHotelPaymentAllowed(bool $admin): bool {
    global $c;
    $s=onbTravelSettings();
    return onbTravelCanSearch('hotels',$admin)&&($s['sandbox']?$admin:(($c['nuitee']['hotel_direct_booking_enabled']??false)===true&&($c['nuitee']['checkout_verified']??false)===true));
}
function onbHotelOfferButton(array $room,array $hotel,array $entry,array $search): void {
    $offer=$room['offerId']??null;
    if(!is_string($offer)||$offer===''||strlen($offer)>32768)return;
    $id=bin2hex(random_bytes(24));
    $name=(string)($hotel['name']??$entry['hotelName']??'Hotel '.$entry['hotelId']);
    $_SESSION['onb_hotel_offers'][$id]=['offerId'=>$offer,'hotel_id'=>(string)$entry['hotelId'],'hotel'=>$hotel,'name'=>$name,'rates'=>$room['rates']??[],'listing_price'=>onbPremiumHotelPrice($room),'ssp'=>onbPremiumPrice($room['suggestedSellingPrice']??null),'summary'=>$search['summary'],'start'=>$search['start'],'end'=>$search['end'],'rooms'=>$search['rooms'],'adults'=>$search['adults'],'sandbox'=>onbTravelSettings()['sandbox'],'expires'=>time()+600];
    echo '<p><a class="btn" href="?page=hotel_details&amp;offer='.h($id).'">View hotel details</a></p>';
    echo '<form method="post" class="onb-direct-select" action="?page=hotel_checkout">'.token().'<input type="hidden" name="action" value="nuitee_hotel_select"><input type="hidden" name="offer_token" value="'.h($id).'"><button>Book Now</button></form>';
}
function onbHotelOffer(string $id,bool $admin): array {
    if(!onbTravelCanSearch('hotels',$admin)||!preg_match('/^[a-f0-9]{48}$/D',$id))throw new RuntimeException('Please search for a fresh hotel offer.');
    $s=$_SESSION['onb_hotel_offers'][$id]??[];
    if(($s['expires']??0)<time()||($s['sandbox']??null)!==onbTravelSettings()['sandbox'])throw new RuntimeException('This offer has expired. Search again.');
    return $s;
}
function onbHotelDetailsPage(bool $admin): void {
    try{
        $id=(string)($_GET['offer']??'');$s=onbHotelOffer($id,$admin);
        echo '<h1>'.h($s['name']).'</h1><p>'.h($s['summary']).'</p>';
        onbHotelDetailView($s);echo '<section><h2>Selected room and policies</h2>';onbHotelRateDetails($s['rates']);echo '</section>';
        echo '<form method="post" class="onb-direct-select" action="?page=hotel_checkout">'.token().'<input type="hidden" name="action" value="nuitee_hotel_select"><input type="hidden" name="offer_token" value="'.h($id).'"><button>Book this room</button></form>';
    }catch(RuntimeException $e){echo '<h1>Hotel details</h1><p class="notice">'.h($e->getMessage()).'</p>';}
    echo '<p><a href="?page=hotels">Search hotels</a></p>';
}
function onbHotelCheckoutState(): array {
    global $u;
    $s=$_SESSION['onb_hotel_checkout']??[];
    if(!$u||($s['uid']??null)!==(int)$u['id']||($s['sandbox']??null)!==onbTravelSettings()['sandbox'])throw new RuntimeException('Sign in and select a fresh hotel offer.');
    return $s;
}
function onbHotelCheckoutTable(): void {
    q("CREATE TABLE IF NOT EXISTS onb_hotel_checkouts (
      checkout_id CHAR(48) PRIMARY KEY,user_id INT NOT NULL,sandbox TINYINT NOT NULL,
      prebook_id VARCHAR(255) NOT NULL,transaction_id VARCHAR(255) NOT NULL,
      amount DECIMAL(12,2) NOT NULL,currency CHAR(3) NOT NULL,state VARCHAR(40) NOT NULL,
      holder_json TEXT NOT NULL,guests_json TEXT NOT NULL,
      booking_id VARCHAR(255) NULL,booking_ref VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY(prebook_id),INDEX(user_id)
    ) ENGINE=InnoDB");
}
function onbHotelCheckoutAction(string $action,bool $admin): void {
    global $u,$c;
    if(!$u)throw new RuntimeException('Please sign in through the traveller portal before booking.');
    if(!onbTravelCanSearch('hotels',$admin))throw new RuntimeException('Hotel search is unavailable.');
    if(in_array($action,['nuitee_hotel_select','nuitee_hotel_prebook'],true)){
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        if((int)q('SELECT COUNT(*) FROM attempts WHERE ip=? AND created_at > NOW()-INTERVAL 15 MINUTE',[$ip])->fetchColumn()>=15)throw new RuntimeException('Too many requests. Try again later.');
        q('INSERT INTO attempts(ip) VALUES(?)',[$ip]);
    }
    if($action==='nuitee_hotel_select'){
        $prior=$_SESSION['onb_hotel_checkout']??[];
        if(($prior['uid']??0)===(int)$u['id']&&($prior['sandbox']??null)===onbTravelSettings()['sandbox']&&!empty($prior['checkout_id'])&&($prior['state']??'')!=='CONFIRMED')throw new RuntimeException('An existing checkout needs attention. Do not pay again.');
        $s=onbHotelOffer(p('offer_token'),$admin);$s['uid']=(int)$u['id'];$_SESSION['onb_hotel_checkout']=$s;go('page=hotel_checkout');
    }
    $s=onbHotelCheckoutState();
    if(!onbHotelPaymentAllowed($admin))throw new RuntimeException('Direct hotel payments are not yet enabled. No payment was taken.');
    if($action==='nuitee_hotel_prebook'){
        if(!empty($s['checkout_id'])||$s['expires']<time())throw new RuntimeException('Existing or expired checkout. Search again or contact support.');
        if(p('accept_terms')!=='1'||($s['sandbox']&&p('test_data')!=='1'))throw new RuntimeException('Review the terms and use fictional details in sandbox.');
        $namePattern='/^[\p{L}\p{M} .\'-]{1,80}$/uD';
        $email=p('contact_email');if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        $holder=['firstName'=>onbFlightInput('contact_first',$namePattern,'first name'),'lastName'=>onbFlightInput('contact_last',$namePattern,'last name'),'email'=>$email];
        $guests=[];
        for($i=1;$i<=$s['rooms'];$i++)$guests[]=['occupancyNumber'=>$i,'firstName'=>onbFlightInput('guest_first_'.$i,$namePattern,'room guest first name'),'lastName'=>onbFlightInput('guest_last_'.$i,$namePattern,'room guest last name'),'email'=>$email];
        onbHotelCheckoutTable();
        $res=onbTravelRequest('/rates/prebook',['offerId'=>$s['offerId'],'usePaymentSdk'=>true]);$d=$res['data']??[];
        $pre=$d['prebookId']??'';$tid=$d['transactionId']??'';$secret=$d['secretKey']??'';$amount=$d['price']??null;
        if(!is_string($pre)||!$pre||strlen($pre)>255||!is_string($tid)||!$tid||strlen($tid)>255||!is_string($secret)||!str_starts_with($secret,'pi_')||!str_contains($secret,'_secret_')||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||($d['currency']??'')!=='CAD')throw new RuntimeException('Supplier returned an incomplete checkout. No payment taken.');
        if(($d['hotelId']??null)!==$s['hotel_id'])throw new RuntimeException('Hotel availability changed. Select a fresh offer.');
        $rates=[];foreach(($d['roomTypes']??[]) as $room)foreach(($room['rates']??[]) as $rate)if(is_array($rate))$rates[]=$rate;
        if(!$rates)throw new RuntimeException('Updated room terms are unavailable. No payment taken.');
        // Only proceed when the provider supplies a publicly sellable CAD price.
        if(!$s['sandbox']){
            $ssp=onbPremiumPrice($d['suggestedSellingPrice']??null);
            if(!empty($d['isPackageRate'])||max($ssp??0,$s['ssp']??0)>(float)$amount+0.005)throw new RuntimeException('This rate requires an adjusted supplier selling price. Request a quote; no payment was taken.');
            foreach($rates as $rate)if((!empty($rate['rateType'])&&strtolower((string)$rate['rateType'])!=='standard')||!empty($rate['requiresLoyaltyMembership'])||!empty($rate['loyaltyMembershipIdRequired']))throw new RuntimeException('Restricted hotel rate. Request a quote.');
        }
        $id=bin2hex(random_bytes(24));
        q('INSERT INTO onb_hotel_checkouts(checkout_id,user_id,sandbox,prebook_id,transaction_id,amount,currency,state,holder_json,guests_json) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,(int)$u['id'],(int)$s['sandbox'],$pre,$tid,$amount,'CAD','PREBOOKED',json_encode($holder,JSON_THROW_ON_ERROR),json_encode($guests,JSON_THROW_ON_ERROR)]);
        $s['checkout_id']=$id;$s['contact_email']=$email;$s['state']='PREBOOKED';$s['payment_price']=round((float)$amount,2);$s['verified_rates']=$rates;$s['secret']=$secret;$s['terms_notice']=(string)($d['termsAndConditions']??'');$s['changed']=!empty($d['cancellationChanged'])||!empty($d['boardChanged'])||round((float)$amount,2)!==($s['listing_price']??null);
        $_SESSION['onb_hotel_checkout']=$s;go('page=hotel_checkout');
    }
    if($action==='nuitee_hotel_finalize'){
        if(empty($s['checkout_id']))throw new RuntimeException('No checkout to finalize.');
        $r=q('SELECT * FROM onb_hotel_checkouts WHERE checkout_id=? AND user_id=? AND sandbox=?',[$s['checkout_id'],(int)$u['id'],(int)$s['sandbox']])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('Booking record unavailable.');
        if($r['state']==='CONFIRMED'){$s['state']='CONFIRMED';$s['booking_id']=$r['booking_id'];$s['booking_ref']=$r['booking_ref'];unset($s['secret'],$s['publishable']);$_SESSION['onb_hotel_checkout']=$s;go('page=hotel_checkout');}
        // Do not submit a second booking after an ambiguous response.
        if(!q("UPDATE onb_hotel_checkouts SET state='FINALIZING' WHERE checkout_id=? AND state='PREBOOKED'",[$s['checkout_id']])->rowCount())throw new RuntimeException('Booking is being reviewed. Do not pay again. Contact support with the checkout reference.');
        try{
            $res=onbTravelRequest('/rates/book',['prebookId'=>$r['prebook_id'],'clientReference'=>$r['checkout_id'],'holder'=>json_decode($r['holder_json'],true,512,JSON_THROW_ON_ERROR),'guests'=>json_decode($r['guests_json'],true,512,JSON_THROW_ON_ERROR),'payment'=>['method'=>'TRANSACTION_ID','transactionId'=>$r['transaction_id']]]);
            $d=$res['data']??[];$bid=$d['bookingId']??'';$ref=$d['hotelConfirmationCode']??'';
            $status=($d['status']??'')==='CONFIRMED'&&is_string($bid)&&$bid!==''?'CONFIRMED':'REVIEW_REQUIRED';
            q('UPDATE onb_hotel_checkouts SET state=?,booking_id=?,booking_ref=? WHERE checkout_id=?',[$status,substr((string)$bid,0,255),substr((string)$ref,0,255),$s['checkout_id']]);
            $s['state']=$status;$s['booking_id']=$bid;$s['booking_ref']=$ref;
            if($status==='CONFIRMED'){unset($s['secret'],$s['publishable']);$s['confirmed_hotel']=$d['hotel']['name']??$s['name'];}
            $_SESSION['onb_hotel_checkout']=$s;
            if($status==='CONFIRMED')onbTravelConfirmation('hotel',$s,$d);
            go('page=hotel_checkout');
        }catch(Throwable $e){
            q("UPDATE onb_hotel_checkouts SET state='REVIEW_REQUIRED' WHERE checkout_id=? AND state='FINALIZING'",[$s['checkout_id']]);$s['state']='REVIEW_REQUIRED';$_SESSION['onb_hotel_checkout']=$s;
            throw new RuntimeException('Confirmation is not yet verified. Do not pay again. Contact support with your checkout reference.');
        }
    }
    throw new RuntimeException('Unknown hotel checkout action.');
}
function onbHotelCheckoutView(bool $admin): void {
    global $u;
    if(!$u){echo '<h1>Sign in to book</h1><a class="btn" href="?page=login">Sign in</a>';return;}
    try{$s=onbHotelCheckoutState();}catch(RuntimeException $e){echo '<h1>Hotel checkout</h1><p>'.h($e->getMessage()).'</p><a class="btn" href="?page=hotels">Search hotels</a>';return;}
    $allowed=onbHotelPaymentAllowed($admin);onbCheckoutHeader('hotel',$s,$allowed);
    if($s['sandbox'])echo '<p class="notice">Sandbox only — fictional guests and Stripe test cards. No real reservation.</p>';
    if(($s['state']??'')==='CONFIRMED'){
        echo '<section class="onb-confirmed"><h2>'.($s['sandbox']?'Sandbox reservation confirmed':'Hotel reservation confirmed').'</h2><p>Property: '.h($s['confirmed_hotel']??$s['name']).'</p><p>Booking ID: '.h($s['booking_id']).'</p><p>Hotel confirmation code: '.h($s['booking_ref']?:'Awaiting property reference').'</p><p>Keep your booking ID for assistance.</p><a class="btn" href="?page=hotels">Search another hotel</a></section>';return;
    }
    echo '<div class="onb-checkout-layout onb-refined-checkout"><div class="onb-checkout-primary">';try{
    echo '<section class="onb-checkout-card onb-itinerary-card">';
    onbCheckoutSectionTitle('01','Your stay');
    echo '<div class="onb-stay-review">';onbPremiumImage($s['hotel']['main_photo']??null,$s['name'],'onb-stay-photo');echo '<div><h3>'.h($s['name']).'</h3><p>'.h($s['summary']).'</p></div></div>';
    echo '<details class="onb-rate-details"><summary>Room details, taxes &amp; cancellation policies</summary>';onbHotelRateDetails($s['verified_rates']??$s['rates']);echo '</details></section>';
    if(!$allowed){onbCheckoutUnavailable('hotel',$s,$admin);return;}
    if(!empty($s['checkout_id'])){
        echo '<section class="onb-checkout-card">';onbCheckoutSectionTitle('03','Secure payment','Review the final amount and any separate property fees before paying.');echo '<p class="onb-payment-amount">'.h(onbTravelMoney($s['payment_price'],'CAD')).'</p><p class="onb-ref">Checkout reference: '.h($s['checkout_id']).'</p>';
        if(($s['state']??'')!=='PREBOOKED'){echo '<p class="notice">Booking status requires review. Do not pay again. Contact support.</p></section>';return;}
        if(!empty($s['changed']))echo '<p class="notice">The supplier has updated the price or room conditions. Review the final total and updated terms before authorizing.</p>';if(!empty($s['terms_notice']))echo '<p>'.h(strip_tags($s['terms_notice'])).'</p>';
        echo '<div id="onb-hotel-payment" data-mode="'.($s['sandbox']?'sandbox':'live').'" data-secret="'.h($s['secret']).'" data-return="https://ournextboarding.com/index.php?page=hotel_checkout&amp;payment_return=1" data-returned="'.(isset($_GET['payment_return'])?'1':'0').'"><p id="onb-hotel-pay-status" role="status"></p><label><input id="onb-hotel-accept" type="checkbox"> I agree to the final total and updated hotel terms.</label><button id="onb-hotel-start" type="button" disabled>Open secure payment</button><div id="onb-hotel-sdk"></div><form id="onb-hotel-finalize" method="post" action="?page=hotel_checkout" '.(isset($_GET['payment_return'])?'':'hidden').'>'.token().'<input type="hidden" name="action" value="nuitee_hotel_finalize"><p>Returned from the payment provider? Continue to verify and finalize your reservation. Do not pay twice.</p><button type="submit">Confirm reservation</button></form></div><script src="https://payment-wrapper.liteapi.travel/dist/liteAPIPayment.js?v=a1" defer></script><script src="hotel-payment.js?v=1" defer></script></section>';return;
    }
    if($s['expires']<time()){echo '<p>Offer expired.</p><a href="?page=hotels">Search again</a>';return;}
    echo '<section class="onb-checkout-card onb-traveller-intro">';onbCheckoutSectionTitle('02','Guest details','Enter the booking holder and the primary guest in each room.');echo '</section><form class="onb-traveller-form" method="post" action="?page=hotel_checkout">'.token().'<input type="hidden" name="action" value="nuitee_hotel_prebook"><h3>Contact information</h3><div class="onb-travel-fields">';
    field('contact_first','Booking holder first name');field('contact_last','Booking holder last name');field('contact_email','Confirmation email',$s['sandbox']?'':(string)$u['email'],'email');echo '</div>';
    for($i=1;$i<=$s['rooms'];$i++){echo '<h3>Room '.$i.' — primary guest</h3><div class="onb-travel-fields">';field('guest_first_'.$i,'First name');field('guest_last_'.$i,'Last name');echo '</div>';}
    if($s['sandbox'])echo '<label><input type="checkbox" name="test_data" value="1" required> I am using fictional guests.</label>';
    echo '<label><input type="checkbox" name="accept_terms" value="1" required> I have reviewed the room and cancellation policies and want to verify the final price.</label><div class="onb-continue"><span>Next: confirm the final room rate</span><button>Continue to payment →</button></div></form>';
    }finally{
        echo '</div><aside class="onb-checkout-aside onb-checkout-summary"><span class="onb-kicker">BOOKING SUMMARY</span><h2>Your reservation</h2><p>'.h($s['name']).'</p><p>'.h($s['summary']).'</p>';
        onbCheckoutPrice($s,'hotel');
        echo '<p class="onb-secure-note">Separate mandatory property charges, if any, are listed in the room details. Your stay is confirmed only after supplier confirmation.</p></aside></div>';
    }
}

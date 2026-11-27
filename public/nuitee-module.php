<?php
declare(strict_types=1);

/* Provider credentials live only in ../private/config.php or environment. */
function onbTravelSettings(): array {
    global $c;
    $settings = is_array($c['nuitee'] ?? null) ? $c['nuitee'] : [];
    $key = trim((string)($settings['api_key'] ?? getenv('NUITEE_API_KEY') ?: ''));
    return [
        'key' => $key,
        'valid_key' => (str_starts_with($key, 'prod_') || str_starts_with($key, 'sand_')) && !str_starts_with($key, 'prod_public_') && !str_starts_with($key, 'sand_public_') && !preg_match('/\s/', $key),
        'sandbox' => str_starts_with($key, 'sand_'),
        'enabled' => ($settings['public_search'] ?? false) === true,
        'flights' => ($settings['flights_enabled'] ?? false) === true,
        'hosted_url' => (string)($settings['hosted_booking_url'] ?? ''),
    ];
}
function onbTravelCanSearch(string $kind, bool $admin): bool {
    $s = onbTravelSettings();
    if (!$s['valid_key'] || !function_exists('curl_init')) return false;
    if ($kind === 'flights' && !$s['flights']) return false;
    return $s['sandbox'] ? $admin : ($admin || $s['enabled']);
}
function onbTravelDate(string $value): DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value || $d < new DateTimeImmutable('today') || $d > new DateTimeImmutable('+11 months')) {
        throw new RuntimeException('Select a valid future travel date within the next 11 months.');
    }
    return $d;
}
function onbTravelRequest(string $path, array $body): array {
    $s = onbTravelSettings();
    if (!in_array($path, ['/hotels/rates','/flights/rates','/flights/verify','/flights/prebooks','/flights/bookings','/rates/prebook','/rates/book'], true)) throw new RuntimeException('Invalid search.');
    $base=in_array($path,['/rates/prebook','/rates/book'],true)?'https://book.liteapi.travel/v3.0':'https://api.liteapi.travel/v3.0';
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','X-API-Key: '.$s['key']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => in_array($path,['/rates/prebook','/rates/book','/flights/prebooks','/flights/bookings'],true)?120:35,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlCode = curl_errno($curl);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $supplierCode = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
    $supplierCode = is_scalar($supplierCode) && preg_match('/^[0-9]{1,10}$/D', (string)$supplierCode) ? (string)$supplierCode : 'not supplied';
    $diagnostic = 'HTTP '.$status.'; supplier code '.$supplierCode.'; cURL '.$curlCode.'; '.($s['sandbox'] ? 'sandbox' : 'production');
    global $admin;
    $detail = $admin ? ' ('.$diagnostic.')' : '';
    if ($status !== 200 || !is_array($data) || isset($data['error'])) error_log('Nuitee '.$path.' '.$diagnostic);
    if ($status === 401) throw new RuntimeException('Supplier authentication failed. Please check the secret API key or request a quote.'.$detail);
    if ($status === 403) throw new RuntimeException('Supplier denied API access. Please check account or product permissions or request a quote.'.$detail);
    if ($status === 429) throw new RuntimeException('Search capacity is temporarily reached. Please try again later.'.$detail);
    if ($status === 400 || $status === 422) throw new RuntimeException('The supplier rejected the search parameters. Please check the search or request a quote.'.$detail);
    if ($raw === false || $status !== 200) throw new RuntimeException('The travel supplier is temporarily unavailable. Please try again or request a quote.'.$detail);
    if ($supplierCode === '2001' && in_array($path,['/hotels/rates','/flights/rates'],true)) return ['data'=>[]];
    if (!is_array($data) || isset($data['error'])) throw new RuntimeException('The supplier did not return usable results. Please try again.'.$detail);
    return $data;
}
function onbTravelMoney($amount, $currency): string {
    return is_numeric($amount) && preg_match('/^[A-Z]{3}$/D', (string)$currency)
        ? (string)$currency.' '.number_format((float)$amount, 2) : 'Price on request';
}
function onbTravelSearch(string $kind, bool $admin): array {
    if (!onbTravelCanSearch($kind, $admin)) throw new RuntimeException('Online search is not available. Please request a quote.');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ((int)q('SELECT COUNT(*) FROM attempts WHERE ip=? AND created_at > NOW()-INTERVAL 15 MINUTE', [$ip])->fetchColumn() >= 12) throw new RuntimeException('Too many searches. Please try again in 15 minutes.');
    q('INSERT INTO attempts(ip) VALUES(?)', [$ip]);
    $adults = filter_var(p('adults'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>9]]);
    if (!$adults) throw new RuntimeException('Choose between 1 and 9 adults.');
    $start = onbTravelDate(p('start'));
    if ($kind === 'hotels') {
        $end = onbTravelDate(p('end'));
        if ($end <= $start || $start->diff($end)->days > 30) throw new RuntimeException('Choose a hotel stay of 1–30 nights.');
        $city = p('city'); $country = strtoupper(p('country')); $nationality = strtoupper(p('nationality'));
        if (!$city || strlen($city)>100 || !preg_match('/^[A-Z]{2}$/D',$country) || !preg_match('/^[A-Z]{2}$/D',$nationality)) throw new RuntimeException('Enter a city and two-letter country and guest nationality codes.');
        $rooms = filter_var(p('rooms'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>5]]);
        if (!$rooms || $rooms > $adults || $adults > 4*$rooms) throw new RuntimeException('Choose 1–5 rooms with 1–4 adults per room.');
        $occ = [];
        for ($i=0; $i<$rooms; $i++) $occ[] = ['rooms'=>1,'adults'=>intdiv($adults,$rooms)+($i<$adults%$rooms?1:0)];
        $result = onbTravelRequest('/hotels/rates', [
            'cityName'=>$city,'countryCode'=>$country,'guestNationality'=>$nationality,
            'checkin'=>$start->format('Y-m-d'),'checkout'=>$end->format('Y-m-d'),
            'occupancies'=>$occ,'currency'=>'CAD','includeHotelData'=>true,'roomMapping'=>true,'maxRatesPerHotel'=>2,'limit'=>30,'timeout'=>15
        ]);
        return ['response'=>$result,'summary'=>"$city, $country | ".$start->format('Y-m-d').' to '.$end->format('Y-m-d')." | $adults adults, $rooms rooms",'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'rooms'=>$rooms,'adults'=>$adults];
    }
    $from = strtoupper(p('origin')); $to = strtoupper(p('destination'));
    if (!preg_match('/^[A-Z]{3}$/D',$from) || !preg_match('/^[A-Z]{3}$/D',$to) || $from===$to) throw new RuntimeException('Enter different three-letter airport codes, such as YVR and NRT.');
    $legs = [['origin'=>$from,'destination'=>$to,'date'=>$start->format('Y-m-d'),'direction'=>'OUTBOUND']];
    $summary = "$from to $to | ".$start->format('Y-m-d');
    if (p('end') !== '') {
        $end = onbTravelDate(p('end'));
        if ($end < $start) throw new RuntimeException('Return date cannot be before departure.');
        $legs[]=['origin'=>$to,'destination'=>$from,'date'=>$end->format('Y-m-d'),'direction'=>'INBOUND'];
        $summary .= ' | Return '.$end->format('Y-m-d');
    }
    $result = onbTravelRequest('/flights/rates', ['legs'=>$legs,'adults'=>$adults,'currency'=>'CAD','country'=>'CA','sort'=>['sortBy'=>'price','sortOrder'=>'asc']]);
    return ['response'=>$result,'summary'=>$summary." | $adults adults",'adults'=>$adults];
}
function onbTravelQuote(string $message, string $label='Request booking quote'): void {
    $url='?page=contact&travel_request='.rawurlencode(substr($message,0,1800));
    echo '<a class="btn" href="'.h($url).'">'.h($label).'</a>';
}
function onbTravelResults(string $kind, array $search): void {
    $r=$search['response']; $summary=$search['summary']; $count=0;
    echo '<h2>Search results</h2><p>'.h($summary).'</p><p class="muted">Prices are supplier estimates at search time, subject to verification, taxes or local charges where applicable. No reservation is held. Select an offer to review the fare or request a quote.</p><div class="onb-travel-results">';
    if ($kind==='hotels') {
        $_SESSION['onb_hotel_offers']=[];
        $hotels=[];
        foreach (($r['hotels']??[]) as $hotel) if(is_array($hotel)&&isset($hotel['id'])) $hotels[$hotel['id']]=$hotel;
        foreach (($r['data']??[]) as $entry) {
            if(!is_array($entry)||!isset($entry['hotelId'])) continue;
            $hotel=$hotels[$entry['hotelId']]??[]; $name=(string)($hotel['name']??$entry['hotelName']??'Hotel '.$entry['hotelId']);
            foreach (($entry['roomTypes']??[]) as $room) {
                $rates=$room['rates']??[]; if(!is_array($rates)||!$rates) continue;
                // Prefer the offer total; do not show a single room as a multi-room total.
                $price=$room['offerRetailRate']??null;
                if (!is_array($price) || !isset($price['amount'],$price['currency'])) {
                    $sum=0;$currency=null;$valid=true;
                    foreach($rates as $rate){$part=$rate['retailRate']['total'][0]??[];if(!is_numeric($part['amount']??null)||!isset($part['currency'])||($currency!==null&&$currency!==$part['currency'])){$valid=false;break;}$currency=$part['currency'];$sum+=(float)$part['amount'];}
                    $price=$valid&&$currency?['amount'=>$sum,'currency'=>$currency]:[];
                }
                $money=onbTravelMoney($price['amount']??null,$price['currency']??'');
                $rate=$rates[0]; $roomName=(string)($rate['name']??'Room offer'); $board=(string)($rate['boardName']??'Meal plan not stated');
                echo '<article class="onb-travel-result">';
                $photo=(string)($hotel['main_photo']??'');
                if(filter_var($photo,FILTER_VALIDATE_URL)&&parse_url($photo,PHP_URL_SCHEME)==='https') echo '<img loading="lazy" alt="" src="'.h($photo).'">';
                echo '<div><h3>'.h($name).'</h3><p>'.h($hotel['address']??'').'</p><p>'.h($roomName.' · '.$board).'</p><p class="onb-travel-price">'.h($money).'</p><p class="muted">Cancellation terms and total must be confirmed.</p>';
                echo '<details class="onb-rate-details"><summary>Room details and policies</summary>';onbHotelRateDetails($rates);echo '</details>';
                onbHotelOfferButton($room,$hotel,$entry,$search);
                onbTravelQuote("Hotel enquiry\n$summary\n$name\n$roomName\n$money (unverified estimate)");
                echo '</div></article>'; if(++$count>=40) break 2;
            }
        }
    } else {
        $_SESSION['onb_flight_offers']=[];
        $journeys=[];
        foreach (($r['data']??[]) as $batch) foreach (($batch['journeys']??[]) as $journey) {
            $offer=$journey['offers'][0]??$journey['cheapestOffer']??[];
            $price=$offer['pricing']['display']??[];
            if (!is_array($price)) $price=[];
            $money=onbTravelMoney($price['total']??null,$price['currency']??'');
            if ($money==='Price on request' && is_numeric($price['total']??null)) $money=onbTravelMoney($price['total'],'CAD');
            $segments=[];
            foreach (($journey['segments']??[]) as $seg) {
                if (!isset($seg['originCode'],$seg['destinationCode'])) continue;
                $carrier=$seg['carrier']['marketingName']??$seg['carrier']['marketingCode']??'';
                $segments[]=trim(($seg['direction']??'').' '.$seg['originCode'].' → '.$seg['destinationCode'].' · '.$carrier.' · '.($seg['departureTime']??'').' → '.($seg['arrivalTime']??''));
            }
            if (!$segments) continue;
            $journeys[]=['segments'=>$segments,'money'=>$money,'offer'=>$offer,'journey'=>$journey,'sort'=>is_numeric($price['total']??null)?(float)$price['total']:INF];
        }
        usort($journeys,fn($a,$b)=>$a['sort']<=>$b['sort']);
        foreach(array_slice($journeys,0,40) as $j){
            echo '<article class="onb-travel-result"><div><h3>Flight itinerary</h3><ul>';
            foreach($j['segments'] as $segment)echo '<li>'.h($segment).'</li>';
            echo '</ul><p class="onb-travel-price">'.h($j['money']).'</p><p class="muted">Fare conditions, baggage and final total require confirmation.</p>';
            echo '<details class="onb-rate-details"><summary>Flight details</summary>';onbFlightSearchDetails($j['journey'],$j['offer']);echo '</details>';
            onbFlightOfferButton($j['offer'],$summary,(int)($search['adults']??0));
            onbTravelQuote("Flight enquiry\n$summary\n".implode("\n",$j['segments'])."\n".$j['money'].' (unverified estimate)');
            echo '</div></article>'; $count++;
        }
    }
    echo '</div>';
    if (!$count) echo '<p class="notice">No usable offers returned. Try different dates or request a quote.</p>';
}
function onbTravelView(string $kind, bool $admin, ?array $search=null): void {
    $hotel=$kind==='hotels'; $settings=onbTravelSettings(); $canSearch=onbTravelCanSearch($kind,$admin);
    $tomorrow=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    echo '<section class="onb-travel-hero"><span class="eyebrow">OUR NEXT BOARDING / BOOK YOUR WAY</span><h1>'.($hotel?'Find your perfect stay.':'Your next flight starts here.').'</h1><p>'.($hotel?'Explore stays for your next adventure.':'Search flights for your next adventure.').'</p><nav class="onb-travel-tabs"><a '.(!$hotel?'aria-current="page" ':'').'href="?page=flights">Flights</a><a '.($hotel?'aria-current="page" ':'').'href="?page=hotels">Hotels</a><a href="?page=trips">Group journeys</a></nav></section>';
    if ($admin) {
        if (!$settings['valid_key']) echo '<p class="notice">Administrator: configure a secret prod_ or sand_ API key in private/config.php. Public/HMAC keys are not supported by this integration.</p>';
        elseif (!function_exists('curl_init')) echo '<p class="notice">Administrator: PHP cURL is unavailable.</p>';
        elseif (!$hotel && !$settings['flights']) echo '<p class="notice">Administrator: local flights_enabled setting is false.</p>';
        elseif ($settings['sandbox']) echo '<p class="notice">Administrator sandbox mode — test inventory, not bookable.</p>';
        elseif (!$settings['enabled']) echo '<p class="notice">Administrator production search test — public searches are disabled.</p>';
    }
    if (!$canSearch) echo '<p class="notice">Online booking search is not yet available. Our team can help with a personalized travel enquiry.</p>';
    if ($canSearch) {
        echo '<form class="onb-travel-search" method="post" action="?page='.h($kind).'">'.token().'<input type="hidden" name="action" value="nuitee_search"><div class="onb-travel-fields">';
        if($hotel){
            field('city','Destination city',p('city'));
            field('country','Destination country (2-letter code)',p('country'));
            field('nationality','Guest nationality (2-letter code)',p('nationality')?:'CA');
        }else{
            field('origin','Departure airport (e.g. YVR)',p('origin'));
            field('destination','Arrival airport (e.g. HND)',p('destination'));
        }
        echo '<label>'.($hotel?'Check-in':'Departure date').'<input type="date" name="start" required min="'.h($tomorrow).'" value="'.h(p('start')).'"></label>';
        echo '<label>'.($hotel?'Check-out':'Return date (leave blank for one-way)').'<input type="date" name="end" '.($hotel?'required ':'').'min="'.h($tomorrow).'" value="'.h(p('end')).'"></label>';
        echo '<label>Adults<input type="number" name="adults" required min="1" max="9" value="'.h(p('adults')?:'2').'"></label>';
        if($hotel)echo '<label>Rooms<input type="number" name="rooms" required min="1" max="5" value="'.h(p('rooms')?:'1').'"></label>';
        echo '</div><button>Search '.($hotel?'hotels':'flights').'</button><p class="muted">Adults-only search. Contact us for children, multi-city travel or special requests.</p></form>';
    }
    if ($search!==null) onbTravelResults($kind,$search);
    echo '<section class="onb-travel-help"><h2>Prefer personalized assistance?</h2><p>Share your dates, destination and requirements with our travel team. Enquiries are not confirmed reservations.</p>';
    onbTravelQuote(($hotel?'Hotel':'Flight')." enquiry\nDestination:\nTravel dates:\nNumber of travellers:\nBudget:",'Request a personalized quote');
    $url=$settings['hosted_url'];
    if (!$settings['sandbox']&&$settings['enabled']&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'){
        echo ' <a class="btn" href="'.h($url).'" rel="noopener">Open secure booking portal</a><p class="muted">Continue to our configured supplier-hosted booking portal. Availability and payment terms are shown there.</p>';
    }
    echo '</section>';
}

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
    $isPrebookGet=preg_match('~^/flights/prebooks/[A-Za-z0-9_-]{1,255}$~D',$path)===1;
    if (!$isPrebookGet && !in_array($path, ['/hotels/rates','/flights/rates','/flights/verify','/flights/prebooks','/flights/bookings','/rates/prebook','/rates/book'], true)) throw new RuntimeException('Invalid search.');
    $base=in_array($path,['/rates/prebook','/rates/book'],true)?'https://book.liteapi.travel/v3.0':'https://api.liteapi.travel/v3.0';
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','X-API-Key: '.$s['key']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => in_array($path,['/rates/prebook','/rates/book','/flights/prebooks','/flights/bookings'],true)?120:35,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2
    ]);
    if(!$isPrebookGet)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
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
    if ($status !== 200 || !is_array($data) || isset($data['error'])) error_log('Nuitee '.($isPrebookGet?'/flights/prebooks/{id}':$path).' '.$diagnostic);
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
        $place=p('place_id'); $city=p('place_id_label'); $nationality=strtoupper(p('nationality'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$place)||!$city||strlen($city)>300||!preg_match('/^[A-Z]{2}$/D',$nationality)) throw new RuntimeException('Select a destination from the suggestions and enter your guest nationality.');
        $rooms = filter_var(p('rooms'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>5]]);
        if (!$rooms || $rooms > $adults || $adults > 4*$rooms) throw new RuntimeException('Choose 1–5 rooms with 1–4 adults per room.');
        $occ = [];
        for ($i=0; $i<$rooms; $i++) $occ[] = ['rooms'=>1,'adults'=>intdiv($adults,$rooms)+($i<$adults%$rooms?1:0)];
        $result = onbTravelRequest('/hotels/rates', [
            'placeId'=>$place,'guestNationality'=>$nationality,
            'checkin'=>$start->format('Y-m-d'),'checkout'=>$end->format('Y-m-d'),
            'occupancies'=>$occ,'currency'=>'CAD','includeHotelData'=>true,'roomMapping'=>true,'maxRatesPerHotel'=>2,'limit'=>30,'timeout'=>15
        ]);
        return ['response'=>$result,'summary'=>"$city | ".$start->format('Y-m-d').' to '.$end->format('Y-m-d')." | $adults adults, $rooms rooms",'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'rooms'=>$rooms,'adults'=>$adults];
    }
    $from = strtoupper(p('origin')?:p('origin_label')); $to = strtoupper(p('destination')?:p('destination_label'));
    if (!preg_match('/^[A-Z]{3}$/D',$from) || !preg_match('/^[A-Z]{3}$/D',$to) || $from===$to) throw new RuntimeException('Enter different three-letter airport codes, such as YVR and NRT.');
    $legs = [['origin'=>$from,'destination'=>$to,'date'=>$start->format('Y-m-d'),'direction'=>'OUTBOUND']];
    $summary = "$from to $to | ".$start->format('Y-m-d');
    if (p('trip_type') !== 'one' && p('end') !== '') {
        $end = onbTravelDate(p('end'));
        if ($end < $start) throw new RuntimeException('Return date cannot be before departure.');
        $legs[]=['origin'=>$to,'destination'=>$from,'date'=>$end->format('Y-m-d'),'direction'=>'INBOUND'];
        $summary .= ' | Return '.$end->format('Y-m-d');
    }
    $cabin=p('cabin')?:'ECONOMY';if(!in_array($cabin,['ECONOMY','PREMIUM_ECONOMY','BUSINESS','FIRST'],true))throw new RuntimeException('Invalid cabin class.');
    $result = onbTravelRequest('/flights/rates', ['legs'=>$legs,'adults'=>$adults,'currency'=>'CAD','country'=>'CA','cabinClass'=>$cabin,'sort'=>['sortBy'=>'price','sortOrder'=>'asc']]);
    return ['response'=>$result,'summary'=>$summary." | $adults adults",'adults'=>$adults];
}
function onbTravelQuote(string $message, string $label='Request booking quote'): void {
    $url='?page=contact&travel_request='.rawurlencode(substr($message,0,1800));
    echo '<a class="btn" href="'.h($url).'">'.h($label).'</a>';
}
function onbTravelResults(string $kind,array $search): void {onbPremiumResults($kind,$search);}
function onbTravelView(string $kind, bool $admin, ?array $search=null): void {
    $hotel=$kind==='hotels'; $settings=onbTravelSettings(); $canSearch=onbTravelCanSearch($kind,$admin);
    onbPremiumHero($kind);
    if ($admin) {
        if (!$settings['valid_key']) echo '<p class="notice">Administrator: configure a secret prod_ or sand_ API key in private/config.php. Public/HMAC keys are not supported by this integration.</p>';
        elseif (!function_exists('curl_init')) echo '<p class="notice">Administrator: PHP cURL is unavailable.</p>';
        elseif (!$hotel && !$settings['flights']) echo '<p class="notice">Administrator: local flights_enabled setting is false.</p>';
        elseif ($settings['sandbox']) echo '<p class="notice">Administrator sandbox mode — test inventory, not bookable.</p>';
        elseif (!$settings['enabled']) echo '<p class="notice">Administrator production search test — public searches are disabled.</p>';
    }
    if (!$canSearch) echo '<p class="notice">Online booking search is not yet available. Our team can help with a personalized travel enquiry.</p>';
    if ($canSearch) onbPremiumSearchForm($kind);
    if ($search!==null) onbTravelResults($kind,$search);
    echo '<section class="onb-travel-help"><h2>Prefer personalized assistance?</h2><p>Share your dates, destination and requirements with our travel team. Enquiries are not confirmed reservations.</p>';
    onbTravelQuote(($hotel?'Hotel':'Flight')." enquiry\nDestination:\nTravel dates:\nNumber of travellers:\nBudget:",'Request a personalized quote');
    $url=$settings['hosted_url'];
    if (!$settings['sandbox']&&$settings['enabled']&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'){
        echo ' <a class="btn" href="'.h($url).'" rel="noopener">Open secure booking portal</a><p class="muted">Continue to our configured supplier-hosted booking portal. Availability and payment terms are shown there.</p>';
    }
    echo '</section>';
}

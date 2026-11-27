(()=>{'use strict';
const root=document.getElementById('onb-flight-payment');if(!root)return;
const form=document.getElementById('onb-flight-card-form'),button=document.getElementById('onb-flight-pay-button'),retry=document.getElementById('onb-flight-recover'),msg=document.getElementById('onb-flight-pay-message'),accept=document.getElementById('onb-flight-pay-accept'),finalize=document.getElementById('onb-flight-finalize');
let stripe,elements,payment,busy=false,ready=false,submitted=false;
const say=t=>{msg.textContent=t};const lock=v=>{busy=v;button.disabled=v||!ready||!accept.checked;retry.disabled=v};
const finish=intent=>{if(!intent||!['succeeded','requires_capture'].includes(intent.status))return false;if(intent.currency!=='cad'||intent.amount!==Number(root.dataset.amount)){say('Payment amount could not be matched. Do not pay again. Contact support.');lock(true);return true;}if(submitted)return true;submitted=true;say('Payment authorized. Confirming your reservation with the airline.');lock(true);finalize.submit();return true};
try{stripe=Stripe(root.dataset.key);elements=stripe.elements({clientSecret:root.dataset.secret,appearance:{theme:'stripe',variables:{colorPrimary:'#102c25',borderRadius:'10px',fontFamily:'system-ui, sans-serif'}}});payment=elements.create('payment',{layout:'tabs'});payment.mount('#onb-flight-card');}catch(e){say('Payment form unavailable. Please contact support.');return;}
payment.on('ready',()=>{ready=true;if(!submitted&&!busy)lock(false)});accept.addEventListener('change',()=>{if(!busy&&!submitted)lock(false)});
async function check(){lock(true);try{const r=await stripe.retrievePaymentIntent(root.dataset.secret);if(r.error){say('Payment status could not be verified. Do not pay again until status is known.');return}if(finish(r.paymentIntent))return;if(r.paymentIntent?.status==='processing'){say('Payment is processing. Check status later.');return}say('No completed authorization was found. Review your payment details.');}catch(e){say('Connection interrupted. Check status before trying again.');}finally{if(!submitted)lock(false)}}
retry.addEventListener('click',check);
form.addEventListener('submit',async e=>{e.preventDefault();if(busy||submitted||!accept.checked||!ready)return;lock(true);try{
const previous=await stripe.retrievePaymentIntent(root.dataset.secret);if(previous.error){say('Could not verify payment status. Use Check payment status first.');return}if(finish(previous.paymentIntent))return;
if(!previous.paymentIntent||previous.paymentIntent.status!=='requires_payment_method'){say('This payment may already be in progress. Check status before retrying.');return;}
const validation=await elements.submit();if(validation.error){say(validation.error.message||'Review your payment details.');return}
const r=await stripe.confirmPayment({elements,confirmParams:{return_url:'https://ournextboarding.com/index.php?page=flight_checkout&payment_return=1'},redirect:'if_required'});
if(r.error){say(r.error.message||'Payment could not be authorized.');return}if(!finish(r.paymentIntent))say('Payment status is pending. Do not pay again; check status.');
}catch(e){say('Connection interrupted. Check payment status before trying again.');}finally{if(!submitted)lock(false)}});
if(new URLSearchParams(location.search).has('payment_return')){history.replaceState(null,'',location.pathname+'?page=flight_checkout');check();}
})();
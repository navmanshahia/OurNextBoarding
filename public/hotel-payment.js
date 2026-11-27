(()=>{'use strict';const root=document.getElementById('onb-hotel-payment');if(!root)return;
const accept=document.getElementById('onb-hotel-accept'),start=document.getElementById('onb-hotel-start'),status=document.getElementById('onb-hotel-pay-status');let opened=false;
const show=message=>{status.textContent=message};
if(root.dataset.returned==='1'){accept.closest('label').hidden=true;start.hidden=true;show('Payment provider returned. Complete the reservation below; the supplier will verify the transaction.');return}
accept.addEventListener('change',()=>{start.disabled=opened||!accept.checked});
start.addEventListener('click',()=>{if(!accept.checked||opened)return;if(typeof window.LiteAPIPayment!=='function'){show('Secure payment could not load. Please refresh or contact support.');return}opened=true;start.disabled=true;accept.disabled=true;
try{const payment=new window.LiteAPIPayment({publicKey:root.dataset.mode,appearance:{theme:'flat'},options:{business:{name:'Our Next Boarding'}},targetElement:'#onb-hotel-sdk',secretKey:root.dataset.secret,returnUrl:root.dataset.return});payment.handlePayment();show('Complete payment in the secure form below.')}catch(e){opened=false;accept.disabled=false;start.disabled=!accept.checked;show('Payment form unavailable. No booking has been confirmed.');}
});})();
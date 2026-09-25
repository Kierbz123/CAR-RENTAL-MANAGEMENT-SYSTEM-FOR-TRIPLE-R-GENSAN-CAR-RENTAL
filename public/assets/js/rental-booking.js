(() => {
    const root=document.querySelector('[data-booking-url]');
    if(!root)return;
    const status=document.querySelector('#booking-context-status');
    fetch(root.dataset.bookingUrl,{credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(async response=>{const result=await response.json();if(!response.ok||result.verified!==true)throw new Error(result.error||'Secure booking access expired. Request a new link.');return result.booking;})
        .then(booking=>{for(const [key,value] of Object.entries(booking)){const node=document.querySelector(`[data-field="${key}"]`);if(node)node.textContent=String(value);}document.querySelector('#booking-context').hidden=false;status.textContent='Your booking details are available for this secure session.';})
        .catch(error=>{status.textContent=error.message||'Booking details are unavailable. Request a new link.';});
})();

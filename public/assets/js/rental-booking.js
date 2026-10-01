(() => {
    const root=document.querySelector('[data-booking-url]');
    if(!root)return;
    const status=document.querySelector('#booking-context-status');
    const retry=document.querySelector('#booking-context-retry');
    const context=document.querySelector('#booking-context');
    const statusLabels={reserved:'Reserved',confirmed:'Confirmed',active:'In progress',returned:'Returned',completed:'Completed',cancelled:'Cancelled',no_show:'Not picked up'};
    const showFailure=(message,canRetry)=>{
        status.textContent=message;
        status.classList.add('booking-context-error');
        retry.hidden=!canRetry;
    };
    const loadBooking=async()=>{
        retry.hidden=true;
        status.classList.remove('booking-context-error');
        status.textContent='Checking your secure booking details…';
        try{
            const response=await fetch(root.dataset.bookingUrl,{credentials:'same-origin',headers:{Accept:'application/json'}});
            const result=await response.json();
            if(!response.ok||result.verified!==true){
                showFailure('This secure link may have expired. Reopen the latest Triple R booking link or contact the rental office for a new one.',false);
                return;
            }
            for(const [key,value] of Object.entries(result.booking??{})){
                const node=document.querySelector(`[data-field="${key}"]`);
                if(node)node.textContent=key==='status'?(statusLabels[value]??String(value)):String(value);
            }
            context.hidden=false;
            status.textContent='Your booking details are ready.';
        }catch{
            showFailure('We couldn’t load your booking details. Check your connection, then try again.',true);
        }
    };
    retry.addEventListener('click',loadBooking);
    loadBooking();
})();

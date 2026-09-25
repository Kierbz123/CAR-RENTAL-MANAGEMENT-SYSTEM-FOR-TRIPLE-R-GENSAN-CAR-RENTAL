(() => {
    const form=document.querySelector('form[action="/rentals/reserve"]');
    if(!form)return;
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        const button=form.querySelector('[type="submit"]');let error=form.querySelector('[data-form-error]');if(!error){error=document.createElement('p');error.className='alert';error.setAttribute('role','alert');error.hidden=true;form.insertBefore(error,button);}button.disabled=true;error.hidden=true;
        try{
            const values=Object.fromEntries(new FormData(form).entries());
            const response=await fetch('/api/rentals',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':values._csrf},body:JSON.stringify(values)});
            const result=await response.json();if(!response.ok)throw new Error(result.error||'The reservation could not be created.');
            window.location.assign(`/rentals/detail?agreement_id=${encodeURIComponent(result.agreement_id)}`);
        }catch(e){error.textContent=e.message||'The reservation could not be created.';error.hidden=false;button.disabled=false;}
    });
})();

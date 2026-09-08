document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.kmembers-auth input[name="email"],.kmembers-auth input[name="password"][autocomplete="current-password"]').forEach(function(input){
    input.addEventListener('keydown',function(event){
      if(event.key==='Enter'&&!event.shiftKey&&!event.ctrlKey&&!event.altKey&&!event.metaKey){
        var form=input.closest('form');
        if(form){event.preventDefault();if(typeof form.requestSubmit==='function'){form.requestSubmit();}else{form.submit();}}
      }
    });
  });
});

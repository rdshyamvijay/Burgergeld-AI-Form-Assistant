<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Citizen Forms</title>
  <style>
    :root { --magenta:#ff00a8; --text:#0f0f13; --bg:#ffffff; }
    body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
    .wrap { max-width:900px; margin:0 auto; padding:24px; }
    h1 { font-size:28px; margin:0 0 16px; }
    .card { background:#fff; border:1px solid #eee; border-radius:14px; padding:20px; box-shadow:0 4px 16px rgba(255,0,168,0.08); }
    .btn { background:var(--magenta); color:#fff; border:none; border-radius:10px; padding:10px 16px; cursor:pointer; font-weight:600; }
    .btn:disabled { opacity:.6; cursor:not-allowed; }
    label { display:block; font-weight:600; margin:10px 0 6px; }
    input, select { width:100%; padding:10px; border:1px solid #ddd; border-radius:10px; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .mt { margin-top:16px; }
    .muted { color:#555; }
    .pill { display:inline-block; background:#ffe6f6; color:#b30078; padding:6px 10px; border-radius:999px; font-size:12px; margin-right:6px; }
    pre { white-space:pre-wrap; background:#fafafa; padding:12px; border-radius:10px; border:1px solid #eee; }
    a { color:var(--magenta); text-decoration:none; }
    .help-btn { margin-left:8px; background:#fff; border:1px solid var(--magenta); color:var(--magenta); width:22px; height:22px; border-radius:50%; font-size:13px; line-height:18px; padding:0; cursor:pointer; vertical-align:middle; }
    .help-btn:hover { background:var(--magenta); color:#fff; }
    label .help-btn { display:inline-flex; align-items:center; justify-content:center; }
    .chat-assistant { position:fixed; right:24px; bottom:24px; width:320px; max-height:70vh; background:#fff; border:1px solid rgba(0,0,0,0.05); border-radius:18px; box-shadow:0 18px 40px rgba(15,35,61,0.18); display:none; flex-direction:column; z-index:1000; overflow:hidden; }
    .chat-assistant.open { display:flex; }
    .chat-assistant .chat-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:var(--magenta); color:#fff; font-weight:600; }
    .chat-assistant .chat-header button { background:transparent; border:none; color:#fff; font-size:18px; cursor:pointer; }
    .chat-assistant .chat-messages { flex:1; overflow-y:auto; padding:12px 18px; display:flex; flex-direction:column; gap:10px; background:#fff; }
    .chat-assistant .chat-message { padding:10px 14px; border-radius:14px; font-size:14px; line-height:1.4; max-width:85%; word-wrap:break-word; }
    .chat-assistant .chat-message.assistant { align-self:flex-start; background:#f6f7fb; color:#111; }
    .chat-assistant .chat-message.user { align-self:flex-end; background:var(--magenta); color:#fff; }
    .chat-assistant .chat-form { display:flex; gap:8px; padding:12px 18px; border-top:1px solid #eee; background:#fff; }
    .chat-assistant .chat-form input { flex:1; border:1px solid #ddd; border-radius:999px; padding:8px 14px; font-size:14px; }
    .chat-assistant .chat-form button { border:none; background:var(--magenta); color:#fff; padding:8px 16px; border-radius:999px; cursor:pointer; font-weight:600; }
    @media (max-width: 768px) {
      .chat-assistant { width:90%; right:5%; bottom:16px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    @yield('content')
  </div>
  <div id="chat-assistant" class="chat-assistant" aria-live="polite">
    <div class="chat-header">
      <span id="chat-topic">Form Assistant</span>
      <button type="button" id="chat-close" aria-label="Close assistant">×</button>
    </div>
    <div class="chat-messages" id="chat-messages"></div>
    <form class="chat-form" id="chat-form">
      <input id="chat-input" type="text" placeholder="Ask for help…" autocomplete="off"/>
      <button type="submit">Send</button>
    </form>
  </div>
  <script>
  (function(){
    const Assistant = {
      currentForm:null,
      currentKey:null,
      history:[],
      init(){
        this.panel=document.getElementById('chat-assistant');
        this.topic=document.getElementById('chat-topic');
        this.messages=document.getElementById('chat-messages');
        this.form=document.getElementById('chat-form');
        this.input=document.getElementById('chat-input');
        this.closeBtn=document.getElementById('chat-close');
        if(!this.panel) return;
        this.history=[];
        this.form.addEventListener('submit',e=>{e.preventDefault(); this.sendMessage();});
        this.closeBtn.addEventListener('click',()=> this.panel.classList.remove('open'));
      },
      handleButton(btn){
        const form = btn.dataset.form || '';
        const key = btn.dataset.key || '';
        const label = btn.dataset.label || key;
        this.openForField(form, key, label);
      },
      openForField(form,key,label){
        this.currentForm=form;
        this.currentKey=key;
        this.history=[];
        this.panel.classList.add('open');
        this.topic.textContent = label || 'Form Assistant';
        this.messages.innerHTML = '';
        const placeholder = this.appendMessage('assistant','Einen Moment, ich sammle Hinweise …');
        this.fetchHelp(form,key,placeholder);
        setTimeout(()=>this.input && this.input.focus(), 150);
      },
      addHistory(role,text){
        if(!text) return;
        this.history.push({role, content:text});
        if (this.history.length > 14) {
          this.history = this.history.slice(-14);
        }
      },
      async fetchHelp(form,key,placeholder){
        try{
          const res = await fetch('/api/ai/help',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({form,key})
          });
          const data = await res.json();
          if(data.help){
            placeholder.textContent = data.help;
            this.addHistory('assistant', data.help);
          }else if(data.error){
            placeholder.textContent = data.error;
            this.addHistory('assistant', data.error);
          }else{
            placeholder.textContent = 'Bitte füllen Sie dieses Feld entsprechend Ihren Dokumenten aus.';
            this.addHistory('assistant', placeholder.textContent);
          }
        }catch(err){
          placeholder.textContent = 'Ich konnte gerade keine Hinweise laden. Versuchen Sie es später erneut.';
          this.addHistory('assistant', placeholder.textContent);
        }
      },
      appendMessage(role,text){
        const bubble=document.createElement('div');
        bubble.className='chat-message '+role;
        bubble.textContent=text;
        this.messages.appendChild(bubble);
        this.messages.scrollTop=this.messages.scrollHeight;
        return bubble;
      },
      async sendMessage(){
        if(!this.input) return;
        const message=this.input.value.trim();
        if(!message) return;
        this.appendMessage('user', message);
        this.addHistory('user', message);
        this.input.value='';
        this.input.focus();
        if(!this.currentForm || !this.currentKey){
          this.appendMessage('assistant','Bitte wählen Sie zuerst ein Feld über das Fragezeichen aus.');
          return;
        }
        try{
          const res = await fetch('/api/ai/chat',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({form:this.currentForm,key:this.currentKey,message,history:this.history})
          });
          const data = await res.json();
          if(data.reply){
            this.appendMessage('assistant', data.reply);
            this.addHistory('assistant', data.reply);
          }else if(data.error){
            this.appendMessage('assistant', data.error);
            this.addHistory('assistant', data.error);
          }else{
            this.appendMessage('assistant','Ich hoffe, das hilft weiter.');
            this.addHistory('assistant','Ich hoffe, das hilft weiter.');
          }
        }catch(err){
          this.appendMessage('assistant','Entschuldigung, aktuell kann ich nicht antworten.');
        }
      }
    };
    window.ChatAssistant = Assistant;
    document.addEventListener('DOMContentLoaded', ()=>Assistant.init());
  })();
  </script>
</body>
</html>

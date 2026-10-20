import { connectRealtime } from './realtime';
const chat = document.querySelector('[data-chat]');
const inbox = document.querySelector('[data-conversation-list]');
const root = chat || inbox;
if (root) {
    let connected = false, stopped = false, busy = false, retry = 0, timer;
    const connection = root.querySelector('[data-chat-connection]');
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const status = text => { connection.textContent = text; };
    async function request(url, options = {}) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options, headers: {...headers, ...options.headers}, signal: controller.signal });
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                stopped = true; status('Tu sesión cambió. Ingresa de nuevo para continuar.');
                throw new Error('Tu sesión venció o cambió. Vuelve a ingresar; conserva tu mensaje antes de salir.');
            }
            let data;
            try { data = await response.json(); } catch { throw new Error('No se pudo conectar. Tu mensaje sigue aquí; vuelve a intentar.'); }
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || (response.status === 429 ? 'Espera un momento y vuelve a enviar.' : 'No se pudo completar. Vuelve a intentar.'));
            return data;
        } finally { clearTimeout(timeout); }
    }
    let last = 0, seen = 0, pending = false, markingSeen = false;
    const history = chat?.querySelector('[data-chat-history]');
    const messages = chat?.querySelector('[data-chat-messages]');
    const form = chat?.querySelector('[data-chat-composer]');
    const newButton = chat?.querySelector('[data-chat-new]');
    const nearBottom = () => history.scrollHeight - history.scrollTop - history.clientHeight < 100;
    const scrollBottom = () => { history.scrollTop = history.scrollHeight; newButton.hidden = true; markSeen(); };
    const csrf = () => form?.elements._token.value;
    async function markSeen() {
        if (!chat || document.hidden || stopped || markingSeen || !last || last <= seen || !nearBottom()) return;
        markingSeen=true;
        const current = last;
        try { await request(chat.dataset.seenUrl, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify({message_id:current})}); seen=Math.max(seen,current); }
        catch {} finally {markingSeen=false;}
        if(seen===current && last>seen)markSeen();
    }
    function messageElement(message) {
        const article = document.createElement('article'); article.className='chat-bubble'+(message.own?' is-own':''); article.dataset.messageId=message.id; article.dataset.createdAt=message.created_at;
        const name=document.createElement('strong'); name.textContent=message.name;
        const body=document.createElement('p'); body.textContent=message.body;
        const time=document.createElement('time'); time.textContent=message.time+(message.own?' · Enviado':'');
        article.append(name,body,time); return article;
    }
    function appendMessages(rows, older=false, advance=true) {
        const stick = nearBottom(); const height=history.scrollHeight;
        let added=0;
        for(const row of rows) {
            if(messages.querySelector('[data-message-id="'+Number(row.id)+'"]'))continue;
            const next=[...messages.children].find(node=>Number(node.dataset.messageId)>Number(row.id));
            messages.insertBefore(messageElement(row),next||null); added++;
        }
        if(!older && advance)last=Math.max(last,...rows.map(row=>Number(row.id)),0);
        chat.querySelector('[data-chat-empty]').hidden=!!messages.children.length;
        if(older) history.scrollTop+=history.scrollHeight-height;
        else if(stick)scrollBottom(); else if(added)newButton.hidden=false;
    }
    async function refreshMessages() {
        const data=await request(chat.dataset.messagesUrl+'?after='+last);
        appendMessages(data.messages);
        await markSeen();
        return data.has_more;
    }
    function renderThreads(rows) {
        const list=inbox.querySelector('[data-chat-threads]'); const fragment=document.createDocumentFragment();
        for(const row of rows) {
            const link=document.createElement('a'); link.className='chat-thread'+(row.unread?' is-unread':'');
            const target=new URL(row.url,location.origin); if(target.origin!==location.origin)continue; link.href=target.href;
            const avatar=document.createElement('span');avatar.className='chat-avatar';avatar.textContent=row.name.slice(0,1);
            const copy=document.createElement('span');copy.className='chat-thread-copy';
            const name=document.createElement('strong');name.textContent=row.name;
            const subject=document.createElement('b');subject.textContent=row.subject;
            const preview=document.createElement('small');preview.textContent=row.preview;
            copy.append(name,subject,preview);
            const time=document.createElement('time');time.textContent=row.time||'';
            link.append(avatar,copy,time);fragment.append(link);
        }
        if(!rows.length){const empty=document.createElement('p');empty.className='pp-empty';empty.textContent='No hay conversaciones para mostrar.';fragment.append(empty);}
        list.replaceChildren(fragment);
    }
    async function refresh() {
        clearTimeout(timer);
        if(stopped)return;
        if(busy){pending=true;return;}
        if(document.hidden || !navigator.onLine){status('Sin conexión activa. Reconectaremos automáticamente.');timer=setTimeout(refresh,10000);return;}
        busy=true; let more=false;
        try {
            if(chat) more=await refreshMessages(); else renderThreads((await request(inbox.dataset.listUrl)).threads);
            retry=0;status(connected?'En tiempo real':'Actualización automática');
        } catch(error){retry=Math.min(retry+1,5);if(!stopped)status('Reconectando… tus mensajes están guardados.');}
        finally {busy=false;if(!stopped){const fast=more||pending;pending=false;timer=setTimeout(refresh,fast?50:retry?Math.min(30000,retry*5000):connected?15000:3000);}}
    }
    if(chat) {
        last=Math.max(0,...[...messages.children].map(node=>Number(node.dataset.messageId)));
        scrollBottom();
        newButton.addEventListener('click',scrollBottom);
        history.addEventListener('scroll',()=>{if(nearBottom()){newButton.hidden=true;markSeen();}},{passive:true});
        const older=chat.querySelector('[data-chat-older]');
        older.addEventListener('click',async()=>{older.disabled=true;try {const first=messages.firstElementChild?.dataset.messageId;if(!first)return;const data=await request(chat.dataset.messagesUrl+'?before='+first);appendMessages(data.messages,true);older.hidden=!data.has_more;}catch(error){form.querySelector('[data-chat-send-status]').textContent=error.message;}finally{older.disabled=false;}});
        let sending=false, attemptedBody=null;
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(sending || stopped)return;
            const body=form.elements.body.value.trim();if(!body)return;
            if(attemptedBody!==null && attemptedBody!==body)form.elements.submission_key.value=crypto.randomUUID();
            attemptedBody=body;
            sending=true;const button=form.querySelector('button[type=submit]');button.disabled=true;button.textContent='Enviando…';
            const output=form.querySelector('[data-chat-send-status]');output.textContent='Enviando tu mensaje…';
            try {
                const data=await request(form.action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify({body,submission_key:form.elements.submission_key.value})});
                // Never advance the incoming cursor past messages missed during this send.
                appendMessages([data.message],false,false);
                if(form.elements.body.value.trim()===body)form.elements.body.value='';
                form.elements.submission_key.value=crypto.randomUUID();output.textContent='Mensaje enviado';scrollBottom();refresh();
            }catch(error){output.textContent=error.message;}
            finally{sending=false;button.disabled=false;button.textContent='Enviar ↑';form.elements.body.focus();}
        });
        form.elements.body.addEventListener('keydown',event=>{if(event.key==='Enter' && !event.shiftKey && !event.isComposing && !matchMedia('(pointer:coarse)').matches){event.preventDefault();form.requestSubmit();}});
    }
    const realtime=connectRealtime({userId:Number(root.dataset.userId),key:root.dataset.realtimeKey,threadId:chat?Number(chat.dataset.threadId):null,onConversation:refresh,onInbox:()=>{window.dispatchEvent(new Event('agrolazo:inbox'));if(inbox)refresh();},onState:state=>{connected=state==='connected';if(!stopped){status(connected?'En tiempo real':'Reconectando…');if(connected)refresh();}}});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    window.addEventListener('online',refresh);window.addEventListener('offline',()=>status('Sin conexión. Tu mensaje permanece aquí.'));
    window.addEventListener('pagehide',event=>{clearTimeout(timer);if(!event.persisted){stopped=true;realtime?.destroy();}});
    window.addEventListener('pageshow',()=>{if(!stopped)refresh();});
    refresh();
}

/* generado por build_cf.py: lógica de precios/créditos compartida con la tienda */
export function makeLogic(){
 const window={matchMedia:()=>({matches:false})}, document={}, matchMedia=window.matchMedia;
const isStaff=u=>u&&(u.role==='admin'||u.role==='gerente');
function notify(to,title,body,go){S.notifs.unshift({id:'n'+uid()+uid(),to,title,body,go:go||'',ts:Date.now(),read:false})}
var API=false;
const PAYM={efectivo:"Efectivo",tarjeta:"Tarjeta",transferencia:"Transferencia"};
/* ============ utilidades ============ */
const $=(s,el=document)=>el.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const uid=()=>Math.random().toString(36).slice(2,8);
const sum=(a,f)=>a.reduce((t,x)=>t+(+f(x)||0),0);
const costOf=pid=>{const c=(S.costs||[]).find(x=>x.id===pid);return c?c.cost:null};
function setCost(pid,v){S.costs=S.costs||[];if(v===''||v==null){S.costs=S.costs.filter(x=>x.id!==pid);return}const n=Math.max(0,Math.round(+v||0)),c=S.costs.find(x=>x.id===pid);if(c)c.cost=n;else S.costs.push({id:pid,cost:n})}
const reduced=window.matchMedia&&matchMedia('(prefers-reduced-motion:reduce)').matches;
let S=null; // estado persistente
var API=false; // true cuando la tienda corre con el servidor y su base de datos
const UI={lang:(()=>{try{return localStorage.getItem('gt_lang')||''}catch(e){return ''}})(),cur:(()=>{try{return localStorage.getItem('gt_cur')||''}catch(e){return ''}})(),view:'store',cat:'all',q:'',sort:'nuevo',modal:null,img:0,qty:1,tab:null,co:{pay:'efectivo'},confirm:null,pf:'todos',cq:'',draft:null,persist:true};
const CURS=[['USD','Dólar estadounidense (Ecuador, El Salvador, Panamá)'],['EUR','Euro (España)'],['MXN','Peso mexicano'],['ARS','Peso argentino'],['BOB','Boliviano'],['BRL','Real brasileño'],['CLP','Peso chileno'],['CRC','Colón costarricense'],['CUP','Peso cubano'],['DOP','Peso dominicano'],['GTQ','Quetzal guatemalteco'],['HNL','Lempira hondureño'],['HTG','Gourde haitiano'],['NIO','Córdoba nicaragüense'],['PEN','Sol peruano'],['PYG','Guaraní paraguayo'],['UYU','Peso uruguayo'],['VES','Bolívar venezolano']];
const baseCur=()=>(S&&S.settings.currency)||'COP';
const curList=()=>((S&&S.settings.curs)||[]).filter(c=>c&&c.code&&+c.rate>0);
let CUR_O;const inOrder=(o,fn)=>{const p=CUR_O;CUR_O=o&&o.cur?o.cur:null;try{return fn()}finally{CUR_O=p}};
const curCode=()=>{if(CUR_O!==undefined)return CUR_O?CUR_O.code:null;if(typeof UI==='undefined'||!UI||!UI.cur||UI.cur===baseCur()||UI.view!=='store')return null;return curList().some(k=>k.code===UI.cur)?UI.cur:null};
const curRate=()=>{if(CUR_O!==undefined)return CUR_O?+CUR_O.rate:null;const c=curCode();const x=c&&curList().find(k=>k.code===c);return x?+x.rate:null};
const fmtCur=(n,code,d)=>{try{return new Intl.NumberFormat('es-CO',{style:'currency',currency:code,currencyDisplay:'code',maximumFractionDigits:d,minimumFractionDigits:d}).format(n||0)}catch(e){return code+' '+(n||0).toFixed(d)}};
const money=n=>{const r=curRate();if(r){const cc=curCode(),d=['CLP','PYG'].includes(cc)?0:2;return fmtCur((n||0)/r,cc,d)}try{return new Intl.NumberFormat('es-CO',{style:'currency',currency:baseCur(),maximumFractionDigits:0}).format(n||0)}catch(e){return '$'+Math.round(n||0)}};
const moneyBase=n=>inOrder(null,()=>money(n));
const curNote=()=>{const r=curRate();return r?`<p class="muted sm-t" style="margin:6px 0 0">Pagarás en ${curCode()}. Tasa de la tienda: 1 ${curCode()} = ${new Intl.NumberFormat('es-CO').format(r)} ${baseCur()}.</p>`:''};
const curSel=()=>{const l=curList();if(!l.length)return '';return `<select class="cursel" data-c="cur" aria-label="Moneda">${[baseCur(),...l.map(c=>c.code)].map(c=>`<option ${UI.cur===c||(!UI.cur&&c===baseCur())?'selected':''}>${c}</option>`).join('')}</select>`};
const fdate=t=>{try{return new Date(t).toLocaleString('es-CO',{dateStyle:'medium',timeStyle:'short'})}catch(e){return String(t)}};
const phoneDigits=p=>{let d=String(p||'').replace(/\D/g,'');if(d.length===10&&d[0]==='3')d='57'+d;return d};
const prod=id=>S.products.find(p=>p.id===id);
const cat=id=>S.categories.find(c=>c.id===id);
const userById=id=>S.users.find(u=>u.id===id);
const me=()=>S&&S.session?userById(S.session):null;
const isPaid=o=>o.status==='pagado'||o.status==='entregado';
const nm=()=>(S&&S.settings.storeName)||'GOLDEN TOUCH';
const tg=()=>(S&&S.settings.tagline)||'El toque dorado que te hace brillar.';
const logoSrc=()=>(S&&S.settings.logo)||LOGO;
const cust=()=>S&&S.custSession?S.customers.find(k=>k.id===S.custSession)||null:null;
const dayKey=ts=>{const d=new Date(ts);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')};
const plr=(n,o,m)=>n+' '+(n===1?o:m);
const fday=k=>{const [y,m,d]=k.split('-').map(Number);const t=new Date(y,m-1,d).toLocaleDateString('es-CO',{weekday:'long',day:'numeric',month:'long',year:'numeric'});return t.charAt(0).toUpperCase()+t.slice(1)};
const fshort=ts=>new Date(ts).toLocaleDateString('es-CO',{day:'numeric',month:'short',year:'numeric'});
const startOfToday=()=>{const d=new Date();d.setHours(0,0,0,0);return d.getTime()};
/* crédito */
const creditOptions=()=>S.settings.creditInstallments&&S.settings.creditInstallments.length?S.settings.creditInstallments:[2,3,4,6];
const creditPct=pid=>{const p=prod(pid);return p&&p.creditMarkup!=null?+p.creditMarkup:(+S.settings.creditMarkupDefault||0)};
const ratePct=n=>n?Math.max(0,+((S.settings.creditRates||{})[n])||0):0;
const unitPrice=(l,pt,n)=>pt==='credito'?Math.round(l.price*(1+(creditPct(l.pid)+ratePct(n))/100)):l.price;
const discOf=p=>Math.min(90,Math.max(0,+(p&&p.discount)||0));
const salePrice=p=>discOf(p)>0?Math.round(p.price*(1-discOf(p)/100)):p.price;
const creditOkP=p=>!!p&&!p.creditOff;
const scopeText=c=>c.scope==='category'?'la categoría “'+((S.categories.find(x=>x.id===c.scopeId)||{}).name||'eliminada')+'”':c.scope==='product'?'el producto “'+((S.products.find(x=>x.id===c.scopeId)||{}).name||'eliminado')+'”':'toda la tienda';
const scopeLabel=c=>c.scope==='category'?'Categoría: '+((S.categories.find(x=>x.id===c.scopeId)||{}).name||'(eliminada)'):c.scope==='product'?'Producto: '+((S.products.find(x=>x.id===c.scopeId)||{}).name||'(eliminado)'):'Toda la tienda';
const inScope=(c,l)=>!c.scope||c.scope==='all'||(c.scope==='category'&&((S.products.find(x=>x.id===l.pid)||{}).categoryId===c.scopeId))||(c.scope==='product'&&l.pid===c.scopeId);
const priceHtml=(p,big)=>discOf(p)>0?`<span class="pr"><span class="was">${money(p.price)}</span><b class="price"${big?' style="font-size:30px"':''}>${money(salePrice(p))}</b><span class="offb">−${discOf(p)}%</span></span>`:`<b class="price"${big?' style="font-size:30px"':''}>${money(p.price)}</b>`;
const unpaid=o=>o.plan?sum(o.plan.filter(i=>!i.paid),i=>i.amount):0;
const paidAmt=o=>o.plan?sum(o.plan.filter(i=>i.paid),i=>i.amount):0;
const nextInst=o=>o.plan?o.plan.find(i=>!i.paid):null;
const custOutstanding=cid=>sum(S.orders.filter(o=>o.customerId===cid&&o.payType==='credito'&&(o.status==='pendiente'||o.status==='credito')),unpaid);
const custLimit=k=>k&&k.creditLimit!=null?+k.creditLimit:(+S.settings.creditLimitDefault||0);
const statLabel=o=>o.payType==='credito'&&o.status==='pendiente'?'Crédito por aprobar':STAT[o.status];
const canShare=o=>isPaid(o)||o.status==='credito';
const CST={aprobar:'Por aprobar',aldia:'Al día',mora:'En mora',pagado:'Pagado',cancelado:'Cancelado'};
function creditState(o){
  if(o.status==='cancelado')return'cancelado';if(o.status==='pendiente')return'aprobar';
  if(o.status==='pagado'||o.status==='entregado'||!nextInst(o))return'pagado';
  return o.plan.some(i=>!i.paid&&i.due<startOfToday())?'mora':'aldia';
}
const nInst=o=>o.plan?o.plan.filter(i=>!i.initial).length:0;
const instLabel=(o,i)=>i.initial?'Inicial':i.n+'/'+nInst(o);
const downOf=(total,raw)=>S.settings.creditDownEnabled?Math.min(Math.max(0,Math.round(+raw||0)),Math.floor(total*.9)):0;
function buildCreditPlan(total,n,from,down){
  const plan=buildPlan(total-down,n,from);
  if(down>0)plan.unshift({n:0,initial:true,due:from+2*864e5,amount:down,paid:false,paidAt:null});
  return plan;
}
function buildPlan(total,n,from){
  const base=Math.floor(total/n),plan=[];
  for(let i=0;i<n;i++){const d=new Date(from);d.setMonth(d.getMonth()+i+1);plan.push({n:i+1,due:d.getTime(),amount:i===n-1?total-base*(n-1):base,paid:false,paidAt:null})}
  return plan;
}
function payInstallment(oid,idx){
  const o=S.orders.find(x=>x.id===oid);if(!o||!o.plan)throw new Error('Crédito no encontrado');
  if(o.status!=='credito')throw new Error('Aprueba el crédito antes de registrar pagos');
  const i=o.plan[idx];if(!i||i.paid)return;
  i.paid=true;i.paidAt=Date.now();i.by=me()?me().name:'';
  if(o.plan.every(x=>x.paid)){o.status='pagado';o.paidAt=Date.now();awardPoints(o)}
}
function migrate(){
  const d={storeName:'GOLDEN TOUCH',tagline:'El toque dorado que te hace brillar.',logo:null,creditEnabled:true,creditMarkupDefault:10,creditInstallments:[2,3,4,6],creditLimitDefault:2000000,creditDownEnabled:true,creditRates:{2:0,3:3,4:5,6:8}};
  Object.assign(d,{loyOn:false,refOn:false,loyEarn:1,loyVal:50,loyMin:20,loyMaxPct:50,refFriend:10000,refFriendMin:50000,refInviterPts:200,cartOn:false,cartDelay:1,cartAuto:false,cartMsg:'',cartSubject:'',cartCode:'',cartCodeNote:'',pickupOn:false,pickupName:'',pickupAddr:'',pickupHours:'',pickupMap:'',pickupNote:'',waOn:false,waMode:'template',waLang:'es',waKinds:{nuevo:true,pagado:true,preparando:false,enviado:true,listo:true,entregado:false},waTpl:{}});
  S.settings=Object.assign({},d,S.settings);
  S.cashClosings=S.cashClosings||[];S.carts=S.carts||[];S.costs=S.costs||[];S.reviews=S.reviews||[];S.prefs=S.prefs||{};S.favs=S.favs||{};S.creditReqs=S.creditReqs||[];S.notifs=S.notifs||[];if(S.custSession===undefined)S.custSession=null;
  S.orders.forEach(o=>{if(!o.payType)o.payType='contado'});
  S.v=2;
}
function findCoupon(code,lines,pt,n){
  code=String(code||'').trim().toUpperCase();if(!code)return{};
  const c=S.coupons.find(x=>x.code.toUpperCase()===code&&x.active);
  if(!c)return{err:'El cupón no existe o está inactivo'};
  const elig=lines.filter(l=>inScope(c,l)),sub=sum(elig,l=>unitPrice(l,pt,n)*l.qty);
  if(!elig.length)return{err:'Este cupón solo aplica a '+scopeText(c)};
  if(c.minTotal>sub)return{err:'Este cupón requiere una compra mínima de '+money(c.minTotal)+(c.scope&&c.scope!=='all'?' en '+scopeText(c):'')};
  if(c.maxUses>0&&c.used>=c.maxUses)return{err:'Este cupón ya alcanzó su límite de usos'};
  const discount=c.type==='percent'?Math.round(sub*c.value/100):Math.min(c.value,sub);
  return{coupon:c,discount,eligSub:sub};
}
function calcTotals(lines,couponCode,method,zoneId,pt,n,x){
  const subtotal=sum(lines,l=>unitPrice(l,pt,n)*l.qty);
  const cp=findCoupon(couponCode,lines,pt,n);
  const discount=cp.discount||0;
  let shipping=0,free=false;
  if(method&&method!=='presencial'){
    const z=S.zones.find(z=>z.id===zoneId);shipping=z?z.cost:0;
    const ff=+S.settings.freeShippingFrom||0;
    if(ff>0&&subtotal-discount>=ff&&shipping>0){shipping=0;free=true}
  }
  const loy=Math.max(0,Math.min(((x&&x.pts)||0)+((x&&x.ref)||0),subtotal-discount));
  return{subtotal,discount,loy,coupon:cp.coupon,couponErr:cp.err,shipping,free,total:subtotal-discount-loy+shipping,extra:subtotal-sum(lines,l=>l.price*l.qty)};
}
function upsertCustomer(c,ownerId){
  const ph=phoneDigits(c.phone),em=String(c.email||'').trim().toLowerCase();
  let x=S.customers.find(k=>(ph&&phoneDigits(k.phone)===ph)||(em&&String(k.email||'').toLowerCase()===em));
  if(!x){x={id:'k'+uid(),name:c.name,phone:c.phone||'',email:c.email||'',address:c.address||'',notes:'',ownerId:ownerId||null,createdAt:Date.now()};S.customers.push(x)}
  else{x.name=c.name||x.name;x.phone=c.phone||x.phone;x.email=c.email||x.email;x.address=c.address||x.address;if(!x.ownerId&&ownerId)x.ownerId=ownerId}
  return x;
}

function createOrder(o){
  const pt=o.payType==='credito'?'credito':'contado';
  const base=o.items.filter(i=>i.qty>0).map(i=>{const p=prod(i.pid);if(!p)throw new Error('Producto no disponible');if(!p.active)throw new Error(p.name+' no está disponible');if(i.qty>p.stock)throw new Error('No hay stock suficiente de '+p.name);return{pid:p.id,name:p.name,price:salePrice(p),qty:i.qty}});
  if(!base.length)throw new Error('Agrega al menos un producto');
  const n0=pt==='credito'?+o.installments:0;
  const pick=!!o.pickup;if(pick&&!pickupOn())throw new Error('Recoger en tienda no está disponible por ahora');
  const mEff=pick?'presencial':o.method;
  const t0=calcTotals(base,o.couponCode,mEff,pick?'':o.zoneId,pt,n0);
  if(o.couponCode&&t0.couponErr)throw new Error(t0.couponErr);
  if(mEff!=='presencial'&&!o.zoneId)throw new Error('Elige la zona de entrega');
  const acct=o.customerId?S.customers.find(k=>k.id===o.customerId&&k.passHash)||null:null;
  const net0=t0.subtotal-t0.discount;
  let refDisc=0,friend=null,ptsUsed=0,ptsDisc=0;
  if(refOn()&&String(o.friendCode||'').trim()){
    const r=o.friendCtx||refResolve(o.friendCode,o.customer,S.customers,id=>S.orders.some(x=>x.customerId===id&&x.status!=='cancelado'));
    if(r.err)throw new Error(r.err);
    const mn=+S.settings.refFriendMin||0;if(net0<mn)throw new Error('El código de amiga aplica en compras desde '+money(mn));
    refDisc=Math.min(+S.settings.refFriend||0,net0);friend=r.owner;
  }
  if(loyOn()&&o.usePts&&acct){const m=ptsMax(acct,net0-refDisc);ptsUsed=m.pts;ptsDisc=m.disc}
  const t=calcTotals(base,o.couponCode,mEff,pick?'':o.zoneId,pt,n0,{pts:ptsDisc,ref:refDisc});
  let n=0,down=0;
  if(pt==='credito'){
    if(!S.settings.creditEnabled)throw new Error('El crédito no está disponible por ahora');
    const nc=base.filter(l=>!creditOkP(prod(l.pid)));if(nc.length)throw new Error('No se vende a crédito: '+nc.map(l=>l.name).join(', '));
    if(!acct)throw new Error('Inicia sesión para comprar a crédito');
    if(acct.creditBlocked)throw new Error('Tu crédito está suspendido. Comunícate con la tienda');
    if(o.method==='contraentrega')throw new Error('El crédito no aplica para contra entrega');
    n=+o.installments;if(!creditOptions().includes(n))throw new Error('Elige el número de cuotas');
    down=downOf(t.total,o.down);const lim=custLimit(acct);if(lim>0&&custOutstanding(acct.id)+t.total-down>lim)throw new Error('Superas tu cupo de crédito ('+money(lim)+')');
  }
  const lines=base.map(l=>({pid:l.pid,name:l.name,price:unitPrice(l,pt,n0),basePrice:l.price,qty:l.qty}));
  let emp=o.userId?userById(o.userId):null;
  if(!emp&&o.refCode)emp=S.users.find(u=>u.active&&u.code.toUpperCase()===String(o.refCode).toUpperCase())||null;
  if(emp&&emp.role==='admin'&&!o.userId)emp=null;
  const pct=emp&&emp.role!=='cajero'?(emp.commission??S.settings.defaultCommission):0;
  const net=t.subtotal-t.discount;
  const zone=pick?null:S.zones.find(z=>z.id===o.zoneId);
  const cust0=acct||(o.channel==='mostrador'&&!String(o.customer.phone||'').trim()?{id:null}:upsertCustomer(o.customer,emp&&emp.role==='vendedor'?emp.id:null));
  if(acct&&mEff!=='presencial'&&o.customer.address&&!acct.address)acct.address=o.customer.address;
  S.counter++;
  const now=Date.now();
  const ord={id:'o'+uid(),number:'GT-'+String(S.counter).padStart(5,'0'),date:now,lines,subtotal:t.subtotal,discount:t.discount,couponCode:t.coupon?t.coupon.code:'',shipping:t.shipping,total:t.total,
    payType:pt,installments:n,ratePct:pt==='credito'?ratePct(n):0,downPayment:down,plan:pt==='credito'?buildCreditPlan(t.total,n,now,down):null,creditExtra:pt==='credito'?t.extra:0,
    cur:(()=>{const x=o.cur&&o.cur!==baseCur()?curList().find(k=>k.code===o.cur):null;return x?{code:x.code,rate:+x.rate}:null})(),method:o.method,pickup:pick,zoneName:zone&&mEff!=='presencial'?zone.name:'',customerId:cust0.id,customer:{name:o.customer.name,phone:o.customer.phone,email:o.customer.email,address:o.method!=='presencial'?o.customer.address:''},
    employeeId:emp?emp.id:null,viaLink:!!(o.refCode&&!o.userId&&emp),commissionPct:pct,commissionAmt:emp?Math.round(net*pct/100):0,commissionPaid:false,
    channel:o.channel==='mostrador'?'mostrador':'',payMethod:o.channel==='mostrador'?(PAYM[o.payMethod]?o.payMethod:'efectivo'):'',cashId:o.channel==='mostrador'?(o.cashId||null):null,
    status:(pt!=='credito'&&o.paid)?'pagado':'pendiente',paidAt:(pt!=='credito'&&o.paid)?now:null,note:o.note||'',
    loyDisc:t.loy||0,ptsUsed,ptsDisc,refDisc,friendCode:friend?String(o.friendCode).trim().toUpperCase():'',refBy:friend?friend.id:''};
  if(!API){lines.forEach(l=>{prod(l.pid).stock-=l.qty});if(t.coupon)t.coupon.used++}
  else ord._in={cur:o.cur||'',items:o.items,customer:o.customer,method:o.method,zoneId:o.zoneId,couponCode:o.couponCode,refCode:o.refCode,note:o.note,payType:o.payType,installments:o.installments,down:o.down,paid:o.paid,customerId:o.customerId,channel:o.channel,payMethod:o.payMethod,cashId:o.cashId,friendCode:o.friendCode,usePts:o.usePts,cartId:o.cartId,pickup:o.pickup};
  S.orders.unshift(ord);
  if(ptsUsed&&acct)addPts(acct,-ptsUsed,'Canje en '+ord.number);
  if(ord.status==='pagado')awardPoints(ord);
  if(pt==='credito'&&!isStaff(me()))notify('staff','Nueva compra a crédito',`${o.customer.name} · ${ord.number} · ${money(ord.total)} en ${n} cuotas${down?' con cuota inicial de '+money(down):''}. Espera tu aprobación.`,'creditos');
  return ord;
}
const fiscalTxt=()=>{const t=S.settings||{};const a=[];if(t.legalName)a.push(t.legalName);if(t.nit)a.push('NIT '+t.nit);if(t.taxRegime)a.push(t.taxRegime);return a.join(' · ')};
const fiscalHtml=()=>{const t=S.settings||{},x=fiscalTxt();return x?`<div class="c fisc"><b>${esc(x)}</b></div>`:''};
const CARRIERS=['Servientrega','Interrapidísimo','Coordinadora','Envía','TCC','Deprisa','4-72','Domiciliario propio','Otra'];
const SHIPST={preparando:'Preparando tu pedido',enviado:'Enviado',listo:'Listo para recoger',entregado:'Entregado'};
function trackSteps(o){
  if(o.status==='cancelado')return [['Pedido recibido',true],['Pedido cancelado',true]];
  const sh=o.ship||{},st=o.status,stage=sh.stage||(st==='entregado'?'entregado':'');
  const paid=st==='pagado'||st==='entregado'||(o.payType==='credito'&&st==='credito');
  const pk=!!o.pickup,L=pk?['preparando','listo','entregado']:['preparando','enviado','entregado'],i=L.indexOf(stage);
  return [['Pedido recibido',true],[o.payType==='credito'?'Crédito aprobado':'Pago confirmado',paid],['Preparando tu pedido',i>=0],[pk?'Listo para recoger':'Enviado',i>=1],['Entregado',i>=2]];
}
function trackLast(o){const sh=o.ship||{};const h=(sh.hist||[]).filter(x=>x.note).slice(-1)[0];return h?h.note:''}
function trackHtml(o){
  const st=trackSteps(o),cur=st.map(x=>x[1]).lastIndexOf(true),sh=o.ship||{};
  return `<div class="trk"><h4 style="margin:0 0 10px;font-family:var(--f-display);font-weight:400;font-size:20px">Seguimiento del pedido</h4><ol>${st.map((x,i)=>`<li class="${x[1]?'done':''} ${i===cur?'now':''}"><span class="dot"></span>${esc(x[0])}</li>`).join('')}</ol>
  ${sh.carrier||sh.guide?`<p class="trkinfo">${sh.carrier?`<span>Transportadora</span> <b>${esc(sh.carrier)}</b>`:''}${sh.guide?` · <span>Número de guía</span> <b>${esc(sh.guide)}</b>`:''}${sh.url&&/^https?:\/\//i.test(sh.url)?` · <a href="${esc(sh.url)}" target="_blank" rel="noopener">Rastrear envío</a>`:''}</p>`:''}
  ${o.pickup&&pickupTxt()?`<p class="trkinfo"><span>Recoges en</span> <b>${esc(pickupTxt())}</b>${S.settings.pickupMap&&/^https?:\/\//i.test(S.settings.pickupMap)?` · <a href="${esc(S.settings.pickupMap)}" target="_blank" rel="noopener">Ver mapa</a>`:''}</p>`:''}
  ${trackLast(o)?`<p class="muted sm-t" style="margin:6px 0 0">${esc(trackLast(o))}</p>`:''}</div>`;
}
function emailOrder(o,kind){return inOrder(o,()=>{
  const s=S.settings||{},nm=s.storeName||'GOLDEN TOUCH',cr=o.payType==='credito',sh=o.ship||{};
  const T={nuevo:cr?'Recibimos tu solicitud de crédito':'¡Recibimos tu pedido!',pagado:'Confirmamos tu pago',preparando:'Estamos preparando tu pedido',enviado:'Tu pedido va en camino',entregado:'Tu pedido fue entregado',listo:'Tu pedido está listo para recoger'};
  const msg={nuevo:cr?'La tienda revisará tu solicitud y te contactará por WhatsApp.':o.method==='transferencia'?'Haz la transferencia con los datos de abajo y envíanos el comprobante por WhatsApp.':o.method==='online'?'Paga en línea desde el enlace de tu factura o escríbenos por WhatsApp.':o.method==='contraentrega'?'Pagarás en efectivo al domiciliario cuando recibas tu pedido.':o.pickup&&o.method==='presencial'?'Pagas y recoges en la tienda. Te avisamos cuando esté listo.':'Te escribiremos por WhatsApp para coordinar tu pedido.',pagado:'Recibimos tu pago. Pronto empezamos a preparar tu pedido.',preparando:'Estamos alistando tu pedido con mucho cuidado.',enviado:'Tu pedido ya salió. Aquí tienes los datos para rastrearlo.',entregado:'Gracias por tu compra. ¡Esperamos que la disfrutes!',listo:'Ya puedes pasar por él. Aquí tienes la dirección y el horario.'}[kind]||'';
  const pkl=o.pickup?pickupTxt():'';
  const subj=(kind==='nuevo'&&s.mailSubject?String(s.mailSubject).replace(/\{n\}/g,o.number):'')||(T[kind]+' · '+o.number);
  const ban=kind==='nuevo'&&o.method==='transferencia'?(s.banks||[]).filter(b=>b.name||b.number):[];
  const rows=o.lines.map(l=>[l.name,l.qty,money(l.price*l.qty)]);
  const trk=trackSteps(o).map(x=>(x[1]?'✔ ':'○ ')+x[0]).join('\n');
  const trkLine=(sh.carrier?'Transportadora: '+sh.carrier:'')+(sh.guide?(sh.carrier?' · ':'')+'Guía: '+sh.guide:'')+(sh.url&&/^https?:\/\//i.test(sh.url)?'\nRastrear: '+sh.url:'');
  const text=[T[kind],'',`Hola ${o.customer.name},`,msg,'',`Pedido ${o.number}`,...rows.map(r=>`- ${r[0]} x${r[1]}: ${r[2]}`),...(isPick(o)?[]:[`Domicilio: ${money(o.shipping)}`]),`Total: ${money(o.total)}`,...(pkl?['','Recoger en: '+pkl+(s.pickupNote?'\n'+s.pickupNote:'')]:[]),'',kind==='nuevo'?'':trk,trkLine,trackLast(o),...(ban.length?['','Datos para transferir:',...ban.map(b=>`${b.name}: ${b.number}`)]:[]),s.mailNote?'\n'+s.mailNote:'','',nm,fiscalTxt(),[s.whatsapp&&'WhatsApp '+s.whatsapp,s.email].filter(Boolean).join(' · ')].filter(x=>x!==undefined&&x!==null).join('\n').replace(/\n{3,}/g,'\n\n');
  const td='padding:8px 0;border-bottom:1px solid #eadfc4';
  const html=`<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#2a2418"><div style="background:#0b0a08;color:#c9a45c;text-align:center;padding:22px;letter-spacing:.2em;font-size:18px">${esc(nm)}</div><div style="padding:22px 18px"><h2 style="margin:0 0 8px;font-weight:400">${esc(T[kind])}</h2><p style="margin:0 0 16px">Hola ${esc(o.customer.name)}, ${esc(msg)}</p>
  <p style="margin:0 0 6px"><b>Pedido ${esc(o.number)}</b></p><table style="width:100%;border-collapse:collapse;font-size:14px">${rows.map(r=>`<tr><td style="${td}">${esc(r[0])}</td><td style="${td};text-align:center">×${r[1]}</td><td style="${td};text-align:right">${esc(r[2])}</td></tr>`).join('')}${isPick(o)?'':`<tr><td style="${td}" colspan="2">Domicilio</td><td style="${td};text-align:right">${esc(money(o.shipping))}</td></tr>`}<tr><td colspan="2" style="padding:10px 0"><b>Total</b></td><td style="padding:10px 0;text-align:right"><b>${esc(money(o.total))}</b></td></tr></table>
  ${kind!=='nuevo'?`<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px">${trackSteps(o).map(x=>`<div style="padding:3px 0;color:${x[1]?'#2a2418':'#9a8f78'}">${x[1]?'✔':'○'} ${esc(x[0])}</div>`).join('')}${trkLine?`<div style="margin-top:8px"><b>${esc(trkLine).replace(/\n/g,'<br>')}</b></div>`:''}${trackLast(o)?`<div style="margin-top:6px">${esc(trackLast(o))}</div>`:''}</div>`:''}
  ${pkl?`<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px"><b>Recoge tu pedido en tienda</b><div>${esc(pkl)}</div>${s.pickupNote?`<div style="margin-top:6px">${esc(s.pickupNote)}</div>`:''}${s.pickupMap&&/^https?:\/\//i.test(s.pickupMap)?`<div style="margin-top:6px"><a href="${esc(s.pickupMap)}">Ver mapa</a></div>`:''}</div>`:''}
  ${ban.length?`<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px"><b>Datos para tu transferencia</b>${ban.map(b=>`<div>${esc(b.name)}: <b>${esc(b.number)}</b></div>`).join('')}</div>`:''}
  ${s.mailNote?`<p style="font-size:14px">${esc(s.mailNote)}</p>`:''}<p style="font-size:13px;color:#7a6f58;margin-top:22px">${esc(nm)}${fiscalTxt()?' · '+esc(fiscalTxt()):''}${s.whatsapp?' · WhatsApp '+esc(s.whatsapp):''}${s.email?' · '+esc(s.email):''}</p></div></div>`;
  return {subject:subj,html,text};
})}
/* ============ recoger en tienda y avisos por WhatsApp ============ */
const pickupOn=()=>!!(S.settings&&S.settings.pickupOn);
const isPick=o=>o.method==='presencial'||!!o.pickup;
function pickupTxt(){const s=S.settings||{};return [s.pickupName,s.pickupAddr,s.pickupHours&&('Horario: '+s.pickupHours)].filter(Boolean).join(' · ')}
const WA_KINDS=[['nuevo','Pedido recibido'],['pagado','Pago confirmado'],['preparando','Preparando el pedido'],['enviado','Pedido enviado'],['listo','Listo para recoger en tienda'],['entregado','Pedido entregado']];
function waKindOf(o){const st=o.ship&&o.ship.stage;if(st)return st;if(o.status==='entregado')return 'entregado';if(o.status==='pagado')return 'pagado';return 'nuevo'}
function waParts(o,kind){return inOrder(o,()=>{
  const s=S.settings||{},sh=o.ship||{},pk=pickupTxt(),first=String(o.customer.name||'').split(' ')[0]||'';
  const trk=[sh.carrier&&('Transportadora '+sh.carrier),sh.guide&&('guía '+sh.guide),sh.url&&/^https?:\/\//i.test(sh.url)&&sh.url].filter(Boolean).join(' · ');
  const head={nuevo:'recibimos tu pedido',pagado:'confirmamos el pago de tu pedido',preparando:'estamos preparando tu pedido',enviado:'tu pedido va en camino',listo:'tu pedido está listo para recoger',entregado:'tu pedido fue entregado'}[kind]||'novedades de tu pedido';
  const detail={nuevo:'Total '+money(o.total)+(o.pickup&&pk?' · Lo recoges en '+pk:''),pagado:'Total pagado '+money(o.total),preparando:'Lo estamos alistando con mucho cuidado',enviado:trk||'Pronto recibirás los datos de rastreo',listo:(pk||'Pasa por la tienda')+(s.pickupNote?' · '+s.pickupNote:''),entregado:'¡Gracias por tu compra!'}[kind]||'—';
  const dt=detail+(trackLast(o)&&kind!=='nuevo'?' · '+trackLast(o):'');
  return{name:first,num:o.number,head,detail:dt,text:`Hola ${first}, ${head} ${o.number}. ${dt}`};
})}
const waText=(o,kind)=>waParts(o,kind||waKindOf(o)).text;
/* ============ fidelización: puntos y referidos ============ */
const loyOn=()=>!!(S.settings&&S.settings.loyOn),refOn=()=>!!(S.settings&&S.settings.refOn);
const ptVal=()=>Math.max(1,+S.settings.loyVal||50);
const refCodeOf=k=>k&&k.id?'GT-'+String(k.id).replace(/[^A-Za-z0-9]/g,'').slice(-6).toUpperCase():'';
function addPts(k,n,why){k.pts=Math.max(0,Math.floor((k.pts||0)+n));k.ptsLog=k.ptsLog||[];k.ptsLog.unshift({d:Date.now(),n,why});if(k.ptsLog.length>60)k.ptsLog.length=60}
function ptsMax(k,net){
  if(!loyOn()||!k)return{pts:0,disc:0};
  const bal=Math.floor(k.pts||0),min=Math.max(1,+S.settings.loyMin||1),pc=Math.min(100,Math.max(1,+S.settings.loyMaxPct||50));
  const pts=Math.min(bal,Math.floor(Math.max(0,net)*pc/100/ptVal()));
  return pts>=min?{pts,disc:pts*ptVal()}:{pts:0,disc:0};
}
function ptsEarn(o){return Math.max(0,Math.floor((o.subtotal-o.discount-(o.loyDisc||0))/1000*(+S.settings.loyEarn||0)))}
function refResolve(code,c,K,hasOrd){
  code=String(code||'').trim().toUpperCase();if(!code)return{};
  const own=(K||[]).find(k=>refCodeOf(k)===code);
  if(!own)return{err:'Ese código de amiga no existe'};
  const ph=phoneDigits(c&&c.phone),em=String((c&&c.email)||'').trim().toLowerCase();
  const mine=(K||[]).filter(k=>(ph&&phoneDigits(k.phone)===ph)||(em&&String(k.email||'').toLowerCase()===em));
  if(mine.some(k=>k.id===own.id))return{err:'No puedes usar tu propio código'};
  if(mine.some(k=>hasOrd&&hasOrd(k.id)))return{err:'El código de amiga es solo para tu primera compra'};
  return{owner:{id:own.id,name:own.name}};
}
function awardPoints(o){
  if(!o||o.ptsDone||!isPaid(o))return;
  const k=S.customers.find(x=>x.id===o.customerId);
  if(loyOn()&&k){const p=ptsEarn(o);if(p>0){addPts(k,p,'Compra '+o.number);o.ptsGot=p}}
  if(refOn()&&o.refBy){const f=S.customers.find(x=>x.id===o.refBy),b=Math.floor(+S.settings.refInviterPts||0);if(f&&b>0){addPts(f,b,'Tu amiga '+String(o.customer.name||'').split(' ')[0]+' hizo su primera compra');o.ptsBonus=b}}
  o.ptsDone=true;
}
function unAward(o){
  const k=S.customers.find(x=>x.id===o.customerId),f=o.refBy?S.customers.find(x=>x.id===o.refBy):null;
  if(o.ptsDone){if(k&&o.ptsGot)addPts(k,-o.ptsGot,'Pedido cancelado '+o.number);if(f&&o.ptsBonus)addPts(f,-o.ptsBonus,'Pedido de tu amiga cancelado');o.ptsDone=false;o.ptsGot=0;o.ptsBonus=0}
  if(o.ptsUsed&&!o.ptsBack){if(k)addPts(k,o.ptsUsed,'Puntos devueltos '+o.number);o.ptsBack=true}
}
const cartMsgDefault='Hola {nombre}, vimos que dejaste estas joyas en tu carrito. Siguen esperándote: {productos}. Complétalo aquí: {link}';
function cartText(c){
  const s=S.settings||{},nm=String(c.name||'').split(' ')[0]||'';
  const prods=(c.items||[]).map(i=>i.name+(i.qty>1?' x'+i.qty:'')).join(', ');
  let t=String(s.cartMsg||cartMsgDefault).replace(/\{nombre\}/g,nm).replace(/\{productos\}/g,prods).replace(/\{link\}/g,c.url||'');
  if(s.cartCode)t+=' Usa el cupón '+s.cartCode+(s.cartCodeNote?' ('+s.cartCodeNote+')':'')+'.';
  return t;
}
function emailCart(c){
  const s=S.settings||{},nm=s.storeName||'GOLDEN TOUCH',text=cartText(c);
  const rows=(c.items||[]).map(i=>`<tr><td style="padding:8px 0;border-bottom:1px solid #eadfc4">${esc(i.name)}</td><td style="padding:8px 0;border-bottom:1px solid #eadfc4;text-align:center">×${i.qty}</td><td style="padding:8px 0;border-bottom:1px solid #eadfc4;text-align:right">${esc(money(i.price*i.qty))}</td></tr>`).join('');
  const html=`<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#2a2418"><div style="background:#0b0a08;color:#c9a45c;text-align:center;padding:22px;letter-spacing:.2em;font-size:18px">${esc(nm)}</div><div style="padding:22px 18px"><h2 style="margin:0 0 8px;font-weight:400">¿Olvidaste algo?</h2><p style="font-size:14px">${esc(text.replace(c.url||'\u0000','').trim())}</p><table style="width:100%;border-collapse:collapse;font-size:14px">${rows}</table>${c.url?`<p style="text-align:center;margin:22px 0"><a href="${esc(c.url)}" style="background:#c9a45c;color:#0b0a08;text-decoration:none;padding:12px 26px;letter-spacing:.1em;font-size:13px">COMPLETAR MI COMPRA</a></p>`:''}<p style="font-size:12px;color:#7a6f58">${esc(nm)}${s.whatsapp?' · WhatsApp '+esc(s.whatsapp):''}</p></div></div>`;
  return{subject:String(s.cartSubject||'Tu carrito te está esperando · '+nm),html,text};
}

 return {setState:s=>{S=s},migrate:()=>migrate(),createOrder:o=>createOrder(o),emailOrder:(o,k)=>emailOrder(o,k),emailCart:c=>emailCart(c),refResolve:(c,x,K,h)=>refResolve(c,x,K,h),salePrice:p=>salePrice(p),waParts:(o,k)=>waParts(o,k),phoneDigits:p=>phoneDigits(p)};
}

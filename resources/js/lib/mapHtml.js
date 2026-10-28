// Halaman peta Leaflet untuk WebView/iframe. Tampilan dan kontrolnya meniru peta modern (Google Maps): peta dasar Jalan,
// Satelit, dan Medan; tombol lapisan, zoom, dan "lokasi saya"; skala; penanda bernomor; pin untuk gempa terpilih.
// Data masuk lewat postMessage; ketukan penanda dan permintaan lokasi dikirim balik. Teks dari data tidak pernah
// disisipkan sebagai HTML (hanya textContent). Tidak ada backtick atau tanda dolar-kurung di dalam skrip ini.
export const MAP_HTML = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
html,body,#m{height:100%;margin:0;font-family:Roboto,Arial,Helvetica,sans-serif}
.leaflet-container{background:#e5e3df;font-family:inherit}
.btn{width:40px;height:40px;background:#fff;border:0;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;color:#444}
.btn:active{background:#f1f3f4}
#rc{position:absolute;z-index:1000;top:10px;right:10px;display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.zoom{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden}
.zoom button{width:40px;height:40px;border:0;background:#fff;font-size:22px;color:#444;cursor:pointer}
.zoom button+button{border-top:1px solid #e0e0e0}
.zoom button:active{background:#f1f3f4}
#lp{position:absolute;z-index:1001;top:10px;right:58px;background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.3);padding:10px;display:none}
#lp.open{display:block}
#lp h4{margin:0 0 8px;font-size:12px;color:#5f6368;font-weight:500}
.opts{display:flex;gap:10px}
.opt{border:0;background:none;padding:0;text-align:center;cursor:pointer;font:inherit;font-size:11px;color:#5f6368}
.opt img{display:block;width:56px;height:56px;border-radius:8px;border:2px solid transparent;object-fit:cover;background:#ddd}
.opt.on img{border-color:#1a73e8}.opt.on{color:#1a73e8;font-weight:700}
#chips{position:absolute;z-index:1000;top:10px;left:10px;display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.chip{min-height:36px;padding:0 14px;background:#fff;border:0;border-radius:18px;box-shadow:0 1px 4px rgba(0,0,0,.3);font:inherit;font-size:13px;font-weight:500;color:#5f6368;cursor:pointer}
.chip.on{background:#1a73e8;color:#fff}
.lg{background:#ffffffee;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.3);padding:6px 10px;font-size:11px;color:#202124;line-height:1.7}
.lg i{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:-1px}
#warn{position:absolute;z-index:1002;left:10px;right:10px;bottom:10px;background:#fff3e0;border:1px solid #e65100;border-radius:8px;padding:8px 10px;font-size:13px;display:none}
.eq{display:flex;align-items:center;justify-content:center;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);color:#fff;font-weight:700;font-size:11px;line-height:1;box-sizing:border-box;text-shadow:0 0 2px rgba(0,0,0,.35)}
.camp{width:22px;height:22px;background:#00796b;border:2px solid #fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.4);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;box-sizing:border-box}
.pin{position:relative;width:0;height:0}
.pin .p{position:absolute;left:-17px;top:-44px;width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.45);box-sizing:border-box}
.pin .n{position:absolute;left:-17px;top:-44px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;z-index:2}
.pin .r{position:absolute;left:-9px;top:-9px;width:18px;height:18px;border-radius:50%;border:2px solid var(--c);box-sizing:border-box;opacity:0;animation:pr 2.4s ease-out infinite}
.pin .r+.r{animation-delay:1.2s}
@keyframes pr{0%{transform:scale(.6);opacity:.9}100%{transform:scale(4);opacity:0}}
.me{width:16px;height:16px;border-radius:50%;background:#1a73e8;border:3px solid #fff;box-shadow:0 0 0 2px rgba(26,115,232,.35),0 1px 4px rgba(0,0,0,.4);box-sizing:border-box}
.leaflet-control-attribution{font-size:9px;background:#ffffffcc}
.leaflet-control-scale-line{font-size:10px;background:#ffffffcc}
.leaflet-tooltip{font-size:12px;border-radius:6px;border:0;box-shadow:0 1px 4px rgba(0,0,0,.35)}
@media (prefers-reduced-motion:reduce){.pin .r{animation:none;opacity:.3;transform:scale(2)}}
.eq{animation:pop .45s cubic-bezier(.2,1.4,.4,1) both;transition:transform .15s ease,box-shadow .15s ease;cursor:pointer}
.eq:hover{transform:scale(1.22);box-shadow:0 3px 10px rgba(0,0,0,.5)}
.camp{animation:pop .45s cubic-bezier(.2,1.4,.4,1) both;transition:transform .15s ease}
.camp:hover{transform:scale(1.2)}
.pin .p{animation:drop .5s cubic-bezier(.3,1.5,.5,1) both}
.me::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:2px solid #1a73e8;animation:pr 2s ease-out infinite}
.me{position:relative}
@keyframes pop{0%{transform:scale(0);opacity:0}100%{transform:scale(1);opacity:1}}
@keyframes drop{0%{transform:translateY(-26px) rotate(-45deg);opacity:0}100%{transform:translateY(0) rotate(-45deg);opacity:1}}
@media (prefers-reduced-motion:reduce){.eq,.camp,.pin .p,.me::after{animation:none}.eq:hover,.camp:hover{transform:none}}
</style></head>
<body><div id="m"></div>
<div id="chips"></div>
<div id="rc">
 <button class="btn" id="bl" aria-label="Pilih peta dasar"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#444" stroke-width="1.8" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/></svg></button>
 <div class="zoom"><button id="zi" aria-label="Perbesar">+</button><button id="zo" aria-label="Perkecil">&minus;</button></div>
 <button class="btn" id="bm" aria-label="Lokasi saya"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#444" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"/><circle cx="12" cy="12" r="7.5"/><path d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3"/></svg></button>
</div>
<div id="lp"><h4>Jenis peta</h4><div class="opts" id="opts"></div></div>
<div id="warn"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function send(o){var s=JSON.stringify(o);if(window.ReactNativeWebView){window.ReactNativeWebView.postMessage(s)}else{parent.postMessage(s,'*')}}
function warn(t,ms){var w=document.getElementById('warn');w.textContent=t;w.style.display='block';if(ms)setTimeout(function(){w.style.display='none'},ms)}
if(typeof L==='undefined'){warn('Pustaka peta belum termuat. Periksa koneksi internet; daftar gempa tetap tersedia.');send({type:'error'})}
else{
var map=L.map('m',{zoomControl:false,attributionControl:false,minZoom:3,maxZoom:19,zoomSnap:0.5}).setView([-2.5,118],5);
L.control.attribution({prefix:false,position:'bottomleft'}).addTo(map);
L.control.scale({imperial:false,position:'bottomleft',maxWidth:90}).addTo(map);
var A='https://server.arcgisonline.com/ArcGIS/rest/services/';
var attr='Peta: Esri, HERE, Garmin, &copy; OpenStreetMap contributors';
var BASES={
 jalan:{label:'Jalan',thumb:A+'World_Street_Map/MapServer/tile/5/15/26',layers:[L.tileLayer(A+'World_Street_Map/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:attr})]},
 satelit:{label:'Satelit',thumb:A+'World_Imagery/MapServer/tile/5/15/26',layers:[L.tileLayer(A+'World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Citra: Esri, Maxar, Earthstar Geographics'}),L.tileLayer(A+'Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',{maxZoom:19})]},
 medan:{label:'Medan',thumb:A+'World_Topo_Map/MapServer/tile/5/15/26',layers:[L.tileLayer(A+'World_Topo_Map/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:attr})]}
};
var cur=null;
function setBase(k){if(!BASES[k])return;if(cur)BASES[cur].layers.forEach(function(l){map.removeLayer(l)});cur=k;BASES[k].layers.forEach(function(l,i){l.addTo(map);if(i===0)l.bringToBack()});
 var o=document.querySelectorAll('.opt');for(var i=0;i<o.length;i++){var on=o[i].getAttribute('data-k')===k;o[i].className='opt'+(on?' on':'');o[i].setAttribute('aria-pressed',on?'true':'false')}
 try{localStorage.setItem('igi.base',k)}catch(e){}}
BASES.jalan.layers[0].on('tileerror',function(){warn('Peta dasar belum termuat. Data gempa tetap tampil.',4000)});
var opts=document.getElementById('opts');
Object.keys(BASES).forEach(function(k){var b=document.createElement('button');b.className='opt';b.setAttribute('data-k',k);var im=document.createElement('img');im.src=BASES[k].thumb;im.alt='';b.appendChild(im);var t=document.createElement('div');t.textContent=BASES[k].label;b.appendChild(t);b.onclick=function(){setBase(k);document.getElementById('lp').className=''};opts.appendChild(b)});
document.getElementById('bl').onclick=function(){var p=document.getElementById('lp');p.className=p.className==='open'?'':'open'};
document.getElementById('zi').onclick=function(){map.zoomIn()};document.getElementById('zo').onclick=function(){map.zoomOut()};
document.getElementById('bm').onclick=function(){send({type:'locate'})};
var saved=null;try{saved=localStorage.getItem('igi.base')}catch(e){}
setBase(saved&&BASES[saved]?saved:'jalan');

var evLayer=L.layerGroup().addTo(map),campLayer=L.layerGroup().addTo(map),faultLayer=L.layerGroup(),selLayer=L.layerGroup().addTo(map),meLayer=L.layerGroup().addTo(map);
var faultsLoaded=false,byId={};
function col(m){return m>=6.5?'#b71c1c':m>=5.5?'#d32f2f':m>=4.5?'#e65100':m>=4?'#b7791f':'#2e7d32'}
function tip(a,b){var r=document.createElement('div');var s=document.createElement('strong');s.textContent=a;r.appendChild(s);if(b){var t=document.createElement('div');t.textContent=b;r.appendChild(t)}return r}
function draw(d){evLayer.clearLayers();campLayer.clearLayers();byId={};
 (d.events||[]).forEach(function(f){var c=f.geometry.coordinates,m=f.properties.magnitude||0;var s=Math.round(18+Math.max(0,m-3)*5);
  var ic=L.divIcon({className:'',html:'<div class="eq" style="width:'+s+'px;height:'+s+'px;background:'+col(m)+'">'+m.toFixed(1)+'</div>',iconSize:[s,s],iconAnchor:[s/2,s/2]});
  var k=L.marker([c[1],c[0]],{icon:ic,zIndexOffset:Math.round(m*100),keyboard:false}).addTo(evLayer);
  k.bindTooltip(tip('M '+m.toFixed(1),f.properties.location||''),{direction:'top',offset:[0,-s/2]});
  k.on('click',function(){send({type:'event',id:f.id})});byId[f.id]=c});
 (d.camps||[]).forEach(function(f){var c=f.geometry.coordinates;
  L.marker([c[1],c[0]],{icon:L.divIcon({className:'',html:'<div class="camp">P</div>',iconSize:[22,22],iconAnchor:[11,11]}),keyboard:false}).bindTooltip(tip(f.properties.name,f.properties.current_occupants+' / '+f.properties.capacity_people+' orang tercatat'),{direction:'top',offset:[0,-10]}).addTo(campLayer)})}
function fly(ll,z){map.invalidateSize();var s=map.getSize();if(!s.x||!s.y||isNaN(ll.lat)||isNaN(ll.lng))return;map.flyTo(ll,z,{duration:.8})}
function select(id,bottom,mag){selLayer.clearLayers();var c=byId[id];if(!c)return;var m=mag||0,cc=col(m);
 L.marker([c[1],c[0]],{icon:L.divIcon({className:'',html:'<div class="pin" style="--c:'+cc+'"><i class="r"></i><i class="r"></i><div class="p" style="background:'+cc+'"></div><div class="n">'+m.toFixed(1)+'</div></div>',iconSize:[0,0]}),interactive:false,keyboard:false,zIndexOffset:5000}).addTo(selLayer);
 var z=Math.max(map.getZoom(),6.5);var p=map.project([c[1],c[0]],z).add([0,(bottom||0)/2]);
 fly(map.unproject(p,z),z)}
function me(lat,lng,acc,bottom){meLayer.clearLayers();
 if(acc&&acc>0)L.circle([lat,lng],{radius:acc,color:'#1a73e8',weight:1,fillColor:'#1a73e8',fillOpacity:.12,interactive:false}).addTo(meLayer);
 L.marker([lat,lng],{icon:L.divIcon({className:'',html:'<div class="me"></div>',iconSize:[16,16],iconAnchor:[8,8]}),interactive:false,keyboard:false}).addTo(meLayer);
 var z=Math.max(map.getZoom(),9);var p=map.project([lat,lng],z).add([0,(bottom||0)/2]);fly(map.unproject(p,z),z)}
function fit(bottom){map.fitBounds([[-11.5,94.5],[6.5,141.5]],{paddingBottomRight:[0,bottom||0],animate:true})}
function drawFaults(g){faultLayer.clearLayers();L.geoJSON(g,{style:function(){return{color:'#e65100',weight:1.5,opacity:.9,dashArray:'5, 5'}},onEachFeature:function(f,l){var p=f.properties||{};l.bindTooltip(tip(p.name||'Sesar',(p.slip_type||'')+(p.slip_rate_mm_yr!=null?' · '+p.slip_rate_mm_yr+' mm/tahun':'')),{sticky:true})}}).addTo(faultLayer);faultsLoaded=true}
var chips=document.getElementById('chips');
function chip(label,layer,on,needs){var b=document.createElement('button');b.className='chip'+(on?' on':'');b.textContent=label;b.setAttribute('aria-pressed',on?'true':'false');
 b.onclick=function(){var now=!map.hasLayer(layer);if(now){layer.addTo(map);if(needs&&!faultsLoaded)send({type:'need-faults'})}else{map.removeLayer(layer)}b.className='chip'+(now?' on':'');b.setAttribute('aria-pressed',now?'true':'false')};chips.appendChild(b)}
chip('Posko',campLayer,true,false);chip('Sesar aktif',faultLayer,false,true);
var lg=document.createElement('div');lg.className='lg';lg.innerHTML='<div style="font-weight:700">Magnitudo</div><div><i style="background:#2e7d32"></i>&lt; 4</div><div><i style="background:#b7791f"></i>4 - 4,5</div><div><i style="background:#e65100"></i>4,5 - 5,5</div><div><i style="background:#d32f2f"></i>5,5 - 6,5</div><div><i style="background:#b71c1c"></i>&ge; 6,5</div>';chips.appendChild(lg);
function onMsg(e){var d;try{d=JSON.parse(e.data)}catch(x){return}
 if(d.type==='data')draw(d);else if(d.type==='select')select(d.id,d.bottom,d.mag);else if(d.type==='fit')fit(d.bottom);else if(d.type==='faults')drawFaults(d.data);
 else if(d.type==='faults-error')warn('Data sesar belum dapat dimuat.',4000);else if(d.type==='me')me(d.lat,d.lng,d.acc,d.bottom);else if(d.type==='me-error')warn(d.message||'Lokasi tidak dapat ditentukan.',5000)}
document.addEventListener('message',onMsg);window.addEventListener('message',onMsg);
map.on('click',function(){document.getElementById('lp').className=''});
send({type:'ready'})}
</script></body></html>`;

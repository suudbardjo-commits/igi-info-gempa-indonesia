import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { SERVER_URL } from './config';
import type { Feature } from './data';

// Peta Leaflet (basemap Esri terang). Dijalankan di WebView pada ponsel dan di <iframe> pada pratinjau web. Data masuk lewat
// postMessage; ketukan penanda dan tombol layer dikirim balik. Teks lokasi dan nama sesar tidak pernah disisipkan sebagai
// HTML (hanya textContent).
const MAP_HTML = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
html,body,#m{height:100%;margin:0;font-family:Arial,Helvetica,sans-serif}
.leaflet-container{background:#dfe6ee}
#warn{position:absolute;z-index:999;left:8px;right:8px;bottom:8px;background:#fff3e0;border:1px solid #e65100;border-radius:6px;padding:8px;font-size:13px;display:none}
.tg{display:flex;flex-direction:column;gap:6px}
.tg button{min-width:78px;min-height:40px;padding:0 10px;background:#fff;border:1px solid #c7d3e0;border-radius:6px;font-size:13px;font-weight:700;color:#566677}
.tg button.on{background:#0b67b2;border-color:#0b67b2;color:#fff}
.lg{background:#ffffffee;border:1px solid #c7d3e0;border-radius:6px;padding:6px 8px;font-size:11px;color:#1b2733;line-height:1.7}
.lg i{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px;vertical-align:-1px}
.sel{position:relative;width:0;height:0}
.sel b{position:absolute;left:-9px;top:-9px;width:18px;height:18px;border-radius:50%;border:3px solid #0b67b2;background:#fff;box-sizing:border-box}
.sel i{position:absolute;left:-9px;top:-9px;width:18px;height:18px;border-radius:50%;border:2px solid #0b67b2;box-sizing:border-box;opacity:0;animation:p 2.4s ease-out infinite}
.sel i:nth-child(2){animation-delay:1.2s}
@keyframes p{0%{transform:scale(1);opacity:.9}100%{transform:scale(4.5);opacity:0}}
@media (prefers-reduced-motion:reduce){.sel i{animation:none;opacity:.3;transform:scale(2)}}
.leaflet-control-attribution{font-size:9px}
</style></head>
<body><div id="m"></div><div id="warn"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function send(o){var s=JSON.stringify(o);if(window.ReactNativeWebView){window.ReactNativeWebView.postMessage(s)}else{parent.postMessage(s,'*')}}
if(typeof L==='undefined'){document.getElementById('warn').style.display='block';document.getElementById('warn').textContent='Pustaka peta belum termuat. Periksa koneksi internet; daftar gempa tetap tersedia.';send({type:'error'})}
else{
var map=L.map('m',{zoomControl:false,attributionControl:false,minZoom:3}).setView([-2.5,118],5);
L.control.attribution({prefix:false,position:'bottomleft'}).addTo(map);
L.control.zoom({position:'topright'}).addTo(map);
var esri='https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/';
L.tileLayer(esri+'World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',{maxZoom:16,attribution:'Peta: Esri, HERE, Garmin, &copy; OpenStreetMap contributors'}).on('tileerror',function(){var w=document.getElementById('warn');w.style.display='block';w.textContent='Peta dasar belum termuat. Data gempa tetap tampil.'}).addTo(map);
L.tileLayer(esri+'World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}',{maxZoom:16,opacity:.9}).addTo(map);
var evLayer=L.layerGroup().addTo(map),campLayer=L.layerGroup().addTo(map),faultLayer=L.layerGroup(),selLayer=L.layerGroup().addTo(map);
var faultsLoaded=false;
function col(m){return m>=6.5?'#b71c1c':m>=5.5?'#d32f2f':m>=4.5?'#e65100':m>=4?'#b7791f':'#2e7d32'}
function tip(a,b){var r=document.createElement('div');var s=document.createElement('strong');s.textContent=a;r.appendChild(s);if(b){var t=document.createElement('div');t.textContent=b;r.appendChild(t)}return r}
var byId={};
function draw(d){evLayer.clearLayers();campLayer.clearLayers();byId={};
 (d.events||[]).slice().sort(function(a,b){return (b.properties.magnitude||0)-(a.properties.magnitude||0)}).forEach(function(f){var c=f.geometry.coordinates,m=f.properties.magnitude||0;
  var k=L.circleMarker([c[1],c[0]],{radius:4+m*1.5,color:'#ffffff',weight:1.5,fillColor:col(m),fillOpacity:.85}).addTo(evLayer);
  k.bindTooltip(tip('M '+m,f.properties.location||''),{direction:'top'});
  k.on('click',function(){send({type:'event',id:f.id})});byId[f.id]=c});
 (d.camps||[]).forEach(function(f){var c=f.geometry.coordinates;
  L.marker([c[1],c[0]],{icon:L.divIcon({className:'',html:'<div style="width:12px;height:12px;background:#00796b;border:2px solid #fff;box-sizing:border-box"></div>',iconSize:[12,12]})}).bindTooltip(tip(f.properties.name,f.properties.current_occupants+' / '+f.properties.capacity_people+' orang tercatat')).addTo(campLayer)})}
function select(id,bottom){selLayer.clearLayers();var c=byId[id];if(!c)return;
 L.marker([c[1],c[0]],{icon:L.divIcon({className:'',html:'<div class="sel"><i></i><i></i><b></b></div>',iconSize:[0,0]}),interactive:false,keyboard:false,zIndexOffset:1000}).addTo(selLayer);
 var z=Math.max(map.getZoom(),6);var p=map.project([c[1],c[0]],z).add([0,(bottom||0)/2]);
 map.flyTo(map.unproject(p,z),z,{duration:.8})}
function fit(bottom){map.fitBounds([[-11.5,94.5],[6.5,141.5]],{paddingBottomRight:[0,bottom||0],animate:true})}
function drawFaults(g){faultLayer.clearLayers();L.geoJSON(g,{style:function(){return{color:'#e65100',weight:1.3,opacity:.9,dashArray:'4, 4'}},onEachFeature:function(f,l){var p=f.properties||{};l.bindTooltip(tip(p.name||'Sesar',(p.slip_type||'')+(p.slip_rate_mm_yr!=null?' · '+p.slip_rate_mm_yr+' mm/tahun':'')),{sticky:true})}}).addTo(faultLayer);faultsLoaded=true}
var ctl=L.control({position:'topleft'});
ctl.onAdd=function(){var d=L.DomUtil.create('div','tg');L.DomEvent.disableClickPropagation(d);
 function btn(label,layer,name,on,needs){var b=document.createElement('button');b.textContent=label;if(on)b.className='on';b.onclick=function(){var now=!map.hasLayer(layer);if(now){layer.addTo(map);if(needs&&!faultsLoaded)send({type:'need-faults'})}else{map.removeLayer(layer)}b.className=now?'on':'';b.setAttribute('aria-pressed',now?'true':'false')};b.setAttribute('aria-pressed',on?'true':'false');d.appendChild(b)}
 btn('Posko',campLayer,'posko',true,false);btn('Sesar',faultLayer,'sesar',false,true);
 var lg=document.createElement('div');lg.className='lg';lg.innerHTML='<div style="font-weight:700">Magnitudo</div><div><i style="background:#2e7d32"></i>&lt; 4</div><div><i style="background:#b7791f"></i>4 - 4,5</div><div><i style="background:#e65100"></i>4,5 - 5,5</div><div><i style="background:#d32f2f"></i>5,5 - 6,5</div><div><i style="background:#b71c1c"></i>&ge; 6,5</div><div style="margin-top:4px;color:#566677;font-size:9px;line-height:1.3">Peta: Esri, OpenStreetMap</div>';d.appendChild(lg);return d};
ctl.addTo(map);
function onMsg(e){var d;try{d=JSON.parse(e.data)}catch(x){return}
 if(d.type==='data')draw(d);else if(d.type==='select')select(d.id,d.bottom);else if(d.type==='fit')fit(d.bottom);else if(d.type==='faults')drawFaults(d.data);else if(d.type==='faults-error'){var w=document.getElementById('warn');w.style.display='block';w.textContent='Data sesar belum dapat dimuat.';setTimeout(function(){w.style.display='none'},4000)}}
document.addEventListener('message',onMsg);window.addEventListener('message',onMsg);
send({type:'ready'})}
</script></body></html>`;

type Props = { events: Feature[]; camps: Feature[]; selectedId: string | null; bottomInset: number; recenterKey: number; onSelect: (id: string) => void };

/** Peta penuh. Mengirim data ke peta bila siap, memusatkan pada gempa terpilih di area yang tidak tertutup sheet. */
export default function QuakeMap({ events, camps, selectedId, bottomInset, recenterKey, onSelect }: Props) {
    const web = useRef<WebView>(null);
    const frame = useRef<any>(null);
    const [ready, setReady] = useState(false);
    const isWeb = Platform.OS === 'web';

    const post = useCallback((msg: object) => {
        const text = JSON.stringify(msg);
        if (isWeb) frame.current?.contentWindow?.postMessage(text, '*');
        else web.current?.postMessage(text);
    }, [isWeb]);

    const handle = useCallback(async (raw: string) => {
        let m: any;
        try { m = JSON.parse(raw); } catch { return; }
        if (m.type === 'ready') setReady(true);
        else if (m.type === 'event') onSelect(m.id);
        else if (m.type === 'need-faults') {
            try {
                const r = await fetch(`${SERVER_URL}/data/sesar-indonesia.geojson`);
                if (!r.ok) throw new Error('http');
                post({ type: 'faults', data: await r.json() });
            } catch {
                post({ type: 'faults-error' });
            }
        }
    }, [onSelect, post]);

    // Pratinjau web: pesan dari <iframe> milik komponen ini saja.
    useEffect(() => {
        if (!isWeb) return;
        const fn = (e: MessageEvent) => { if (e.source === frame.current?.contentWindow && typeof e.data === 'string') handle(e.data); };
        window.addEventListener('message', fn);
        return () => window.removeEventListener('message', fn);
    }, [isWeb, handle]);

    useEffect(() => { if (ready) post({ type: 'data', events, camps }); }, [ready, events, camps, post]);
    useEffect(() => { if (ready && selectedId) post({ type: 'select', id: selectedId, bottom: bottomInset }); }, [ready, selectedId, bottomInset, recenterKey, post]);

    const html = useMemo(() => MAP_HTML, []);

    return (
        <View style={st.fill}>
            {isWeb ? (
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                (require('react') as any).createElement('iframe', { ref: frame, srcDoc: html, title: 'Peta gempa', style: { border: 0, width: '100%', height: '100%' } })
            ) : (
                <WebView
                    ref={web}
                    originWhitelist={['*']}
                    source={{ html, baseUrl: 'https://localhost' }}
                    style={st.fill}
                    onMessage={(e) => handle(e.nativeEvent.data)}
                    javaScriptEnabled
                    domStorageEnabled
                    setSupportMultipleWindows={false}
                />
            )}
        </View>
    );
}

const st = StyleSheet.create({ fill: { flex: 1, backgroundColor: '#dfe6ee' } });

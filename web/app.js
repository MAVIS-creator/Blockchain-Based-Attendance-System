"use strict";
// Static frontend maintains the existing server-side identity and tab-away
// evidence conventions. PHP remains authoritative for every attendance rule.
const $ = id => document.getElementById(id);
const API = (() => {
  if (window.BBAS_STUDENT_ATTENDANCE_ENABLED !== true) return null;
  try {
    const url = new URL(window.BBAS_API_ORIGIN);
    if (url.protocol !== "https:" || url.username || url.password ||
        url.pathname !== "/" || url.search || url.hash) return null;
    return url.origin;
  } catch { return null; }
})();
const route = path => API + "/" + path;
const form = $("attendanceForm"), button = $("submitBtn");
const keys = {token:"attendance_token", strikes:"attendanceTabAwayStrikes",
  lock:"attendanceTabAwayLockUntil", bypass:"attendanceSubmissionBypass"};
const GRACE_MS = 6000, LOCK_MS = 15*60000, MAX_STRIKES = 3;
let context = null, ready = false, submitted = false, hiddenTimer = null;
let submitting = false, uncertain = false;
let statusTimer = null, refreshing = false, failedRefreshes = 0;

function message(text, type="") {
  const el = $("serviceMessage"); el.textContent = text;
  el.className = "notice" + (type ? " " + type : "");
}
function localGet(k) { try{return localStorage.getItem(k)}catch{return null} }
function localSet(k,v){try{localStorage.setItem(k,v)}catch{}}
function localRemove(k){try{localStorage.removeItem(k)}catch{}}
function parseBypass(){
  try {return JSON.parse(localGet(keys.bypass)||"null")} catch {return null}
}
function allowedBypass(){
  const b=parseBypass();
  return b && context && b.token===localGet(keys.token) && b.day===context.day && b.course===context.course;
}
function clearFence(){
  localRemove(keys.strikes);localRemove(keys.lock);localRemove("attendanceBlocked");
}
function lockCheck() {
  if (allowedBypass()) {clearFence();return false;}
  const until=Number(localGet(keys.lock)||0);
  if(until>Date.now()){message("This device is temporarily locked. Please retry after the existing 15-minute lock expires.","error");return true;}
  if(until){localRemove(keys.lock);localSet(keys.strikes,"0")}
  return false;
}
function setReady(ok) {
  ready=ok;
  button.disabled=!ready || submitting || submitted || uncertain || lockCheck();
}
function contextMessage(text, type) {
  if (!submitting && !submitted && !uncertain) message(text, type);
}
async function getJSON(path){
  const controller=new AbortController();
  const timeout=setTimeout(() => controller.abort(), 8000);
  try {
    const response=await fetch(route(path),{cache:"no-store",mode:"cors",credentials:"omit",signal:controller.signal});
    if(!response.ok)throw Error("The attendance server is unavailable ("+response.status+").");
    return await response.json();
  } finally {
    clearTimeout(timeout);
  }
}
async function refreshContext(){
  if (refreshing || document.hidden) return;
  refreshing=true;
  try{
    const next=await getJSON("public_context_api.php");
    failedRefreshes=0;
    context={...next, clockOffset:Number(next.server_time)*1000-Date.now()};
    const announcement=next.announcement;
    const el=$("announcement");
    const text=announcement && announcement.enabled ? String(announcement.message||"").trim() : "";
    el.textContent=text;
    el.classList.toggle("hidden", !text);
    $("courseTitle").textContent=next.course||"Student Attendance";
    $("action").value=next.action||"";
    $("course").value=next.course||"General";
    $("modeBadge").textContent=next.action ? next.action.toUpperCase()+" MODE" : "SESSION CLOSED";
    $("topStatus").textContent=next.active ? "Attendance active" : "Attendance closed";
    if(next.active && next.end_time && Number(next.end_time)<=Number(next.server_time)){setReady(false);contextMessage("This attendance session has ended.","error");return;}
    if(!next.active) {$("sessionDescription").textContent="No active session";setReady(false); contextMessage("Attendance is closed. Check again when your class starts.");return;}
    $("sessionDescription").textContent="Session date: "+next.day+" · Ends at "+new Date(next.end_time*1000).toLocaleTimeString();
    setReady(Boolean($("fingerprint").value)&&!submitted);
    if (!lockCheck()) contextMessage("Check your details, then submit attendance once.");
  }catch(e){
    failedRefreshes++;
    setReady(false);
    contextMessage("Cannot reach the attendance server. Check your connection or try again when class starts.", "error");
  }finally{refreshing=false;}
}
function renderCountdown(){
  if (!context || !context.active || !context.end_time) return;
  const remaining=Math.max(0, Math.ceil((context.end_time*1000-Date.now()-context.clockOffset)/1000));
  $("sessionDescription").textContent=remaining ?
    "Time remaining " + Math.floor(remaining/60) + ":" + String(remaining%60).padStart(2,"0") :
    "This attendance session has ended.";
  if (!remaining) setReady(false);
}
async function fingerprint(){
  try{
    if(!window.FingerprintJS)throw Error("fingerprint library unavailable");
    const fp=await FingerprintJS.load(); const r=await fp.get();
    const c=document.createElement("canvas");c.width=200;c.height=50;
    const ctx=c.getContext("2d");ctx.textBaseline="top";ctx.font="14px Arial";
    ctx.fillStyle="#f60";ctx.fillRect(100,1,62,20);
    ctx.fillStyle="#069";ctx.fillText("SmartAttend\u2764",2,15);
    ctx.fillStyle="rgba(102,204,0,0.3)";ctx.font="18pt Arial";
    ctx.fillText("SmartAttend\u2764",4,25);
    const hw=[screen.width,screen.height,screen.colorDepth,navigator.hardwareConcurrency||0,
      navigator.deviceMemory||0,navigator.platform||"",navigator.maxTouchPoints||0,c.toDataURL().slice(-64)].join("|");
    const bytes=await crypto.subtle.digest("SHA-256",new TextEncoder().encode(r.visitorId+"::"+hw));
    const hash=Array.from(new Uint8Array(bytes)).map(b=>b.toString(16).padStart(2,"0")).join("");
    let token=localGet(keys.token);
    if(!token){token=crypto.randomUUID();localSet(keys.token,token);}
    $("fingerprint").value=hash+"_"+token;
    setReady(Boolean(context&&context.active));
  }catch(e){setReady(false);message("Device verification is unavailable. Please use a supported browser with JavaScript enabled.","error");}
}
function getLocation(){
  return new Promise(resolve=>{
    if(!navigator.geolocation)return resolve(null);
    let done=false;
    const timer=setTimeout(()=>{if(!done){done=true;resolve(null)}},5000);
    navigator.geolocation.getCurrentPosition(p=>{
      if(done)return;done=true;clearTimeout(timer);
      resolve({lat:p.coords.latitude,lng:p.coords.longitude,accuracy:p.coords.accuracy,timestamp:p.timestamp});
    },()=>{if(done)return;done=true;clearTimeout(timer);resolve(null)},
    {enableHighAccuracy:true,maximumAge:0,timeout:5000});
  });
}
form.addEventListener("submit",async e=>{
  e.preventDefault();
  if(!ready||submitting||submitted||uncertain||lockCheck()||!form.reportValidity())return;
  submitting=true;setReady(ready);message("Submitting your attendance. Please keep this page open.");
  const submissionContext=context;
  const data=new FormData(form);
  const loc=await getLocation();
  if(loc){data.append("lat",loc.lat);data.append("lng",loc.lng);
    data.append("geo_accuracy_m",loc.accuracy);data.append("geo_client_ts",loc.timestamp);
    data.append("geo_high_accuracy","1");data.append("geo_source","browser-geolocation");}
  const abort=new AbortController();const timer=setTimeout(()=>abort.abort(),25000);
  try{
    const res=await fetch(route("submit.php"),{method:"POST",body:data,signal:abort.signal,
      mode:"cors",credentials:"omit",cache:"no-store"});
    const json=await res.json();
    if(!json || !json.ok){message((json&&json.message)||"Attendance was not accepted.","error");return;}
    submitted=true;clearFence();
    localSet(keys.bypass,JSON.stringify({token:localGet(keys.token),course:submissionContext.course,
      day:submissionContext.day,action:submissionContext.action,at:Date.now()}));
    message(json.message||"Attendance was recorded successfully.","success");button.disabled=true;
  }catch(e){
    // A timeout is ambiguous: the server may have committed the attendance.
    message("The response was interrupted. Your attendance may have been saved. Contact support or check your record before submitting again.","error");
    uncertain=true;
  }finally{clearTimeout(timer);submitting=false;setReady(ready);}
});
document.addEventListener("visibilitychange",()=>{
  if(!context||!context.active||submitted||allowedBypass())return;
  if(document.hidden){
    clearTimeout(hiddenTimer);
    hiddenTimer=setTimeout(()=>{
      const strikes=Number(localGet(keys.strikes)||0)+1;
      localSet(keys.strikes,String(strikes));
      const locked=strikes>=MAX_STRIKES;
      if(locked)localSet(keys.lock,String(Date.now()+LOCK_MS));
      const data=new URLSearchParams({reason:locked?"Tab-away limit reached (locked 15m)":"Tab away beyond 6s grace ("+strikes+"/"+MAX_STRIKES+")",
        should_lock:locked?"1":"0",token:localGet(keys.token)||"",
        fingerprint:$("fingerprint").value});
      fetch(route("log_inactivity.php"),{method:"POST",body:data,mode:"cors",credentials:"omit",
        keepalive:true}).catch(()=>{});
      if(locked){setReady(false);message("Session locked after three tab-away violations.","error")}
      else contextMessage("Tab-away warning: "+strikes+" of 3 grace periods used.","error");
    },GRACE_MS);
  }else clearTimeout(hiddenTimer);
});
async function pollContext(){
  if (!API || document.hidden) return;
  await refreshContext();
  if (!document.hidden && !statusTimer) {
    const delay=Math.min(120000, 15000*2**failedRefreshes);
    statusTimer=setTimeout(() => {statusTimer=null;pollContext();}, delay+Math.random()*5000);
  }
}
document.addEventListener("visibilitychange", () => {
  clearTimeout(statusTimer);
  statusTimer=null;
  if (!document.hidden) pollContext();
});
if (API) {
  $("topStatus").textContent = "Checking status…";
  $("modeBadge").textContent = "Checking session…";
  $("sessionDescription").textContent = "Connecting to the attendance server";
  message("Loading the current class status…");
  $("supportLink").href = route("support.php");
  $("rulesLink").href = route("rules.php");
  form.hidden = false;
  $("studentLinks").hidden = false;
  $("studentFootnote").hidden = false;
  fingerprint();
  pollContext();
  setInterval(() => {if (!document.hidden) renderCountdown();}, 1000);
}

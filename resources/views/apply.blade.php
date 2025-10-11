@extends('layouts.app')
@section('content')
<h1>Basic details (HA pages 1–2)</h1>
<div class="card" id="box">Loading…</div>
<script>
const escapeAttr = (s='') => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');

function control(f){
  const id='f_'+f.key;
  const help=`<button type="button" class="help-btn" data-form="ha" data-key="${escapeAttr(f.key)}" data-label="${escapeAttr(f.label || f.key)}" onclick="ChatAssistant.handleButton(this)">?</button>`;
  if (f.type==='select') {
    const opts=(f.options||[]).map(o=>`<option value="${o}">${o}</option>`).join('');
    return `<label>${f.label}${f.required?' *':''}${help}</label><select id="${id}">${opts}</select>${f.hint?`<div class="muted">${f.hint}</div>`:''}`;
  }
  const type=f.type==='date'?'date':'text';
  return `<label>${f.label}${f.required?' *':''}${help}</label><input id="${id}" type="${type}" value="${f.default||''}">`;
}

async function load(){
  const {fields=[]}=await fetch('/api/ha-schema').then(r=>r.json());
  const rows=[];
  for (let i=0;i<fields.length;i+=2){
    const a=fields[i], b=fields[i+1];
    rows.push(`<div class="row"><div>${a?control(a):''}</div><div>${b?control(b):''}</div></div>`);
  }
  const householdSection = `
  <div class="mt card" style="border-style:dashed">
    <h3>Household (for annex decision) <button type="button" class="help-btn" data-form="household" data-key="section_overview" data-label="Household" onclick="ChatAssistant.handleButton(this)">?</button></h3>
    <label><input type="checkbox" id="hh_add_adult"> I live with another adult in my BG</label>
    <div id="hh_adult_fields" style="display:none; margin-top:10px;">
      <div class="row">
        <div>
          <label>First name <button type="button" class="help-btn" data-form="household" data-key="hh_first_name" data-label="Partner first name" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <input id="hh_first_name" value="Anna">
        </div>
        <div>
          <label>Last name <button type="button" class="help-btn" data-form="household" data-key="hh_surname" data-label="Partner last name" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <input id="hh_surname" value="Schmidt">
        </div>
      </div>
      <div class="row">
        <div>
          <label>Age <button type="button" class="help-btn" data-form="household" data-key="hh_age" data-label="Partner age" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <input id="hh_age" type="number" value="25">
        </div>
        <div>
          <label>Relationship <button type="button" class="help-btn" data-form="household" data-key="hh_relationship" data-label="Relationship to applicant" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <input id="hh_relationship" value="partner">
        </div>
      </div>
      <div class="row">
        <div>
          <label>In same BG? <button type="button" class="help-btn" data-form="household" data-key="hh_in_bg" data-label="In same BG" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <select id="hh_in_bg"><option>true</option><option>false</option></select>
        </div>
        <div>
          <label>Gender <button type="button" class="help-btn" data-form="household" data-key="hh_gender" data-label="Partner gender" onclick="ChatAssistant.handleButton(this)">?</button></label>
          <select id="hh_gender"><option>female</option><option>male</option><option>divers</option><option>unspecified</option></select>
        </div>
      </div>
    </div>
  </div>`;
  document.getElementById('box').innerHTML =
    rows.join('') + householdSection + `<div class="mt"><button class="btn" onclick="save()">Continue</button></div>`;
  const toggle = document.getElementById('hh_add_adult');
  if (toggle) {
    toggle.addEventListener('change', e=>{
      document.getElementById('hh_adult_fields').style.display = e.target.checked ? 'block':'none';
    });
  }
}

async function save(){
  const {fields=[]}=await fetch('/api/ha-schema').then(r=>r.json());
  const applicant={};
  for (const f of fields){
    const el=document.getElementById('f_'+f.key);
    if (el) applicant[f.key]=el.value;
  }

  const hh = [];
  const addAdult = document.getElementById('hh_add_adult').checked;
  if (addAdult) {
    const age = parseInt(document.getElementById('hh_age').value||'0',10);
    const in_bg = document.getElementById('hh_in_bg').value === 'true';
    hh.push({
      id:'p2',
      role:'member',
      age,
      in_bg,
      relationship: document.getElementById('hh_relationship').value,
      first_name: document.getElementById('hh_first_name').value,
      surname: document.getElementById('hh_surname').value,
      date_of_birth: age ? '1999-05-10' : '',
      gender: document.getElementById('hh_gender').value,
      nationality: applicant.nationality || 'DE',
      marital_status: 'married'
    });
  }

  const domain={
    applicant,
    household: hh,
    housing:{ claims_housing_costs:false },
    income:[],
    assets:[],
    flags:{ self_employed:false }
  };

  await fetch('/api/form/save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(domain)});
  location.href='/annexes';
}
load();
</script>
@endsection

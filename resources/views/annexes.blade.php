@extends('layouts.app')
@section('content')
<h1>Required forms</h1>
<div class="card">
  <div id="plan"></div>
  <div class="mt"><button class="btn" id="next">Continue</button></div>
</div>
<script>
let forms = [];
async function load(){
  const res = await fetch('/api/ai/annexes',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
  const data = await res.json();
  forms = data.forms || [];
  const box = document.getElementById('plan');
  box.innerHTML = forms.map(f=>{
    const pill = `<span class="pill">${f.code}${f.for_person_id? ' ('+f.for_person_id+')':''}</span>`;
    return pill;
  }).join(' ');
  const hasWep = forms.some(f=>f.code==='WEP');
  const nextBtn = document.getElementById('next');
  if (hasWep) {
    nextBtn.textContent = 'Continue to WEP';
    nextBtn.dataset.target = '/wep';
  } else {
    nextBtn.textContent = 'Download HA';
    nextBtn.dataset.target = '/merged';
  }
}
document.getElementById('next').onclick = (e)=>{
  const target = e.currentTarget.dataset.target || '/merged';
  location.href = target;
};
load();
</script>
@endsection

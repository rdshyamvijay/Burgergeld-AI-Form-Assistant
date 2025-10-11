@extends('layouts.app')
@section('content')
<h1>WEP – Additional Details</h1>
<div class="card">
  <h2>Only the missing pieces</h2>
  <form id="wepForm">
    <div id="wepFields"></div>
    <div class="mt" id="wepActions" style="display:none;">
      <button type="submit" class="btn">Save & Download</button>
    </div>
  </form>
</div>
<script>
const wrap = document.getElementById('wepFields');
const actions = document.getElementById('wepActions');
const escapeAttr = (s='') => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');

function controlMarkup(field) {
  const id = `f_${field.key}`;
  const value = field.default !== undefined ? field.default : '';
  const help = `<button type="button" class="help-btn" data-form="wep" data-key="${escapeAttr(field.key)}" data-label="${escapeAttr(field.label || field.key)}" onclick="ChatAssistant.handleButton(this)">?</button>`;
  if (field.type === 'select' && Array.isArray(field.options)) {
    const opts = field.options.map(opt => {
      const optValue = typeof opt === 'string' ? opt : opt.value;
      const optLabel = typeof opt === 'string' ? opt : (opt.label ?? opt.value);
      const selected = (value ?? '') === optValue ? 'selected' : '';
      return `<option value="${optValue}" ${selected}>${optLabel}</option>`;
    }).join('');
    return `<label for="${id}">${field.label}${help}</label><select id="${id}" ${field.required ? 'required' : ''}>${opts}</select>`;
  }
  const type = field.type === 'date' ? 'date' : 'text';
  const valAttr = value ? ` value="${value}"` : '';
  return `<label for="${id}">${field.label}${help}</label><input id="${id}" type="${type}"${valAttr}${field.required ? ' required' : ''}>`;
}

async function loadWep() {
  const res = await fetch('/api/wep/questions');
  const data = await res.json();

  if (!data.required) {
    wrap.innerHTML = `<p>${data.message || 'No WEP required for this application.'}</p>`;
    return;
  }

  if (!data.questions || !data.questions.length) {
    wrap.innerHTML = '<p>Great, we already have everything we need. Preparing your download…</p>';
    setTimeout(() => { window.location.href = '/merged'; }, 800);
    return;
  }

  wrap.innerHTML = data.questions.map(q => `<div class="mt">${controlMarkup(q)}</div>`).join('');
  actions.style.display = 'block';
}

document.getElementById('wepForm').addEventListener('submit', async e => {
  e.preventDefault();
  const payload = {};
  document.querySelectorAll('#wepFields input,#wepFields select').forEach(el => {
    payload[el.id.replace('f_', '')] = el.value;
  });
  await fetch('/api/wep/save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  window.location.href = '/merged';
});

loadWep();
</script>
@endsection

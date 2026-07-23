@verbatim
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Albaranes → Factura</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f5f6f8; color: #1a1a1a; }
  header { background: #2f7df6; color: #fff; padding: 16px 20px; }
  header h1 { margin: 0; font-size: 18px; }
  main { max-width: 860px; margin: 0 auto; padding: 20px; }
  .card { background: #fff; border: 1px solid #e3e6eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
  label { display: block; font-size: 12px; font-weight: 600; color: #555; margin-bottom: 4px; }
  input[type=text], input[type=password] { width: 100%; padding: 9px 11px; border: 1px solid #cfd4dc; border-radius: 8px; font-size: 14px; }
  button { background: #2f7df6; color: #fff; border: 0; border-radius: 8px; padding: 9px 16px; font-size: 14px; font-weight: 600; cursor: pointer; }
  button.secondary { background: #eef1f5; color: #1a1a1a; }
  button:disabled { opacity: .5; cursor: default; }
  .row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 180px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #eef1f5; font-size: 14px; }
  th { font-size: 11px; text-transform: uppercase; color: #888; }
  td.amount, th.amount { text-align: right; }
  .muted { color: #888; font-size: 13px; }
  .contacts { display: flex; gap: 14px; flex-wrap: wrap; }
  .contacts label { display: flex; align-items: center; gap: 6px; font-weight: 400; font-size: 13px; color: #1a1a1a; margin: 0; }
  .sent { font-size: 12px; color: #17663a; white-space: nowrap; }
  .notsent { font-size: 12px; color: #b06a00; white-space: nowrap; }
  .results { list-style: none; margin: 6px 0 0; padding: 0; }
  .results li { padding: 8px 10px; border: 1px solid #e3e6eb; border-radius: 8px; margin-bottom: 6px; cursor: pointer; }
  .results li:hover { background: #f0f5ff; }
  .msg { padding: 10px 12px; border-radius: 8px; margin-top: 10px; font-size: 14px; }
  .msg.err { background: #fdecec; color: #b00020; }
  .msg.ok { background: #e8f7ec; color: #17663a; }
  .total { font-weight: 700; }
  @media (prefers-color-scheme: dark) {
    body { background: #14161a; color: #e8eaed; }
    .card { background: #1e2127; border-color: #2c313a; }
    input[type=text], input[type=password] { background: #14161a; color: #e8eaed; border-color: #2c313a; }
    .results li { border-color: #2c313a; }
    button.secondary { background: #2c313a; color: #e8eaed; }
    .contacts label { color: #e8eaed; }
    .sent { color: #6ddc9b; }
    .notsent { color: #e0a34e; }
  }
</style>
</head>
<body>
<header><h1>Albaranes → Factura</h1></header>
<main>
  <div class="card" id="tokenCard">
    <label>Token de API de Invoice Ninja (Settings → Account Management → API Tokens)</label>
    <div class="row">
      <div><input type="password" id="token" placeholder="X-API-TOKEN" autocomplete="off"></div>
      <div style="flex:0"><button id="saveToken">Guardar</button></div>
    </div>
    <p class="muted">El token se guarda sólo en este navegador (localStorage). No se envía a ningún sitio salvo a tu propia instancia.</p>
  </div>

  <div class="card" id="clientCard" style="display:none">
    <label>Cliente</label>
    <div class="row">
      <div><input type="text" id="clientSearch" placeholder="Buscar cliente por nombre…"></div>
      <div style="flex:0"><button class="secondary" id="btnSearch">Buscar</button></div>
    </div>
    <ul class="results" id="clientResults"></ul>
    <div id="selectedClient" class="muted"></div>
    <div id="contactsBox" style="display:none;margin-top:10px">
      <label>Enviar los albaranes a</label>
      <div id="contactsList" class="contacts"></div>
    </div>
  </div>

  <div class="card" id="candidatesCard" style="display:none">
    <strong id="candHeader">Presupuestos disponibles</strong>
    <div class="muted" style="margin:2px 0 6px">Marca como albarán los presupuestos que quieras poder agrupar en factura.</div>
    <table>
      <thead><tr><th>Nº</th><th>Fecha</th><th>Referencia</th><th class="amount">Importe</th><th></th></tr></thead>
      <tbody id="candBody"></tbody>
    </table>
  </div>

  <div class="card" id="albaranesCard" style="display:none">
    <div class="row" style="justify-content:space-between">
      <div style="flex:1"><strong id="albHeader">Albaranes pendientes</strong></div>
      <div style="flex:0"><button id="btnConsolidate" disabled>Generar factura</button></div>
    </div>
    <table>
      <thead><tr>
        <th style="width:34px"><input type="checkbox" id="checkAll"></th>
        <th>Nº</th><th>Fecha</th><th>Referencia</th><th class="amount">Importe</th><th>Enviado</th><th></th>
      </tr></thead>
      <tbody id="albBody"></tbody>
      <tfoot><tr><td colspan="4" class="amount total">Seleccionado</td><td class="amount total" id="selTotal">0,00</td><td colspan="2"></td></tr></tfoot>
    </table>
    <div id="msg"></div>
  </div>
</main>

<script>
const origin = window.location.origin;
let token = localStorage.getItem('in_albaran_token') || '';
let client = null;
let albaranes = [];
let contacts = [];

const $ = (id) => document.getElementById(id);
const money = (n) => new Intl.NumberFormat('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fecha = (s) => { if (!s) return ''; const d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('es-ES', {day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit'}); };

function headers() { return { 'X-API-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }; }
function showMsg(text, kind) { $('msg').innerHTML = text ? `<div class="msg ${kind}">${text}</div>` : ''; }

async function api(path, opts={}) {
  const res = await fetch(origin + path, { headers: headers(), ...opts });
  if (!res.ok) {
    let m = 'Error ' + res.status;
    try { const j = await res.json(); m = j.message || m; } catch(e) {}
    throw new Error(m);
  }
  return res.json();
}

function initToken() {
  if (token) { $('token').value = token; $('clientCard').style.display = 'block'; }
}

$('saveToken').onclick = () => {
  token = $('token').value.trim();
  localStorage.setItem('in_albaran_token', token);
  $('clientCard').style.display = token ? 'block' : 'none';
};

$('btnSearch').onclick = doSearch;
$('clientSearch').addEventListener('keydown', (e) => { if (e.key === 'Enter') doSearch(); });

async function doSearch() {
  const q = $('clientSearch').value.trim();
  $('clientResults').innerHTML = '<li class="muted">Buscando…</li>';
  try {
    const j = await api('/api/v1/clients?filter=' + encodeURIComponent(q) + '&per_page=20&status=active');
    const items = (j.data || []);
    $('clientResults').innerHTML = items.length ? '' : '<li class="muted">Sin resultados</li>';
    items.forEach(c => {
      const li = document.createElement('li');
      li.textContent = (c.display_name || c.name || '(sin nombre)');
      li.onclick = () => selectClient(c.id, li.textContent);
      $('clientResults').appendChild(li);
    });
  } catch (e) { $('clientResults').innerHTML = `<li class="msg err">${e.message}</li>`; }
}

async function selectClient(id, name) {
  client = { id, name };
  $('clientResults').innerHTML = '';
  $('clientSearch').value = '';
  $('selectedClient').textContent = 'Cliente: ' + name;
  $('candidatesCard').style.display = 'block';
  $('albaranesCard').style.display = 'block';
  await reload();
}

async function reload() {
  await Promise.all([loadCandidates(), loadAlbaranes()]);
}

async function loadCandidates() {
  $('candBody').innerHTML = '<tr><td colspan="5" class="muted">Cargando…</td></tr>';
  try {
    const j = await api('/api/v1/albaranes/clients/' + client.id + '/candidates');
    const items = j.presupuestos || [];
    $('candHeader').textContent = `Presupuestos disponibles (${items.length})`;
    if (!items.length) { $('candBody').innerHTML = '<tr><td colspan="5" class="muted">No hay presupuestos sin marcar para este cliente.</td></tr>'; return; }
    $('candBody').innerHTML = '';
    items.forEach(p => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${p.number || ''}</td><td>${p.date || ''}</td><td>${p.po_number || ''}</td>
        <td class="amount">${money(p.amount)}</td>
        <td><button class="markbtn" data-id="${p.id}">→ Albarán</button></td>`;
      $('candBody').appendChild(tr);
    });
    document.querySelectorAll('.markbtn').forEach(b => b.addEventListener('click', () => toggleAlbaran(b.dataset.id, true)));
  } catch (e) { $('candBody').innerHTML = `<tr><td colspan="5"><div class="msg err">${e.message}</div></td></tr>`; }
}

async function toggleAlbaran(id, on) {
  try {
    await api('/api/v1/albaranes/quotes/' + id + '/toggle', { method: 'PUT', body: JSON.stringify({ albaran: on }) });
    await reload();
  } catch (e) { showMsg(e.message, 'err'); }
}

async function loadAlbaranes() {
  $('albBody').innerHTML = '<tr><td colspan="7" class="muted">Cargando…</td></tr>';
  showMsg('');
  try {
    const j = await api('/api/v1/albaranes/clients/' + client.id);
    albaranes = j.albaranes || [];
    renderContacts(j.contacts || []);
    renderAlbaranes();
  } catch (e) { $('albBody').innerHTML = `<tr><td colspan="7"><div class="msg err">${e.message}</div></td></tr>`; }
}

// Destinatarios: se marcan por defecto los contactos que ya reciben correo en
// la ficha del cliente; el usuario puede acotar el envío a los que quiera.
function renderContacts(list) {
  contacts = list;
  $('contactsBox').style.display = list.length ? 'block' : 'none';
  $('contactsList').innerHTML = list.map(c =>
    `<label><input type="checkbox" class="contactchk" value="${esc(c.id)}"${c.default ? ' checked' : ''}> ${esc(c.name)} <span class="muted">&lt;${esc(c.email)}&gt;</span></label>`
  ).join('');
}

function selectedContacts() { return [...document.querySelectorAll('.contactchk:checked')].map(c => c.value); }

function renderAlbaranes() {
  $('albHeader').textContent = `Albaranes pendientes (${albaranes.length})`;
  if (!albaranes.length) { $('albBody').innerHTML = '<tr><td colspan="7" class="muted">Este cliente no tiene albaranes pendientes de facturar.</td></tr>'; recalc(); return; }
  $('albBody').innerHTML = '';
  albaranes.forEach(a => {
    const enviado = a.sent_at
      ? `<span class="sent" title="${esc((a.sent_to || []).join(', '))}">✓ ${fecha(a.sent_at)}</span>`
      : '<span class="notsent">Sin enviar</span>';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="checkbox" class="albchk" data-id="${a.id}" data-amount="${a.amount}"></td>
      <td>${esc(a.number)}</td><td>${esc(a.date)}</td><td>${esc(a.po_number)}</td>
      <td class="amount">${money(a.amount)}</td>
      <td>${enviado}</td>
      <td style="white-space:nowrap"><button class="secondary pdfbtn" data-id="${a.id}" data-num="${esc(a.number || a.id)}">PDF</button>
        <button class="secondary sendbtn" data-id="${a.id}" data-num="${esc(a.number || a.id)}" title="Enviar el albarán por correo">Enviar</button>
        <button class="secondary unmarkbtn" data-id="${a.id}" title="Quitar la marca de albarán">✕</button></td>`;
    $('albBody').appendChild(tr);
  });
  document.querySelectorAll('.albchk').forEach(c => c.addEventListener('change', recalc));
  document.querySelectorAll('.pdfbtn').forEach(b => b.addEventListener('click', () => downloadPdf(b.dataset.id, b.dataset.num)));
  document.querySelectorAll('.sendbtn').forEach(b => b.addEventListener('click', () => sendAlbaran(b, b.dataset.id, b.dataset.num)));
  document.querySelectorAll('.unmarkbtn').forEach(b => b.addEventListener('click', () => toggleAlbaran(b.dataset.id, false)));
  recalc();
}

async function sendAlbaran(btn, id, num) {
  const elegidos = selectedContacts();
  const destinos = contacts.filter(c => elegidos.includes(c.id)).map(c => c.email);

  if (!destinos.length) { showMsg('Marca al menos un destinatario en la ficha del cliente.', 'err'); return; }
  if (!confirm(`¿Enviar el albarán ${num} a ${destinos.join(', ')}?`)) return;

  btn.disabled = true;
  showMsg('Enviando albarán…', 'ok');
  try {
    const j = await api('/api/v1/albaranes/quotes/' + id + '/email', {
      method: 'POST', body: JSON.stringify({ contacts: elegidos })
    });
    showMsg('✓ ' + j.message, 'ok');
    await loadAlbaranes();
  } catch (e) { showMsg(e.message, 'err'); btn.disabled = false; }
}

$('checkAll').onchange = (e) => { document.querySelectorAll('.albchk').forEach(c => c.checked = e.target.checked); recalc(); };

function selectedIds() { return [...document.querySelectorAll('.albchk:checked')].map(c => c.dataset.id); }
function recalc() {
  const chk = [...document.querySelectorAll('.albchk:checked')];
  const total = chk.reduce((s, c) => s + parseFloat(c.dataset.amount || 0), 0);
  $('selTotal').textContent = money(total);
  $('btnConsolidate').disabled = chk.length === 0;
}

$('btnConsolidate').onclick = async () => {
  const ids = selectedIds();
  if (!ids.length) return;
  if (!confirm(`¿Generar una factura con ${ids.length} albarán(es)?`)) return;
  $('btnConsolidate').disabled = true;
  showMsg('Generando factura…', 'ok');
  try {
    const j = await api('/api/v1/albaranes/clients/' + client.id + '/consolidate', {
      method: 'POST', body: JSON.stringify({ albaranes: ids })
    });
    showMsg(`✓ ${j.message} Factura <strong>${j.invoice.number || j.invoice.id}</strong> por ${money(j.invoice.amount)} €.`, 'ok');
    await reload();
  } catch (e) { showMsg(e.message, 'err'); $('btnConsolidate').disabled = false; }
};

async function downloadPdf(id, num) {
  showMsg('Generando PDF del albarán…', 'ok');
  try {
    const res = await fetch(origin + '/api/v1/albaranes/quotes/' + id + '/pdf', { headers: headers() });
    if (!res.ok) { let m = 'Error ' + res.status; try { const j = await res.json(); m = j.message || m; } catch(e) {} throw new Error(m); }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
    showMsg('');
  } catch (e) { showMsg(e.message, 'err'); }
}

initToken();
</script>
</body>
</html>
@endverbatim

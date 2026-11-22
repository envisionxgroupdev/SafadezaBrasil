(function () {
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const proxy = (u, p) => u ? window.ADMIN.base + 'admin/thumb.php?p=' + encodeURIComponent(p || '') + '&u=' + encodeURIComponent(u) : window.ADMIN.base + 'assets/img/no-thumb.svg';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function call(action, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    fd.set('action', action); fd.set('csrf', window.ADMIN.csrf);
    try {
      const r = await fetch(window.ADMIN.base + 'admin/import-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      return await r.json();
    } catch (e) { return { ok: false, msg: 'Erro de conexão com o servidor' }; }
  }
  const optsFrom = form => {
    const fd = new FormData();
    $$('input[name="categories[]"]:checked', form).forEach(i => fd.append('categories[]', i.value));
    fd.append('status', $('[name=status]', form).value);
    if ($('[name=auto_categories]', form)?.checked) fd.append('auto_categories', '1');
    return fd;
  };

  // Confirmações e "selecionar todos" nas tabelas
  $$('[data-confirm]').forEach(el => el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); }));
  const checkAll = $('#check-all');
  if (checkAll) checkAll.addEventListener('change', () => $$('.row-check').forEach(c => c.checked = checkAll.checked));

  // Abas
  $$('.tabs button').forEach(b => b.addEventListener('click', () => {
    $$('.tabs button').forEach(x => x.classList.toggle('active', x === b));
    $$('.tab').forEach(t => t.classList.toggle('active', t.id === 'tab-' + b.dataset.tab));
  }));

  /* ========== LINK ÚNICO ========== */
  const sBtn = $('#single-fetch');
  if (sBtn) {
    const form = $('#single-form'), msg = $('#single-msg');
    const show = (t, type) => msg.innerHTML = t ? `<div class="alert ${type}">${t}</div>` : '';
    const fetchIt = async () => {
      const input = $('#single-input').value.trim();
      if (!input) return;
      sBtn.disabled = true; sBtn.textContent = 'Buscando...'; show('', ''); form.style.display = 'none';
      const r = await call('parse', { input });
      sBtn.disabled = false; sBtn.textContent = 'Buscar dados';
      if (!r.ok) return show(esc(r.msg), 'err');
      const d = r.data;
      form.reset();
      ['provider', 'source_id', 'source_url', 'title', 'thumb', 'embed_url', 'description'].forEach(k => form[k].value = d[k] || '');
      form.duration.value = d.duration_fmt || '';
      form.tags.value = (d.tags || []).join(', ');
      $$('input[name="categories[]"]', form).forEach(c => c.checked = (d.suggested || []).includes(+c.value));
      $('#single-player').src = d.embed_url;
      form.thumbs.value = JSON.stringify(d.thumbs || []);
      $('#single-thumbprev').src = proxy(d.thumb, d.provider);
      $('#single-frames').innerHTML = (d.thumbs || []).map(f => `<img src="${esc(proxy(f, d.provider))}" alt="">`).join('');
      $('#single-src').textContent = d.provider_name + ' · ID ' + d.source_id;
      form.style.display = 'block';
      if (d.exists) show('⚠ Este vídeo já está no site.', 'err');
      else if (!d.title) show('Não consegui ler os dados automaticamente — preencha o título e a thumb manualmente.', 'info');
      else if (!d.thumb) show('O site de origem não informou a thumb. Cole a URL de uma imagem no campo Thumbnail.', 'info');
    };
    sBtn.addEventListener('click', fetchIt);
    $('#single-input').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); fetchIt(); } });
    form.thumb.addEventListener('change', () => { $('#single-thumbprev').src = proxy(form.thumb.value, form.provider.value); });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(form);
      const btn = $('button.btn-primary', form); btn.disabled = true;
      const r = await call('save', fd);
      btn.disabled = false;
      if (r.ok) {
        show(`✓ Vídeo importado! <a href="${esc(r.url)}" target="_blank">Ver no site</a> · <a href="video-edit.php?id=${r.id}">Editar</a>`, 'ok');
        form.style.display = 'none'; $('#single-player').src = 'about:blank'; $('#single-input').value = ''; $('#single-input').focus();
      } else show(esc(r.msg), 'err');
    });
  }

  /* ========== EM MASSA ========== */
  const bForm = $('#bulk-form');
  if (bForm) {
    let stop = false;
    const log = $('#bulk-log');
    const line = (cls, t) => { log.insertAdjacentHTML('beforeend', `<div class="${cls}">${esc(t)}</div>`); log.scrollTop = log.scrollHeight; };
    $('#bulk-stop').addEventListener('click', () => { stop = true; });
    bForm.addEventListener('submit', async e => {
      e.preventDefault();
      // aceita links separados por linha e vários <iframe> colados juntos
      const raw = bForm.list.value;
      const items = (raw.match(/<iframe[\s\S]*?<\/iframe>|<iframe[^>]*>/gi) || [])
        .concat(raw.replace(/<iframe[\s\S]*?<\/iframe>|<iframe[^>]*>/gi, '').split(/\s+/).filter(s => /^https?:\/\//i.test(s)));
      const uniq = [...new Set(items.map(s => s.trim()))].filter(Boolean);
      if (!uniq.length) return alert('Cole pelo menos um link.');
      stop = false; log.innerHTML = ''; log.style.display = 'block';
      $('#bulk-start').disabled = true; $('#bulk-stop').disabled = false;
      let ok = 0, skip = 0, err = 0;
      for (let i = 0; i < uniq.length && !stop; i++) {
        const fd = optsFrom(bForm);
        fd.append('input', uniq[i]);
        if (bForm.require_title.checked) fd.append('require_title', '1');
        if (bForm.no_meta.checked) fd.append('no_meta', '1');
        const r = await call('bulk_one', fd);
        const short = uniq[i].length > 70 ? uniq[i].slice(0, 70) + '…' : uniq[i];
        if (r.status === 'ok') { ok++; line('ok', `✓ ${r.msg}`); }
        else if (r.status === 'skip') { skip++; line('skip', `↷ ${short} — ${r.msg}`); }
        else { err++; line('err', `✗ ${short} — ${r.msg || 'erro'}`); }
        $('#bulk-bar').style.width = ((i + 1) / uniq.length * 100) + '%';
        $('#bulk-count').textContent = `${i + 1}/${uniq.length} · ${ok} importados · ${skip} repetidos · ${err} erros`;
      }
      line(stop ? 'skip' : 'ok', stop ? '■ Interrompido.' : '— Concluído —');
      $('#bulk-start').disabled = false; $('#bulk-stop').disabled = true;
    });
  }

  /* ========== BUSCAR ========== */
  const sForm = $('#search-form');
  if (sForm) {
    let items = [];
    const grid = $('#search-grid'), box = $('#search-results');
    const count = () => $('#sel-count').textContent = $$('.ritem.sel', grid).length;
    const run = async () => {
      const btn = $('button', sForm); btn.disabled = true; btn.textContent = 'Buscando...';
      const r = await call('search', new FormData(sForm));
      btn.disabled = false; btn.textContent = '🔎 Buscar';
      box.style.display = 'block';
      if (!r.ok) { grid.innerHTML = `<div class="alert err">${esc(r.msg)}</div>`; items = []; return; }
      items = r.items;
      $('#search-info').textContent = `${items.length} resultados (página ${sForm.page.value})`;
      grid.innerHTML = items.length ? items.map((it, i) => `
        <div class="ritem ${it.exists ? 'exists' : ''}" data-i="${i}" title="${it.exists ? 'Já está no site' : ''}">
          <span class="rc"></span>${it.duration_fmt ? `<span class="rd">${esc(it.duration_fmt)}</span>` : ''}
          <img src="${esc(proxy(it.thumb, it.provider))}" loading="lazy" alt="" onerror="this.src='${esc(proxy('', ''))}'">
          ${(it.thumbs || []).length ? `<span class="rf">${it.thumbs.length} quadros</span>` : ''}
          <div class="rt">${esc(it.title)}</div>
        </div>`).join('') : `<div class="alert info">${esc(r.msg)}</div>`;
      count();
    };
    sForm.addEventListener('submit', e => { e.preventDefault(); run(); });
    grid.addEventListener('click', e => { const it = e.target.closest('.ritem'); if (it && !it.classList.contains('exists')) { it.classList.toggle('sel'); count(); } });
    $('#search-all').addEventListener('click', () => { $$('.ritem:not(.exists)', grid).forEach(i => i.classList.add('sel')); count(); });
    $('#search-none').addEventListener('click', () => { $$('.ritem', grid).forEach(i => i.classList.remove('sel')); count(); });
    $('#search-next').addEventListener('click', () => { sForm.page.value = +sForm.page.value + 1; run(); });
    $('#search-import').addEventListener('click', async () => {
      const els = $$('.ritem.sel', grid);
      if (!els.length) return alert('Selecione pelo menos um vídeo.');
      const btn = $('#search-import'); btn.disabled = true;
      $('#search-progress').style.display = 'block';
      let ok = 0, skip = 0, err = 0, noThumb = 0;
      for (let i = 0; i < els.length; i++) {
        const el = els[i];
        const fd = optsFrom($('#search-opts'));
        fd.append('item', JSON.stringify(items[+el.dataset.i]));
        const r = await call('save_one', fd);
        if (r.status === 'ok') { ok++; if (!r.local_thumb) noThumb++; el.classList.remove('sel'); el.classList.add('exists'); }
        else if (r.status === 'skip') { skip++; el.classList.remove('sel'); el.classList.add('exists'); }
        else err++;
        $('#search-bar').style.width = ((i + 1) / els.length * 100) + '%';
        $('#search-status').textContent = `${i + 1}/${els.length} · ${ok} importados · ${skip} já existiam · ${err} erros` + (noThumb ? ` · ${noThumb} sem thumb salva` : '');
        count();
      }
      btn.disabled = false;
      $('#search-status').textContent += ' — concluído';
    });
  }
})();

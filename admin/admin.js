(function () {
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const proxy = (u, p) => u ? window.ADMIN.base + 'admin/thumb.php?p=' + encodeURIComponent(p || '') + '&u=' + encodeURIComponent(u) : window.ADMIN.base + 'assets/img/no-thumb.svg';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function call(action, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    fd.set('action', action); fd.set('csrf', window.ADMIN.csrf);
    let r;
    try { r = await fetch(window.ADMIN.base + 'admin/import-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' }); }
    catch (e) { return { ok: false, msg: 'sem conexão com o servidor' }; }
    const txt = await r.text();
    try { return JSON.parse(txt); }
    catch (e) {
      if (r.status === 429) return { ok: false, http: 429, retry: +(r.headers.get('Retry-After') || 60), msg: 'a hospedagem pediu para ir mais devagar (429)' };
      if (/^\s*<\?php/.test(txt)) return { ok: false, msg: 'a hospedagem não está executando o PHP' };
      if (r.status === 508 || r.status === 503) return { ok: false, http: r.status, retry: 90, msg: `a hospedagem está sobrecarregada (erro ${r.status}). Aguarde alguns minutos` };
      if (r.status === 403) return { ok: false, msg: 'acesso bloqueado pelo servidor (403)' };
      return { ok: false, msg: `resposta inesperada do servidor (HTTP ${r.status})` };
    }
  }
  const optsFrom = form => {
    const fd = new FormData();
    $$('input[name="categories[]"]:checked', form).forEach(i => fd.append('categories[]', i.value));
    fd.append('status', $('[name=status]', form).value);
    if ($('[name=auto_categories]', form)?.checked) fd.append('auto_categories', '1');
    return fd;
  };

  /* ========== FILA DE THUMBS (baixa em segundo plano, devagar para não sobrecarregar a hospedagem) ========== */
  const thumbQ = { running: false, ok: 0, fail: 0, el: null, keep: () => false, stop: false };
  const pause = ms => new Promise(res => setTimeout(res, ms));
  window.runThumbQueue = async (el, keepAlive) => {
    if (el) thumbQ.el = el;
    if (keepAlive) thumbQ.keep = keepAlive;
    if (thumbQ.running) return;
    thumbQ.running = true; thumbQ.stop = false;
    let idle = 0, busy = 0, errors = 0, first = true;
    const show = (html) => { if (thumbQ.el) { thumbQ.el.style.display = 'block'; thumbQ.el.innerHTML = html; } };
    const line = (r, extra = '') => {
      const done = thumbQ.ok, left = r ? r.pending : 0, total = done + left;
      const pct = total ? Math.round(done / total * 100) : 100;
      show(`<div class="tq"><div class="tq-top">🖼️ <b>${done}</b> thumbs salvas` + (left ? ` · <b>${left}</b> na fila` : ' · fila vazia') +
        (thumbQ.fail ? ` · ${thumbQ.fail} falharam (vão aparecer direto do site de origem)` : '') +
        ` <button type="button" class="btn btn-sm tq-stop">Parar</button></div>` +
        `<div class="progress" style="display:block"><span style="width:${pct}%"></span></div>${extra}</div>`);
      const b = thumbQ.el && thumbQ.el.querySelector('.tq-stop'); if (b) b.onclick = () => { thumbQ.stop = true; b.textContent = 'Parando...'; };
    };
    show('<div class="tq">⏳ Preparando a fila de thumbs...</div>');
    while (!thumbQ.stop) {
      const r = await call('thumbs_batch', { n: 8 });
      if (!r.ok) {
        if (r.retry) {
          // hospedagem pediu calma: espera e continua sozinho
          for (let s = Math.min(300, Math.max(30, r.retry)); s > 0 && !thumbQ.stop; s--) {
            show(`<div class="tq">⏸️ ${esc(r.msg)}. Continuando sozinho em <b>${s}s</b>... <button type="button" class="btn btn-sm tq-stop">Parar</button></div>`);
            const b = thumbQ.el && thumbQ.el.querySelector('.tq-stop'); if (b) b.onclick = () => { thumbQ.stop = true; };
            await pause(1000);
          }
          continue;
        }
        errors++;
        if (errors >= 3) { show(`<div class="alert err">Não foi possível baixar as thumbs: ${esc(r.msg || 'erro no servidor')}. Tente de novo em alguns minutos.</div>`); break; }
        await pause(10000); continue;
      }
      errors = 0;
      if (r.problem) { show(`<div class="alert err">🖼️ ${esc(r.problem)}</div>`); break; }
      thumbQ.ok += +r.saved || 0; thumbQ.fail += +r.failed || 0;
      if (r.busy) { busy++; line(r, '<small class="muted">O servidor já está baixando outras thumbs; aguardando a vez...</small>'); await pause(4000); if (busy > 60) break; continue; }
      busy = 0;
      line(r);
      if (first && !r.pending && !r.saved && !r.failed) { show('<div class="tq">✓ Nenhuma thumb faltando.</div>'); break; }
      first = false;
      if (r.pending > 0) { idle = 0; await pause(4000); continue; }   // pausa entre lotes: não sobrecarrega a hospedagem
      if (thumbQ.keep() && idle < 400) { idle++; await pause(3000); continue; }
      line(r, '<small style="color:var(--ok);font-weight:700">✓ Concluído</small>' + (thumbQ.fail && !thumbQ.ok ? '<div class="alert info" style="margin-top:8px">O seu servidor não está conseguindo baixar as imagens desses sites. Elas continuam aparecendo direto do site de origem. Para não tentar mais baixar, escolha <b>“Direto do site de origem”</b> em <a href="settings.php#thumbs">Configurações › Thumbs</a>.</div>' : ''));
      break;
    }
    if (thumbQ.stop) line(null, '<small class="muted">Parado. Clique de novo para continuar.</small>');
    thumbQ.running = false;
  };
  $$('[data-thumb-queue]').forEach(btn => btn.addEventListener('click', async () => {
    const el = $(btn.dataset.thumbQueue);
    btn.disabled = true;
    if (el) { el.style.display = 'block'; el.innerHTML = '<div class="tq">⏳ Procurando vídeos sem thumb...</div>'; }
    if (btn.dataset.requeue) {
      const q = await call('thumbs_requeue', {});
      if (!q.ok) { if (el) el.innerHTML = `<div class="alert err">${esc(q.msg || 'Erro')}</div>`; btn.disabled = false; return; }
    }
    await runThumbQueue(el);
    btn.disabled = false;
  }));

  /* ========== ATUALIZAR THUMBS COM O SITE DE ORIGEM ========== */
  const rBtn = $('#refresh-thumbs');
  if (rBtn) rBtn.addEventListener('click', async () => {
    const el = $('#refresh-status'); let first = true, upd = 0, kept = 0, stop = false;
    rBtn.disabled = true;
    el.innerHTML = '<div class="tq">⏳ Começando...</div>';
    while (!stop) {
      const r = await call('thumbs_refresh', first ? { restart: 1 } : {});
      if (!r.ok) {
        const wait = r.retry ? Math.min(300, Math.max(30, r.retry)) : 20;
        for (let s = wait; s > 0; s--) { el.innerHTML = `<div class="tq">⏸️ ${esc(r.msg || 'erro')}. Continuando sozinho em <b>${s}s</b>...</div>`; await pause(1000); }
        continue;
      }
      first = false; upd += r.updated; kept += r.kept;
      const pct = r.total ? Math.round((r.total - r.remaining) / r.total * 100) : 100;
      el.innerHTML = `<div class="tq"><div class="tq-top">🔄 ${r.total - r.remaining} de ${r.total} vídeos · <b>${upd}</b> atualizados` + (kept ? ` · ${kept} sem resposta do site (mantidos)` : '') + `</div><div class="progress" style="display:block"><span style="width:${pct}%"></span></div></div>`;
      if (r.remaining === 0) { el.innerHTML += `<div class="alert ok" style="margin-top:8px">✓ Pronto! ${upd} thumbs agora vêm do site de origem. ${r.freed_files} arquivo(s) apagados do servidor (${esc(r.freed)} liberados).</div>`; break; }
      await pause(5000);   // pausa entre lotes para a hospedagem não bloquear
    }
    rBtn.disabled = false;
  });

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
        show(`✓ Vídeo importado! <a href="${esc(r.url)}" target="_blank">Ver no site</a> · <a href="video-edit.php?id=${r.id}">Editar</a>` +
          (r.local_thumb ? '' : `<br><small>⚠ A thumb não foi salva no servidor${r.thumb_msg ? ': ' + esc(r.thumb_msg) : ' (o site de origem não deixou baixar). Ela será carregada direto do site de origem.'}</small>`), r.local_thumb ? 'ok' : 'info');
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
        if (r.status === 'ok') { ok++; line('ok', `✓ ${r.msg}`); runThumbQueue($('#bulk-thumb-status'), () => !$('#bulk-start').disabled ? false : true); }
        else if (r.status === 'skip') { skip++; line('skip', `↷ ${short} — ${r.msg}`); }
        else { err++; line('err', `✗ ${short} — ${r.msg || 'erro'}`); }
        $('#bulk-bar').style.width = ((i + 1) / uniq.length * 100) + '%';
        $('#bulk-count').textContent = `${i + 1}/${uniq.length} · ${ok} importados · ${skip} repetidos · ${err} erros`;
      }
      line(stop ? 'skip' : 'ok', stop ? '■ Interrompido.' : '— Concluído —');
      $('#bulk-start').disabled = false; $('#bulk-stop').disabled = true;
    });
  }

  /* ========== BUSCAR (várias páginas) ========== */
  const sForm = $('#search-form');
  if (sForm) {
    const PROVS = ['eporner', 'xvideos', 'pornhub', 'redtube'];
    const grid = $('#search-grid'), box = $('#search-results'), log = $('#search-log');
    const status = t => $('#search-status').textContent = t;
    const bar = p => { $('#search-progress').style.display = 'block'; $('#search-bar').style.width = Math.min(100, p) + '%'; };
    const line = (cls, t) => { log.style.display = 'block'; log.insertAdjacentHTML('beforeend', `<div class="${cls}">${esc(t)}</div>`); if (log.children.length > 400) log.firstChild.remove(); log.scrollTop = log.scrollHeight; };
    let items = [], seen = new Set(), pages = {}, done = {}, stop = false, busy = false, lastQuery = '', failures = [];
    const count = () => $('#sel-count').textContent = $$('.ritem.sel', grid).length;
    const sleep = ms => new Promise(r => setTimeout(r, ms));

    const reset = () => {
      items = []; seen = new Set(); pages = {}; done = {}; failures = []; grid.innerHTML = ''; log.innerHTML = ''; log.style.display = 'none';
      const start = Math.max(1, +sForm.page.value || 1);
      provs().forEach(p => { pages[p] = start; done[p] = false; });
    };
    const provs = () => sForm.provider.value === 'all' ? PROVS : [sForm.provider.value];
    const render = list => {
      grid.insertAdjacentHTML('beforeend', list.map(it => `
        <div class="ritem ${it.exists ? 'exists' : ''}" data-i="${it._i}" title="${it.exists ? 'Já está no site' : ''}">
          <span class="rc"></span>${it.duration_fmt ? `<span class="rd">${esc(it.duration_fmt)}</span>` : ''}
          <img src="${esc(proxy(it.thumb, it.provider))}" loading="lazy" alt="" onerror="this.onerror=null;this.src='${esc(proxy('', ''))}'">
          <div class="rt">${esc(it.title)}</div>
        </div>`).join(''));
      $('#search-info').textContent = `${items.length} vídeos encontrados`;
    };

    /** Busca a próxima página de cada site. Retorna quantos vídeos novos vieram. */
    const fetchRound = async () => {
      let added = 0;
      for (const p of provs()) {
        if (done[p] || stop) continue;
        const fd = new FormData();
        fd.append('provider', p); fd.append('q', sForm.q.value); fd.append('order', sForm.order.value); fd.append('page', pages[p]);
        const r = await call('search', fd);
        pages[p]++;
        if (!r.ok || !r.items || !r.items.length) {
          done[p] = true;
          if (r.msg) { line(r.blocked || !r.ok ? 'err' : 'skip', (r.blocked ? '⚠ ' : '• ') + r.msg); failures.push(r.msg); }
          continue;
        }
        const fresh = r.items.filter(it => { const k = it.provider + ':' + it.source_id; if (seen.has(k)) return false; seen.add(k); return true; });
        fresh.forEach(it => { it._i = items.length; items.push(it); });
        added += fresh.length;
        render(fresh);
      }
      return added;
    };
    const exhausted = () => provs().every(p => done[p]);

    const setBusy = b => { busy = b; $$('button[name=mode]', sForm).forEach(x => x.disabled = b); $('#search-stop').disabled = !b; $('#search-more').disabled = b; $('#search-import').disabled = b; };

    /* ---- Modo 1: buscar e escolher ---- */
    const pick = async (target) => {
      setBusy(true); box.style.display = 'block'; stop = false;
      while (items.length < target && !exhausted() && !stop) {
        await fetchRound();
        status(`Buscando... ${items.length} de ${target}`); bar(items.length / target * 100);
      }
      if (!items.length) { box.style.display = 'none'; status('Nenhum vídeo encontrado. Veja o motivo de cada site acima e clique em “Testar conexão com os sites”.'); }
      else status(`${items.length} vídeos encontrados` + (exhausted() ? ' (não há mais resultados)' : '') + '. Clique para selecionar ou use “Selecionar todos”.');
      setBusy(false); count();
    };

    /* ---- Importação com vários "trabalhadores" ao mesmo tempo ---- */
    const importQueue = async (queue, total, label, keepFetching) => {
      runThumbQueue($('#thumb-status'), () => busy);
      const workers = Math.max(1, +sForm.dataset.workers || 1);
      let ok = 0, skip = 0, err = 0, noThumb = 0, processed = 0;
      const show = () => { status(`${label}: ${ok} importados · ${skip} já existiam · ${err} erros${noThumb ? ` · ${noThumb} sem thumb` : ''}`); bar((ok + (keepFetching ? 0 : skip + err)) / total * 100); };
      const worker = async () => {
        while (!stop && ok < total) {
          let it = queue.shift();
          if (!it) {
            if (keepFetching && !exhausted()) { if (!worker.fetching) { worker.fetching = true; await fetchRound(); worker.fetching = false; queue.push(...items.filter(x => !x.exists && !x._queued).map(x => (x._queued = true, x))); } else await sleep(300); continue; }
            break;
          }
          const fd = optsFrom(sForm);
          const { _i, _queued, exists, duration_fmt, ...clean } = it;
          fd.append('item', JSON.stringify(clean));
          const r = await call('save_one', fd);
          processed++;
          const el = grid.querySelector(`.ritem[data-i="${it._i}"]`);
          if (r.status === 'ok') { ok++; line('ok', '✓ ' + it.title); }
          else if (r.status === 'skip') skip++;
          else { err++; line('err', '✗ ' + it.title + ' — ' + (r.msg || 'erro')); if (/login|sessão/i.test(r.msg || '')) stop = true; }
          it.exists = true;
          if (el) { el.classList.remove('sel'); el.classList.add('exists'); }
          show();
        }
      };
      await Promise.all(Array.from({ length: workers }, worker));
      return { ok, skip, err };
    };

    /* ---- Modo 2: importar tudo automaticamente ---- */
    const auto = async (target) => {
      setBusy(true); stop = false; box.style.display = 'block';
      line('skip', `Importando até ${target} vídeos de “${sForm.q.value}”...`);
      await fetchRound();
      const queue = items.filter(x => !x.exists).map(x => (x._queued = true, x));
      const r = await importQueue(queue, target, 'Importação automática', true);
      if (!items.length) status('Nenhum vídeo encontrado. Veja o motivo de cada site acima e clique em “Testar conexão com os sites”.');
      line(stop ? 'skip' : 'ok', stop ? `■ Parado. ${r.ok} importados.` : `— Concluído: ${r.ok} importados${exhausted() && r.ok < target ? ' (acabaram os resultados dessa busca)' : ''} —`);
      setBusy(false); count();
    };

    sForm.addEventListener('submit', e => {
      e.preventDefault();
      if (busy) return;
      const mode = e.submitter && e.submitter.value === 'auto' ? 'auto' : 'pick';
      const target = +sForm.qty.value || 100;
      const q = provs().join() + '|' + sForm.q.value + '|' + sForm.order.value + '|' + sForm.page.value;
      if (q !== lastQuery || mode === 'auto') { reset(); lastQuery = q; }
      if (mode === 'auto') {
        if (target >= 500 && !confirm(`Importar até ${target} vídeos agora? Pode levar alguns minutos — deixe esta aba aberta.`)) return;
        auto(target);
      } else pick(target);
    });
    const diagBtn = $('#diag-btn');
    if (diagBtn) diagBtn.addEventListener('click', async () => {
      const out = $('#diag-out'); diagBtn.disabled = true; diagBtn.textContent = 'Testando (até 1 minuto)...';
      out.innerHTML = '';
      const r = await call('diagnose', {});
      diagBtn.disabled = false; diagBtn.textContent = '🩺 Testar conexão com os sites';
      if (!r.ok) { out.innerHTML = `<div class="alert err">${esc(r.msg || 'Erro')}</div>`; return; }
      const sv = r.server;
      out.innerHTML = `<p class="muted" style="margin:10px 0">Servidor: <b>${esc(sv.country || 'país desconhecido')}</b> · IP ${esc(sv.ip || '?')} · PHP ${esc(sv.php)} · cURL ${esc(sv.curl)} · DNS ${esc(sv.dns)} · Imagens (GD): ${esc(sv.gd)} · Pasta das thumbs: ${esc(sv.folder)}</p>` +
        (sv.problem ? `<div class="alert err">🖼️ ${esc(sv.problem)}</div>` : '') +
        `<div class="table-wrap"><table><thead><tr><th>Site</th><th>Busca</th><th>Download da thumb</th><th>Detalhe</th></tr></thead><tbody>` +
        r.sites.map(s => `<tr><td><b>${esc(s.site)}</b></td><td>${s.ok ? `<span class="tag published">✓ ${s.count} vídeos</span>` : '<span class="tag open">✗ não funcionou</span>'}</td>
          <td>${s.thumb_ok === null ? '—' : s.thumb_ok ? `<span class="tag published">✓ ${esc(s.thumb_msg)}</span>` : `<span class="tag open">✗</span> <small>${esc(s.thumb_msg)}</small>`}</td>
          <td><small>${s.ok ? `Respondeu em ${s.time}s` + (s.thumb ? ` · <a href="#" data-thumb="${esc(s.thumb)}" data-p="${esc(s.site.toLowerCase())}">ver thumb</a>` : '') : esc(s.msg) + (s.http && !/HTTP/.test(s.msg) ? ` (HTTP ${s.http})` : '')}</small></td></tr>`).join('') +
        `</tbody></table></div>` + (r.sites.some(s => s.ok) ? '<p class="muted">Use os sites que funcionaram (ou “Todos”: os que não respondem são pulados).</p>'
        : '<div class="alert err">Nenhum site respondeu ao seu servidor. Envie esta tela para o suporte da hospedagem e pergunte se conexões de saída (cURL/HTTPS) estão liberadas.</div>');
      $$('[data-thumb]', out).forEach(a => a.addEventListener('click', e => { e.preventDefault(); window.open(proxy(a.dataset.thumb, a.dataset.p), '_blank'); }));
    });
    $('#search-stop').addEventListener('click', () => { stop = true; status('Parando...'); });
    $('#search-more').addEventListener('click', () => { if (!busy) pick(items.length + (+sForm.qty.value || 100)); });
    grid.addEventListener('click', e => { const it = e.target.closest('.ritem'); if (it && !it.classList.contains('exists') && !busy) { it.classList.toggle('sel'); count(); } });
    $('#search-all').addEventListener('click', () => { $$('.ritem:not(.exists)', grid).forEach(i => i.classList.add('sel')); count(); });
    $('#search-none').addEventListener('click', () => { $$('.ritem', grid).forEach(i => i.classList.remove('sel')); count(); });
    $('#search-import').addEventListener('click', async () => {
      const sel = $$('.ritem.sel', grid).map(el => items[+el.dataset.i]);
      if (!sel.length) return alert('Selecione pelo menos um vídeo.');
      setBusy(true); stop = false;
      const r = await importQueue(sel.slice(), sel.length, 'Importando selecionados', false);
      line('ok', `— ${r.ok} importados, ${r.skip} já existiam, ${r.err} erros —`);
      setBusy(false); count();
    });
  }
})();

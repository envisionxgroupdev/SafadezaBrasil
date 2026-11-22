(function () {
  const $ = (s, c = document) => c.querySelector(s);

  // Thumb que não carrega: tenta os outros quadros do vídeo antes de mostrar a imagem padrão
  const NO_THUMB = ((window.APP && window.APP.base) || '/') + 'assets/img/no-thumb.svg';
  window.thumbFail = img => {
    const box = img.closest('[data-frames]');
    const list = box ? box.dataset.frames.split('|').filter(Boolean) : [];
    const tried = (img.dataset.tried || '').split('|');
    tried.push(img.getAttribute('src'));
    const next = list.find(f => !tried.includes(f));
    img.dataset.tried = tried.join('|');
    if (next) { img.src = next; if (box && box._setOrig) box._setOrig(next); }
    else { img.onerror = null; img.src = NO_THUMB; img.classList.add('no-thumb'); }
  };

  // Age gate
  const gate = $('#agegate');
  if (gate) {
    document.body.style.overflow = 'hidden';
    $('#age-yes').addEventListener('click', () => {
      document.cookie = 'age_ok=1; max-age=' + 60 * 60 * 24 * 365 + '; path=/; SameSite=Lax';
      gate.remove();
      document.body.style.overflow = '';
    });
  }

  // Prévia animada da thumb (mouse por cima no PC; rolando a tela no celular)
  const cards = document.querySelectorAll('.thumb[data-frames], .thumb[data-sprite]');
  cards.forEach(a => {
    const bar = a.querySelector('.pbar i');
    // Formato leve: todos os quadros numa imagem só ("sprite")
    if (a.dataset.sprite) {
      const n = Math.max(2, +a.dataset.n || 2);
      let layer = null, timer = null, i = 0;
      a._start = () => {
        if (timer) return;
        if (!layer) {
          layer = document.createElement('span'); layer.className = 'sprite';
          layer.style.backgroundImage = 'url("' + a.dataset.sprite.replace(/"/g, '%22') + '")';
          layer.style.backgroundSize = (n * 100) + '% 100%';
          a.insertBefore(layer, a.querySelector('img').nextSibling);
        }
        i = 0; a.classList.add('previewing');
        const step = () => { const k = i % n; layer.style.backgroundPosition = (k / (n - 1) * 100) + '% 0'; if (bar) bar.style.width = (k + 1) / n * 100 + '%'; i++; };
        timer = setTimeout(function run() { step(); timer = setTimeout(run, 650); }, 120);
      };
      a._stop = () => { clearTimeout(timer); timer = null; a.classList.remove('previewing'); if (bar) bar.style.width = '0'; };
      a.addEventListener('mouseenter', a._start);
      a.addEventListener('mouseleave', a._stop);
      return;
    }
    const frames = a.dataset.frames.split('|').filter(Boolean);
    const img = a.querySelector('img');
    if (!img || !frames.length) return;
    let timer = null, i = 0, loaded = false; let orig = img.getAttribute('src');
    a._setOrig = u => { orig = u; };
    a._start = () => {
      if (timer) return;
      if (!loaded) { frames.forEach(f => { const p = new Image(); p.referrerPolicy = 'no-referrer'; p.src = f; }); loaded = true; }
      i = 0; a.classList.add('previewing');
      const step = () => { img.src = frames[i % frames.length]; if (bar) bar.style.width = ((i % frames.length) + 1) / frames.length * 100 + '%'; i++; };
      timer = setTimeout(function run() { step(); timer = setTimeout(run, 650); }, 120);
    };
    a._stop = () => { clearTimeout(timer); timer = null; img.src = orig; a.classList.remove('previewing'); if (bar) bar.style.width = '0'; };
    a.addEventListener('mouseenter', a._start);
    a.addEventListener('mouseleave', a._stop);
  });
  if (cards.length && window.matchMedia('(hover: none)').matches && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(en => {
      const a = en.target; if (!a._start) return;
      en.intersectionRatio >= 0.95 ? a._start() : a._stop();
    }), { threshold: [0, 0.95], rootMargin: '-25% 0px -25% 0px' });
    cards.forEach(a => io.observe(a));
  }

  // Menu mobile
  const mb = $('#menu-btn');
  if (mb) mb.addEventListener('click', () => {
    const open = $('#mainnav').classList.toggle('open');
    mb.setAttribute('aria-expanded', open ? 'true' : 'false');
    mb.classList.toggle('is-open', open);
  });

  // Toast
  function toast(msg) {
    let t = $('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2400);
  }
  window.toast = toast;

  async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action); fd.append('csrf', window.APP.csrf);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(window.APP.base + 'api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  // Curtir / não curtir
  document.querySelectorAll('[data-vote]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const res = await api('vote', { id: btn.dataset.id, type: btn.dataset.vote });
      if (!res.ok) return toast(res.msg || 'Erro');
      $('#likes').textContent = res.likes;
      $('#dislikes').textContent = res.dislikes;
      $('#rating-pct').textContent = res.pct + '%';
      $('#rating-fill').style.width = res.pct + '%';
      document.querySelectorAll('[data-vote]').forEach(b => b.classList.toggle('voted', b.dataset.vote === res.voted));
    });
  });

  // Compartilhar
  const share = $('#share-btn');
  if (share) share.addEventListener('click', async () => {
    try {
      if (navigator.share) await navigator.share({ title: document.title, url: location.href });
      else { await navigator.clipboard.writeText(location.href); toast('Link copiado!'); }
    } catch (e) {}
  });

  // Denunciar
  const modal = $('#report-modal');
  if (modal) {
    $('#report-btn').addEventListener('click', () => modal.classList.add('open'));
    modal.addEventListener('click', e => { if (e.target === modal || e.target.dataset.close !== undefined) modal.classList.remove('open'); });
    $('#report-form').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target;
      const res = await api('report', { id: f.id.value, reason: f.reason.value, details: f.details.value });
      modal.classList.remove('open');
      toast(res.ok ? 'Denúncia enviada. Obrigado!' : (res.msg || 'Erro'));
      f.reset();
    });
  }
  document.querySelectorAll('.thumb img').forEach(img => { if (img.complete && img.naturalWidth === 0 && !img.src.endsWith('no-thumb.svg')) window.thumbFail(img); });
})();

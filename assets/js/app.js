(function () {
  const $ = (s, c = document) => c.querySelector(s);

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
  const cards = document.querySelectorAll('.thumb[data-frames]');
  cards.forEach(a => {
    const frames = a.dataset.frames.split('|').filter(Boolean);
    const img = a.querySelector('img'); const bar = a.querySelector('.pbar i');
    if (!img || !frames.length) return;
    let timer = null, i = 0, loaded = false; const orig = img.getAttribute('src');
    a._start = () => {
      if (timer) return;
      if (!loaded) { frames.forEach(f => { const p = new Image(); p.src = f; }); loaded = true; }
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
})();

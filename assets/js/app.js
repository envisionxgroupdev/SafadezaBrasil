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

  // Menu mobile
  const mb = $('#menu-btn');
  if (mb) mb.addEventListener('click', () => $('#mainnav').classList.toggle('open'));

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

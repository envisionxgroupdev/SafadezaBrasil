</main>

<?php if ($ad = setting('ad_footer', '')): ?><div class="ad ad-footer wrap"><?= $ad ?></div><?php endif; ?>

<footer class="footer">
  <div class="wrap">
    <div class="footer-top">
      <div>
        <a href="<?= e(base_url()) ?>" aria-label="<?= e(site_name()) ?>"><?= logo_html('sm') ?></a>
        <p class="muted"><?= e(site_tagline()) ?></p>
      </div>
      <div class="footer-links">
        <a href="<?= e(base_url('page.php?p=termos')) ?>">Termos de Uso</a>
        <a href="<?= e(base_url('page.php?p=privacidade')) ?>">Privacidade</a>
        <a href="<?= e(base_url('page.php?p=dmca')) ?>">DMCA / Remoção</a>
        <a href="<?= e(base_url('page.php?p=2257')) ?>">18 U.S.C. 2257</a>
        <a href="<?= e(base_url('page.php?p=contato')) ?>">Contato</a>
      </div>
    </div>
    <p class="disclaimer">
      <?= e(site_name()) ?> não hospeda nenhum vídeo. Todo o conteúdo é incorporado (embed) a partir de sites de terceiros,
      que são responsáveis por ele. Todos os modelos tinham 18 anos ou mais no momento da gravação.
      Para denunciar um vídeo, use o botão “Denunciar” na página do vídeo.
    </p>
    <p class="muted small">&copy; <?= date('Y') ?> <?= e(site_name()) ?>. Somente para maiores de 18 anos.</p>
  </div>
</footer>

<script>window.APP = { base: <?= json_encode(base_url()) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="<?= e(base_url('assets/js/app.js')) ?>?v=2"></script>
<?= setting('footer_code', '') ?>
</body>
</html>

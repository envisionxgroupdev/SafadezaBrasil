<?php
/**
 * Sitemap automático (com extensão de vídeo do Google).
 *   sitemap.php                    -> índice
 *   sitemap.php?t=pages            -> início, categorias, páginas
 *   sitemap.php?t=videos&p=1       -> vídeos (5.000 por arquivo)
 */
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
const PER_SITEMAP = 5000;

$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$abs = fn($u) => preg_match('~^https?://~', $u) ? $u : absolute_url(ltrim(preg_replace('~^' . preg_quote(rtrim(base_url(), '/'), '~') . '~', '', $u), '/'));
$t = $_GET['t'] ?? '';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($t === '') {
    $pages = max(1, (int)ceil(count_videos() / PER_SITEMAP));
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '<sitemap><loc>' . $x(absolute_url('sitemap.php?t=pages')) . '</loc></sitemap>' . "\n";
    for ($i = 1; $i <= $pages; $i++) echo '<sitemap><loc>' . $x(absolute_url('sitemap.php?t=videos&p=' . $i)) . '</loc></sitemap>' . "\n";
    echo '</sitemapindex>';
    exit;
}

if ($t === 'pages') {
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $urls = [[absolute_url(), 'daily', '1.0'], [absolute_url('videos.php?sort=vistos'), 'daily', '0.8'],
             [absolute_url('categories.php'), 'weekly', '0.8'], [absolute_url('tags.php'), 'weekly', '0.6']];
    foreach (all_categories() as $c) $urls[] = [$abs(category_url($c)), 'daily', '0.8'];
    foreach (['termos', 'privacidade', 'dmca', '2257', 'contato'] as $p) $urls[] = [absolute_url('page.php?p=' . $p), 'yearly', '0.2'];
    foreach ($urls as [$u, $f, $pr]) echo "<url><loc>{$x($u)}</loc><changefreq>$f</changefreq><priority>$pr</priority></url>\n";
    echo '</urlset>';
    exit;
}

$p = max(1, (int)($_GET['p'] ?? 1));
$videos = list_videos(['order' => 'id ASC', 'limit' => PER_SITEMAP, 'offset' => ($p - 1) * PER_SITEMAP]);
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";
foreach ($videos as $v) {
    echo '<url><loc>' . $x($abs(video_url($v))) . '</loc><lastmod>' . date('Y-m-d', strtotime($v['created_at'])) . '</lastmod>';
    if ($v['thumb']) {
        echo '<video:video>'
           . '<video:thumbnail_loc>' . $x(thumb_abs($v['thumb'])) . '</video:thumbnail_loc>'
           . '<video:title>' . $x(mb_substr($v['title'], 0, 100)) . '</video:title>'
           . '<video:description>' . $x(mb_substr($v['description'] ?: $v['title'], 0, 2000)) . '</video:description>'
           . '<video:player_loc>' . $x($v['embed_url']) . '</video:player_loc>'
           . ((int)$v['duration'] > 0 ? '<video:duration>' . min(28800, (int)$v['duration']) . '</video:duration>' : '')
           . '<video:view_count>' . (int)$v['views'] . '</video:view_count>'
           . '<video:publication_date>' . date('c', strtotime($v['created_at'])) . '</video:publication_date>'
           . '<video:family_friendly>no</video:family_friendly>'
           . '</video:video>';
    }
    echo "</url>\n";
}
echo '</urlset>';

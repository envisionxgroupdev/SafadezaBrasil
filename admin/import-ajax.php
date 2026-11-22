<?php
require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (!is_admin()) out(['ok' => false, 'msg' => 'Faça login novamente']);
if (!csrf_valid($_POST['csrf'] ?? '')) out(['ok' => false, 'msg' => 'Sessão expirada, recarregue a página']);
@set_time_limit(120);
session_write_close(); // não trava outras abas enquanto busca

$opts = [
    'categories'      => array_map('intval', (array)($_POST['categories'] ?? [])),
    'status'          => in_array($_POST['status'] ?? '', ['published', 'draft'], true) ? $_POST['status'] : 'published',
    'auto_categories' => !empty($_POST['auto_categories']),
];
$provs = importer_providers();
$clear = fn() => @unlink(__DIR__ . '/../data/cache_tags.json');

switch ($_POST['action'] ?? '') {

    case 'parse': {
        $p = importer_parse($_POST['input'] ?? '');
        if (!$p) out(['ok' => false, 'msg' => 'Link não reconhecido. Cole o link da página do vídeo ou o código <iframe> de embed.']);
        $meta = importer_fetch_meta($p);
        out(['ok' => true, 'data' => $p + $meta + [
            'provider_name' => $provs[$p['provider']]['name'] ?? 'Outro site',
            'duration_fmt'  => fmt_duration((int)$meta['duration']),
            'exists'        => importer_exists($p['provider'], $p['source_id']),
            'suggested'     => importer_guess_categories(($meta['title'] ?? '') . ' ' . implode(' ', $meta['tags'] ?? [])),
        ]]);
    }

    case 'save': {
        $d = [
            'provider' => $_POST['provider'] ?? '', 'source_id' => $_POST['source_id'] ?? '',
            'embed_url' => trim($_POST['embed_url'] ?? ''), 'source_url' => $_POST['source_url'] ?? '',
            'title' => $_POST['title'] ?? '', 'thumb' => trim($_POST['thumb'] ?? ''),
            'duration' => parse_duration($_POST['duration'] ?? ''), 'tags' => split_list($_POST['tags'] ?? ''),
            'description' => $_POST['description'] ?? '', 'models' => $_POST['models'] ?? '', 'featured' => !empty($_POST['featured']),
            'thumbs' => array_values(array_filter((array)json_decode($_POST['thumbs'] ?? '[]', true), 'is_string')),
        ];
        if (!filter_var($d['embed_url'], FILTER_VALIDATE_URL)) out(['ok' => false, 'msg' => 'URL de embed inválida']);
        [$ok, $r] = importer_save($d, $opts);
        if ($ok) $clear();
        out($ok ? ['ok' => true, 'id' => $r, 'url' => video_url(db()->find('videos', $r))] : ['ok' => false, 'msg' => $r]);
    }

    case 'bulk_one': {
        $in = trim($_POST['input'] ?? '');
        $p = importer_parse($in);
        if (!$p) out(['ok' => false, 'status' => 'err', 'msg' => 'não reconhecido']);
        if (importer_exists($p['provider'], $p['source_id'])) out(['ok' => true, 'status' => 'skip', 'msg' => 'já existe']);
        $meta = empty($_POST['no_meta']) ? importer_fetch_meta($p) : [];
        if (!empty($_POST['require_title']) && empty($meta['title'])) out(['ok' => false, 'status' => 'err', 'msg' => 'não foi possível ler o título']);
        [$ok, $r] = importer_save($p + $meta, $opts);
        if ($ok) $clear();
        out(['ok' => $ok, 'status' => $ok ? 'ok' : 'err', 'msg' => $ok ? ($meta['title'] ?? 'importado') : $r]);
    }

    case 'search': {
        $prov = $_POST['provider'] ?? '';
        if (!isset(importer_search_providers()[$prov])) out(['ok' => false, 'msg' => 'Site inválido']);
        $q = trim($_POST['q'] ?? '');
        if ($q === '') out(['ok' => false, 'msg' => 'Digite uma palavra-chave']);
        $list = importer_search($prov, $q, max(1, (int)($_POST['page'] ?? 1)), $_POST['order'] ?? 'recentes');
        foreach ($list as &$r) $r['duration_fmt'] = fmt_duration((int)$r['duration']);
        out(['ok' => true, 'items' => $list, 'msg' => $list ? '' : 'Nada encontrado (ou a API do site não respondeu).']);
    }

    case 'save_one': {
        $it = json_decode($_POST['item'] ?? '', true);
        if (!is_array($it) || !isset($provs[$it['provider'] ?? ''])) out(['ok' => false, 'status' => 'err', 'msg' => 'Item inválido']);
        $it['embed_url'] = str_replace('{id}', (string)$it['source_id'], $provs[$it['provider']]['embed']);
        [$ok, $r] = importer_save($it, $opts);
        if ($ok) $clear();
        $v = $ok ? db()->find('videos', $r) : null;
        out(['ok' => $ok, 'status' => $ok ? 'ok' : ($r === 'Já existe no site' ? 'skip' : 'err'), 'msg' => $ok ? '' : $r,
             'local_thumb' => $v ? thumb_is_local($v['thumb']) : false]);
    }
}
out(['ok' => false, 'msg' => 'Ação inválida']);

<?php
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'msg' => 'Método inválido']);
if (!csrf_valid($_POST['csrf'] ?? '')) out(['ok' => false, 'msg' => 'Sessão expirada, recarregue a página']);

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$v = $id ? db()->find('videos', $id) : null;
if (!$v || $v['status'] !== 'published') out(['ok' => false, 'msg' => 'Vídeo não encontrado']);

switch ($action) {
    case 'vote':
        if (!rl_hit('vote:' . client_ip(), 40, 600)) out(['ok' => false, 'msg' => 'Calma! Muitos votos em pouco tempo.']);
        $type = $_POST['type'] === 'down' ? 'down' : 'up';
        $prev = $_SESSION['votes'][$id] ?? null;
        $likes = (int)$v['likes']; $dislikes = (int)$v['dislikes'];
        if ($prev === $type) out(['ok' => true, 'likes' => $likes, 'dislikes' => $dislikes, 'pct' => rating_pct($v), 'voted' => $type]);
        if ($prev === 'up') $likes = max(0, $likes - 1);
        if ($prev === 'down') $dislikes = max(0, $dislikes - 1);
        $type === 'up' ? $likes++ : $dislikes++;
        db()->update('videos', $id, ['likes' => $likes, 'dislikes' => $dislikes]);
        $_SESSION['votes'][$id] = $type;
        out(['ok' => true, 'likes' => $likes, 'dislikes' => $dislikes,
             'pct' => rating_pct(['likes' => $likes, 'dislikes' => $dislikes]), 'voted' => $type]);

    case 'report':
        $reasons = ['menor' => 'Possível menor de idade', 'sem_consentimento' => 'Sem consentimento', 'direitos' => 'Direitos autorais',
                    'quebrado' => 'Vídeo não funciona', 'outro' => 'Outro'];
        $reason = array_key_exists($_POST['reason'] ?? '', $reasons) ? $_POST['reason'] : 'outro';
        if (!rl_hit('report:' . client_ip(), 10, 3600)) out(['ok' => false, 'msg' => 'Muitas denúncias. Tente mais tarde.']);
        db()->insert('reports', ['video_id' => $id, 'reason' => $reason, 'details' => mb_substr(trim($_POST['details'] ?? ''), 0, 1000),
                                 'ip' => client_ip(), 'status' => 'open', 'created_at' => now()]);
        // Denúncias graves tiram o vídeo do ar automaticamente até você revisar
        if (in_array($reason, ['menor', 'sem_consentimento'], true)) db()->update('videos', $id, ['status' => 'review']);
        out(['ok' => true]);
}
out(['ok' => false, 'msg' => 'Ação inválida']);

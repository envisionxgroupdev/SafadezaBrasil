<?php
/**
 * ============================================================
 *  CONFIGURAÇÃO PRINCIPAL DO SITE
 * ============================================================
 *  O NOME DO SITE fica SÓ AQUI (SITE_NAME). Todas as páginas,
 *  títulos, logo, rodapé e painel usam essa constante.
 *  Também dá para trocar pelo painel: Admin > Configurações.
 * ============================================================
 */

define('SITE_NAME',    'Safadeza Brasil');     // <<< troque aqui o nome
define('SITE_TAGLINE', 'Vídeos adultos grátis em HD');
define('SITE_URL',     '');                    // ex.: https://seudominio.com (vazio = automático)

// ---------- Banco de dados ----------
// 'json'  = salva em arquivos na pasta /data (funciona sem banco, para começar)
// 'mysql' = usa MySQL (importe install/schema.sql e preencha os dados abaixo)
define('DB_DRIVER', 'json');

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'nome_do_banco');
define('DB_USER', 'usuario');
define('DB_PASS', 'senha');
define('DB_CHARSET', 'utf8mb4');

// ---------- Admin padrão (troque a senha no primeiro acesso!) ----------
define('ADMIN_DEFAULT_USER', 'admin');
define('ADMIN_DEFAULT_PASS', 'admin123');

// ---------- Geral ----------
define('VIDEOS_PER_PAGE', 24);
define('PRETTY_URLS', false);   // true = links tipo /video/12/titulo (precisa Apache com mod_rewrite + .htaccess)
define('TIMEZONE', 'America/Sao_Paulo');
define('DEBUG', false);

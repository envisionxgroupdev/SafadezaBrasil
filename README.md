# Safadeza Brasil

Site de vídeos adultos (+18) estilo tube, em PHP + MySQL, com painel admin, importador de embeds e área de SEO.

## Requisitos
- Hospedagem com PHP 8.0+ (extensões pdo_mysql, curl, mbstring)
- Um banco MySQL

## Instalação (3 passos, sem mexer em código)
1. Envie todos os arquivos para a pasta pública da hospedagem (`public_html`, `htdocs` ou `www`).
2. No painel da hospedagem, crie um **banco MySQL** e um **usuário** com senha, e adicione o usuário ao banco com **todos os privilégios**.
3. Acesse `seudominio.com` — o **instalador** abre sozinho. Ele:
   - confere o servidor,
   - pede a **chave de instalação** (abra `data/chave-instalacao.txt` no Gerenciador de Arquivos) — assim ninguém instala no seu lugar,
   - conecta no banco e cria as tabelas,
   - pede o nome do site e cria o seu login de admin.

Pronto: você cai direto no painel para importar vídeos.
Os dados do banco ficam em `data/config.local.php` (gerado pelo instalador).
Para reinstalar, apague `data/installed.lock`.

## Painel (`seudominio.com/admin`)
- **Importador**: link único, vários links de uma vez, ou busca por palavra-chave (Pornhub, RedTube, Eporner).
  Funciona com XVideos, XNXX, Pornhub, xHamster, RedTube, YouPorn, SpankBang, Eporner, Tube8, TXXX e qualquer `<iframe>`.
- **Vídeos / Categorias / Denúncias / Páginas**
- **SEO**: verificação do Google Search Console, Bing, Yandex e Pinterest; Google Analytics 4 e Tag Manager;
  título e descrição da página inicial (com prévia do Google); robots.txt; bloquear buscadores.
  Sitemap automático com extensão de vídeo: `seudominio.com/sitemap.php`.
- **Configurações**: nome do site, cor principal, e-mail, espaços de anúncio, troca de senha.

## Thumbs
As thumbs são baixadas para `uploads/thumbs` na importação, num formato leve:
- thumb principal em WebP 480px (~15–25 KB)
- prévia animada com todos os quadros numa única imagem (~25 KB)

A importação em massa grava o vídeo na hora e baixa as thumbs **em segundo plano**. Se alguma thumb não foi salva,
ela é baixada na primeira visita (`thumb.php`); se o servidor não conseguir, o navegador carrega direto do site de origem.

São cerca de **45 KB e 2 arquivos por vídeo** (10 mil vídeos ≈ 450 MB).
Em Admin › Configurações › Thumbs dá para ver o espaço usado, escolher o tamanho (econômico/padrão/alto),
desligar a prévia, limpar thumbs sem uso e converter thumbs antigas.

## Segurança
- **Login**: verificação em duas etapas (Google Authenticator, Microsoft Authenticator, 2FAS) com códigos de recuperação;
  5 senhas erradas em 15 min bloqueiam o IP por 15 min; 20 em 24h bloqueiam o IP no site inteiro por 24h;
  sessão presa ao navegador, expira em 4h, botão para desconectar todos os dispositivos; todos os acessos registrados.
- **Firewall**: bloqueia na hora tentativas de SQL injection, XSS, invasão de pastas e execução de código pela URL;
  5 tentativas = IP bloqueado por 24h; excesso de acessos por minuto também bloqueia. Lista manual de IPs/faixas bloqueados.
- **IP real com Cloudflare** (só confia no cabeçalho quando a conexão vem mesmo do Cloudflare).
- Instalador com chave secreta e bloqueio automático depois de instalado.
- CSRF em todos os formulários, cabeçalhos de segurança, forçar HTTPS, proteção SSRF no importador
  (IP fixado e redirecionamentos conferidos), links só http/https, `uploads` sem execução de código.
- Opção de impedir pop-ups e redirecionamentos dos players de outros sites.
- **Admin › Segurança** mostra as verificações, eventos de ataque, IPs bloqueados e últimos acessos.

## Links bonitos (opcional)
O site **não tem `.htaccess` na pasta principal** de propósito: lá ficam as configurações da hospedagem
(versão do PHP, HTTPS), e substituí-lo faz o navegador baixar os arquivos `.php`.
Para links `/video/12/titulo-do-video`, cole o conteúdo de `htaccess-opcional.txt` **no final** do `.htaccess`
da hospedagem e mude `PRETTY_URLS` para `true` no `config.php`.

### O navegador baixa os arquivos .php em vez de abrir?
A hospedagem perdeu a linha que liga o PHP. No cPanel: **MultiPHP Manager** → marque o domínio → escolha PHP 8.1+ → **Aplicar**.
Na Hostinger: **Avançado → Configuração do PHP** → escolha a versão → **Salvar**. Depois reative o HTTPS forçado no painel da hospedagem se ele estava ligado.

## Segurança do conteúdo
Todo vídeo tem botão "Denunciar". Denúncias de possível menor ou sem consentimento tiram o vídeo do ar na hora até você revisar em Admin > Denúncias.

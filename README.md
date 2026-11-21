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

## Links bonitos (opcional)
Em Apache, mude `PRETTY_URLS` para `true` no `config.php` → `/video/12/titulo-do-video`.

## Segurança do conteúdo
Todo vídeo tem botão "Denunciar". Denúncias de possível menor ou sem consentimento tiram o vídeo do ar na hora até você revisar em Admin > Denúncias.

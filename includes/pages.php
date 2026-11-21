<?php
/** Páginas institucionais. Textos padrão — edite em Admin > Páginas. {SITE} e {EMAIL} são trocados automaticamente. */
function static_pages(): array {
    return [
        'termos' => ['title' => 'Termos de Uso', 'default' => '
<p>Ao acessar o {SITE} você declara ter 18 anos ou mais (ou a maioridade legal no seu país) e que o acesso a conteúdo adulto é permitido onde você está.</p>
<h2>Conteúdo</h2>
<p>O {SITE} não hospeda nenhum arquivo de vídeo. Todos os vídeos são incorporados (embed) a partir de sites de terceiros, que são responsáveis pelo conteúdo, pela verificação de idade e pelo consentimento dos participantes.</p>
<h2>Uso proibido</h2>
<p>É proibido usar o site se você for menor de idade, redistribuir o conteúdo ou tentar burlar qualquer mecanismo de segurança.</p>
<h2>Tolerância zero</h2>
<p>Temos tolerância zero com qualquer conteúdo envolvendo menores ou pessoas sem consentimento. Denuncie pelo botão “Denunciar” ou pelo e-mail {EMAIL}. O conteúdo denunciado é retirado do ar para análise.</p>'],
        'privacidade' => ['title' => 'Política de Privacidade', 'default' => '
<p>O {SITE} não exige cadastro. Usamos cookies apenas para lembrar a confirmação de idade, seus votos e preferências.</p>
<p>Os vídeos são exibidos por players de terceiros, que podem usar seus próprios cookies. Anunciantes também podem usar cookies. Para dúvidas sobre seus dados (LGPD), escreva para {EMAIL}.</p>'],
        'dmca' => ['title' => 'DMCA / Remoção de conteúdo', 'default' => '
<p>Respeitamos direitos autorais. Como o {SITE} só incorpora vídeos de outros sites, a remoção definitiva deve ser pedida também ao site de origem — mas removemos o link daqui rapidamente.</p>
<p>Envie para {EMAIL}: o link da página do vídeo no {SITE}, prova de que você é o titular (ou representante) e seus dados de contato. Respondemos em até 48 horas.</p>'],
        '2257' => ['title' => '18 U.S.C. 2257', 'default' => '
<p>O {SITE} não produz nenhum conteúdo. Todo o material é incorporado de sites de terceiros, que são os produtores secundários responsáveis pela manutenção dos registros exigidos pela 18 U.S.C. 2257 e 28 C.F.R. 75. Consulte a página 2257 do site de origem de cada vídeo.</p>'],
        'contato' => ['title' => 'Contato', 'default' => '
<p>Fale com a equipe do {SITE} pelo e-mail <strong>{EMAIL}</strong>.</p>
<p>Para denunciar um vídeo, use o botão “Denunciar” na página dele — é o jeito mais rápido.</p>'],
    ];
}

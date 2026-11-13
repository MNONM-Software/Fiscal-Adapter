<?php

use Mnonm\FiscalAdapter\Arca\Soap\Redactor;

it('redacts token, sign and CMS with or without prefix in any case', function () {
    $out = Redactor::redact('<x><ar:Auth><ar:Token>TOK-2</ar:Token><ar:Sign>FIR-2</ar:Sign></ar:Auth><wsaa:in0>CMS-SECRETO</wsaa:in0><token>TOK</token><SIGN a="1">SIG</SIGN></x>');

    expect($out)->not->toContain('TOK-2')->not->toContain('FIR-2')->not->toContain('CMS-SECRETO')
        ->not->toContain('>TOK<')->not->toContain('>SIG<')
        ->and($out)->toContain('[REDACTED]');
});

it('redacts escaped token and sign inside loginCmsReturn', function () {
    $out = Redactor::redact('<loginCmsReturn>&lt;credentials&gt;&lt;token&gt;TOK-ESC&lt;/token&gt;&lt;Sign&gt;FIR-ESC&lt;/Sign&gt;&lt;/credentials&gt;</loginCmsReturn>');

    expect($out)->not->toContain('TOK-ESC')->not->toContain('FIR-ESC')
        ->and($out)->toContain('&lt;token&gt;[REDACTED]&lt;/token&gt;');
});

it('leaves unrelated content untouched', function () {
    expect(Redactor::redact('<a>hola</a>'))->toBe('<a>hola</a>');
});

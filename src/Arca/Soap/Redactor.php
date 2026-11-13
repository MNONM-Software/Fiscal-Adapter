<?php

namespace Mnonm\FiscalAdapter\Arca\Soap;

final class Redactor
{
    /**
     * Tapa el contenido de token, sign e in0 (el CMS de loginCms) con o sin prefijo de namespace y en
     * cualquier mayúscula. También la forma escapada (`&lt;token&gt;`) con que WSAA devuelve el
     * ticket dentro de `loginCmsReturn`.
     */
    public static function redact(string $xml): string
    {
        $xml = preg_replace(
            '/<((?:[\w-]+:)?(?:token|sign|in0))(\s[^>]*)?>.*?<\/\1>/is',
            '<$1$2>[REDACTED]</$1>',
            $xml,
        ) ?? '[REDACTED]';

        return preg_replace(
            '/&lt;((?:[\w-]+:)?(?:token|sign))&gt;.*?&lt;\/\1&gt;/is',
            '&lt;$1&gt;[REDACTED]&lt;/$1&gt;',
            $xml,
        ) ?? '[REDACTED]';
    }
}

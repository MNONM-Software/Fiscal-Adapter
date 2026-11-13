<?php

return [
    'arca' => [
        /*
         * Segundos de espera de cada llamada SOAP a ARCA.
         */
        'timeout' => 30,

        /*
         * Sobrescribe URLs por ambiente y servicio: ['homologation' => ['wscpe' => 'https://…']].
         */
        'endpoints' => [],

        /*
         * Segundos que dura (y que se espera) el lock de renovación del ticket de acceso. Debe ser
         * mayor que `timeout`; con null se usa `timeout + 60`.
         */
        'lock_seconds' => null,
    ],
];

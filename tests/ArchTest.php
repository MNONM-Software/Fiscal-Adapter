<?php

arch('core does not know ARCA')->expect([
    'Mnonm\FiscalAdapter\Contracts',
    'Mnonm\FiscalAdapter\Exceptions',
    'Mnonm\FiscalAdapter\Testing',
])->not->toUse('Mnonm\FiscalAdapter\Arca');

arch('no ext-soap')->expect('Mnonm\FiscalAdapter')->not->toUse(['SoapClient', 'SoapServer']);

arch('no database')->expect('Mnonm\FiscalAdapter')->not->toUse([
    'PDO',
    'Illuminate\Database',
    'Illuminate\Support\Facades\DB',
    'Illuminate\Support\Facades\Schema',
]);

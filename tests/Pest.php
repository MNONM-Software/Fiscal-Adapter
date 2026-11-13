<?php

use Mnonm\FiscalAdapter\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Homologation');

// Everything under Homologation hits the real services: the group is applied by directory so a new
// test can never run in CI by forgetting it.
uses()->group('homologation')->in('Homologation');

require_once __DIR__.'/Support/arca-helpers.php';

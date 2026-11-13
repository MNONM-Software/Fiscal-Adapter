<?php

use Mnonm\FiscalAdapter\Arca\Cuit;
use Mnonm\FiscalAdapter\Arca\Wsaa\AccessTicket;
use Mnonm\FiscalAdapter\Arca\Wscpe\AnnulmentReason;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillAnnulment;
use Mnonm\FiscalAdapter\Arca\Wscpe\ArcaWaybillQuery;
use Mnonm\FiscalAdapter\Arca\Wscpe\WscpeXml;
use Mnonm\FiscalAdapter\Tests\Support\Fixtures;

function wscpeTicket(): AccessTicket
{
    return new AccessTicket('TOK', 'SIG', now()->toImmutable()->addHours(6));
}

function represented(): Cuit
{
    return Cuit::from('30712345671');
}

it('builds the authorize envelope in schema order', function () {
    $xml = Fixtures::waybill()->envelope(wscpeTicket(), represented());

    expect($xml)->toContain('<wsc:AutorizarCPEAutomotorReq><auth><token>TOK</token><sign>SIG</sign><cuitRepresentada>30712345671</cuitRepresentada></auth>')
        ->toContain('<solicitud><cabecera><tipoCP>74</tipoCP><cuitSolicitante>30712345671</cuitSolicitante><sucursal>1</sucursal><nroOrden>419</nroOrden></cabecera>'
            .'<origen><productor><codProvincia>9</codProvincia><codLocalidad>1234</codLocalidad></productor></origen>'
            .'<correspondeRetiroProductor>false</correspondeRetiroProductor><esSolicitanteCampo>true</esSolicitanteCampo>'
            .'<datosCarga><codGrano>23</codGrano><cosecha>2526</cosecha><pesoBruto>45000</pesoBruto><pesoTara>15000</pesoTara></datosCarga>'
            .'<destino><cuit>30500000003</cuit><esDestinoCampo>false</esDestinoCampo><codProvincia>12</codProvincia><codLocalidad>5678</codLocalidad><planta>12345</planta></destino>'
            .'<destinatario><cuit>30500000003</cuit></destinatario>'
            .'<transporte><cuitTransportista>30798765430</cuitTransportista><dominio>AB123CD</dominio><dominio>AC456DE</dominio>'
            .'<fechaHoraPartida>2026-10-08T10:00:00</fechaHoraPartida><kmRecorrer>120</kmRecorrer><cuitChofer>20123456786</cuitChofer>'
            .'<cuitPagadorFlete>30712345671</cuitPagadorFlete><mercaderiaFumigada>true</mercaderiaFumigada></transporte></solicitud>');
});

it('without plant goes as field destination', function () {
    expect(Fixtures::waybill(['destinationPlantNumber' => null])->envelope(wscpeTicket(), represented()))
        ->toContain('<esDestinoCampo>true</esDestinoCampo>')
        ->not->toContain('<planta>');
});

it('sends the not-fumigated declaration as is', function (bool $declared, string $sent) {
    expect(Fixtures::waybill(['declaredNotFumigated' => $declared])->envelope(wscpeTicket(), represented()))
        ->toContain("<mercaderiaFumigada>{$sent}</mercaderiaFumigada>");
})->with([[true, 'true'], [false, 'false']]);

it('sends departure in Argentine time', function () {
    $waybill = Fixtures::waybill(['departureAt' => new DateTimeImmutable('2026-10-08T13:00:00Z')]);

    expect($waybill->envelope(wscpeTicket(), represented()))->toContain('<fechaHoraPartida>2026-10-08T10:00:00</fechaHoraPartida>');
});

it('normalizes plates in the envelope', function () {
    expect(Fixtures::waybill(['plates' => [' ab123cd', 'AC 456 DE']])->envelope(wscpeTicket(), represented()))
        ->toContain('<dominio>AB123CD</dominio><dominio>AC456DE</dominio>');
});

it('builds the annul envelope', function () {
    $xml = (new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'Carga mal'))->envelope(wscpeTicket(), represented());

    expect($xml)->toContain('<wsc:AnularCPEReq><auth>')
        ->toContain('<solicitud><cartaPorte><tipoCPE>74</tipoCPE><sucursal>1</sucursal><nroOrden>419</nroOrden></cartaPorte><anulacionMotivo>3</anulacionMotivo><anulacionObservaciones>Carga mal</anulacionObservaciones></solicitud>');
});

it('omits empty annulment observations', function (?string $observations) {
    $xml = (new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Accident, $observations))->envelope(wscpeTicket(), represented());

    expect($xml)->toContain('<anulacionMotivo>1</anulacionMotivo></solicitud>')->not->toContain('anulacionObservaciones');
})->with([null, '']);

it('escapes observations', function () {
    $xml = (new ArcaWaybillAnnulment(1, 419, AnnulmentReason::Other, 'A & B <x>'))->envelope(wscpeTicket(), represented());

    expect($xml)->toContain('A &amp; B &lt;x&gt;')
        ->and(simplexml_load_string($xml))->not->toBeFalse();
});

it('queries by CTG', function () {
    expect(ArcaWaybillQuery::byCtg('101234567890')->envelope(wscpeTicket(), represented()))
        ->toContain('<wsc:ConsultarCPEAutomotorReq><auth>')
        ->toContain('<solicitud><nroCTG>101234567890</nroCTG></solicitud>');
});

it('queries by order number', function () {
    expect(ArcaWaybillQuery::byOrderNumber(1, 419)->envelope(wscpeTicket(), represented()))
        ->toContain('<solicitud><cartaPorte><tipoCPE>74</tipoCPE><sucursal>1</sucursal><nroOrden>419</nroOrden></cartaPorte></solicitud>');
});

it('builds the SOAP action from the method', fn () => expect(WscpeXml::soapAction('anularCPE'))->toBe('https://serviciosjava.afip.gob.ar/wscpe/anularCPE'));

it('substitutes invalid UTF-8 instead of dropping the text', function () {
    expect(\Mnonm\FiscalAdapter\Arca\Wscpe\WscpeXml::text("ab\xC3\x28cd"))->toContain('ab')->toContain('cd')->not->toBe('');
});

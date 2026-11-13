<?php

namespace Mnonm\FiscalAdapter\Arca\Wscpe;

/** Motivo de anulación de WSCPE: 1 siniestro, 2 pérdida de carga, 3 otro. */
enum AnnulmentReason: int
{
    case Accident = 1;
    case CargoLoss = 2;
    case Other = 3;
}

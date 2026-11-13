<?php

namespace Mnonm\FiscalAdapter;

enum OutcomeStatus: string
{
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}

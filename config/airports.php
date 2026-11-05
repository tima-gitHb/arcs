<?php

/*
 * IATA airport code => ISO-2 country code, for the entry requirements check (SR-49).
 * Flights store airports; entry rules are per country. An airport missing from this
 * list gives the "unknown" result, which never blocks check-in.
 */

return [
    'AMM' => 'JO',
    'ATH' => 'GR',
    'AUH' => 'AE',
    'BEY' => 'LB',
    'CAI' => 'EG',
    'CDG' => 'FR',
    'DOH' => 'QA',
    'DXB' => 'AE',
    'FCO' => 'IT',
    'FRA' => 'DE',
    'GVA' => 'CH',
    'IST' => 'TR',
    'JED' => 'SA',
    'KWI' => 'KW',
    'LCA' => 'CY',
    'LHR' => 'GB',
    'ORY' => 'FR',
    'RUH' => 'SA',
];

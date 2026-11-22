<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airline identity
    |--------------------------------------------------------------------------
    |
    | Used for flight-number examples, bag-tag prefix (via flight_no), boarding
    | passes, e-tickets and passenger emails. Seeders and factories read this.
    |
    */

    'airline' => [
        'name' => env('AIRLINE_NAME', 'Arcs Air'),
        'code' => env('AIRLINE_CODE', 'AR'),
    ],

];

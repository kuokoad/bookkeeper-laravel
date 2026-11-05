<?php

return [

    /*
    | The shop's own day. Trading dates are DATE columns decided in this zone;
    | timestamps are stored in UTC.
    */
    'business_timezone' => 'Africa/Accra',

    /*
    | Seed invented demo shop data. Refused in production.
    */
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),

];

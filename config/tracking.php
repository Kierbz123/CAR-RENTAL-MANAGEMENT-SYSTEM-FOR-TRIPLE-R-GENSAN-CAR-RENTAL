<?php
declare(strict_types=1);

/*
 * LIVE VEHICLE TRACKING
 * ---------------------
 * Settings for the tracker page a phone opens (/track) and the live map on /fleet/locations.
 * Positions are real: they come from the GPS of a phone travelling with the vehicle.
 */

return [
    // Where the map opens when no vehicle is out: the centre of General Santos City.
    // Approximate; move it to the rental office by replacing the two numbers.
    'center' => ['latitude' => 6.1164, 'longitude' => 125.1716],
    'zoom' => 12,

    // How far from the centre a rented vehicle may go before the map flags it, in kilometres.
    'service_radius_km' => 150,

    // How often the phone reports and the map asks, in seconds.
    'interval_seconds' => 5,

    // With no report for this long the vehicle is shown as "last seen", never as still moving.
    'quiet_after_seconds' => 120,

    // A phone that has not moved more than this many metres is treated as stopped.
    'stopped_within_metres' => 25,

    // How long a tracker link stays usable. It also stops working once the vehicle is returned.
    'link_days' => 30,

    /*
     * The map pictures ("tiles"). OpenStreetMap's are free for light use and need no account;
     * they ask for the credit line below to stay on the map. This server's name is also the one
     * place outside this site that the map page is allowed to load pictures from.
     */
    'tiles' => [
        'origin' => 'https://tile.openstreetmap.org',
        'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'credit' => '© OpenStreetMap contributors',
        'credit_url' => 'https://www.openstreetmap.org/copyright',
    ],
];

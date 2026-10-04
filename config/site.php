<?php
declare(strict_types=1);

/*
 * BUSINESS PROFILE
 * ----------------
 * Every public-facing business detail on the landing page comes from this file;
 * nothing is hard-coded in the page itself.
 *
 * The contact details (name, phone, address, hours, map link) are taken from the
 * Triple R Gensan Car Rental listing on Google Maps, as read on 2026-10-01:
 * https://maps.app.goo.gl/H1HPtYuUbwq3KWRRA  (re-check them there if they change).
 *
 * The fleet classes, rates and photos are still illustrative and are marked
 * DEMO-PLACEHOLDER. Replace them with the real list, then set 'is_demo' to false
 * to remove the "demonstration site" notice and let search engines index the page.
 */

return [
    // While true, the footer says this is a demonstration site and search engines are told not to index it.
    'is_demo' => true,

    'brand' => [
        'name' => 'Triple R',
        'full_name' => 'Triple R Gensan Car Rental',
    ],

    'contact' => [
        'phone_display' => '09676355474',
        'phone_href' => 'tel:+639676355474',
        'address_lines' => [
            'Blk 4 Lot 4, Reformville, Calumpang',
            'General Santos City, 9500 South Cotabato',
        ],
        'city' => 'General Santos City',
        'hours' => 'Mon–Sun, 6 AM–9 PM',
        // The same hours as numbers (24-hour clock), for the "open now" line on the landing page.
        'open_hour' => 6,
        'close_hour' => 21,
        'map_url' => 'https://maps.app.goo.gl/H1HPtYuUbwq3KWRRA',
    ],

    // Where customers send the 30% downpayment. Shown on the customer's booking page.
    // Left empty, the page tells the customer to call the office for the number.
    'payments' => [
        // Shown to customers who pay by GCash transfer and upload a proof. The other ways to pay are in config/payments.php.
        'gcash_number' => '',                                       // e.g. '0917 123 4567'
        'gcash_account_name' => '',                                 // the name GCash shows for that number
    ],

    // Hand-written list for the landing page. It is NOT read from the vehicles table.
    'currency_symbol' => '₱',                                       // Philippine peso, matching the staff workspace
    // Rates are illustrative starting prices in the range of typical Philippine market rates
    // (2025–2026): per day for the sedan, SUV and van; per event for the limousine.
    // 'image' is a file name in public/assets/img/landing/. The fleet photos were supplied by the
    // project owner for this demo; replace them with photos you have the right to use before any
    // public launch. Drawn alternatives (sedan.svg, suv.svg, van.svg, limo.svg) are in the same folder.
    // Each class on the landing page opens the booking page at /book?class=<slug>. 'body_types' lists
    // the vehicles.body_type values that belong to the class; leave it empty for a class that is not
    // booked online (the limousine comes with a chauffeur and is arranged by phone).
    'fleet' => [                                                    // DEMO-PLACEHOLDER (classes, rates, capacities)
        [
            'name' => 'Business Sedan',
            'slug' => 'sedan',
            'body_types' => ['sedan', 'hatchback'],
            'from' => 2000,
            'unit' => 'day',
            'seats' => 3,
            'bags' => 2,
            'image' => 'fleet-sedan.jpg',
            'blurb' => 'Quiet, comfortable and on time for airport runs and meetings.',
        ],
        [
            'name' => 'Luxury SUV',
            'slug' => 'suv',
            'body_types' => ['SUV', 'pickup'],
            'from' => 3500,
            'unit' => 'day',
            'seats' => 6,
            'bags' => 5,
            'image' => 'fleet-suv.webp',
            'blurb' => 'Room for the family or the team, with space for every bag.',
        ],
        [
            'name' => 'Executive Van',
            'slug' => 'van',
            'body_types' => ['van'],
            'from' => 5000,
            'unit' => 'day',
            'seats' => 10,
            'bags' => 8,
            'image' => 'fleet-van.jpg',
            'blurb' => 'One vehicle for the whole group, from hotel to venue and back.',
        ],
        [
            'name' => 'Stretch Limo',
            'slug' => 'limo',
            'body_types' => [],
            'from' => 8000,
            'unit' => 'event',
            'seats' => 8,
            'bags' => 3,
            'image' => 'fleet-limo.jpg',
            'blurb' => 'For weddings, proms and the nights worth arriving in style.',
        ],
    ],
];

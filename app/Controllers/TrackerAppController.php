<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Services\VehicleTrackingService;
use TripleR\Support\SiteProfile;

/**
 * The tracker: a small web app a phone opens at /track to share its GPS position while the
 * vehicle it travels with is on rental. There is no sign-in. The phone proves which rental it
 * reports for with the token from the link staff created, sent in the X-Tracker-Token header
 * of every request. Without a valid token nothing is shown and nothing is saved.
 */
final class TrackerAppController
{
    public function __construct(private readonly VehicleTrackingService $tracking)
    {
    }

    public function page(): Response
    {
        ob_start();
        require APP_ROOT . '/app/Views/tracker/app.php';
        return Response::html((string) ob_get_clean());
    }

    /** Lets the phone add the tracker to its home screen and open it like an app. */
    public function manifest(): Response
    {
        return Response::json([
            'name' => SiteProfile::get('brand.name', 'Triple R') . ' Tracker',
            'short_name' => 'Tracker',
            'description' => 'Shares this phone’s location with ' . SiteProfile::get('brand.full_name', 'the rental office') . ' while the vehicle is on rental.',
            'start_url' => '/track',
            'scope' => '/track',
            'display' => 'standalone',
            'background_color' => '#0d1b1e',
            'theme_color' => '#0d1b1e',
            'icons' => [['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
        ]);
    }

    /** Which vehicle the link is for, and whether positions are wanted yet. */
    public function session(Request $request): Response
    {
        $session = $this->tracking->session((string) ($request->header('X-Tracker-Token') ?? ''), $request->ip, $request->userAgent);
        return $session === null
            ? Response::json(['error' => 'This tracker link is not valid any more. Ask the rental office for a new one.'], 401)
            : Response::json($session);
    }

    /** One position from the phone. */
    public function report(Request $request): Response
    {
        try {
            $state = $this->tracking->report((string) ($request->header('X-Tracker-Token') ?? ''), $request->json(), $request->ip, $request->userAgent);
        } catch (RuntimeException $error) {
            return Response::json(['error' => $error->getMessage()], 422);
        }
        return $state === null
            ? Response::json(['error' => 'This tracker link is not valid any more. Ask the rental office for a new one.'], 401)
            : Response::json(['state' => $state]);
    }
}

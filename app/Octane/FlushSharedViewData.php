<?php

namespace App\Octane;

use Illuminate\View\Factory;
use WeakMap;

/**
 * Octane warms the view factory, so data shared into it during a request (AuthenticateCampfire's
 * `currentUser`, ShareErrorsFromSession's `errors`) would otherwise be visible to every later
 * request served by the same worker, e.g. the signed-out login page rendering the previous
 * user's `current-user-id` meta tag. Before and after each request, drop everything shared since boot.
 */
final class FlushSharedViewData
{
    /** @var WeakMap<Factory, array<string, true>>|null keys shared at boot, per factory */
    private static ?WeakMap $bootKeys = null;

    public function handle($event): void
    {
        if (! $event->sandbox->resolved('view')) {
            return;
        }
        $view = $event->sandbox->make('view');
        if (! $view instanceof Factory) {
            return;
        }
        self::$bootKeys ??= new WeakMap;
        // The first request a worker receives finds the factory as booted.
        $keep = self::$bootKeys[$view] ??= array_fill_keys(array_keys($view->getShared()), true);
        (function () use ($keep) {
            $this->shared = array_intersect_key($this->shared, $keep);
        })->call($view);
    }
}

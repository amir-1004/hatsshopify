<?php

namespace App\Http\Controllers;

use App\Models\Hat;
use Illuminate\Contracts\View\View;

class TryOnController extends Controller
{
    /**
     * The virtual try-on page. A hat can be pre-selected via the route
     * (`/try-on/{hat}`); everything else — face detection, measurement, and
     * the 3D preview — happens in the browser so the shopper's photo never
     * leaves their device.
     */
    public function index(?Hat $hat = null): View
    {
        return view('try-on', [
            'hats' => Hat::latest()->get(),
            'selected' => $hat,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class StudioController extends Controller
{
    /**
     * Render the Design Studio page. Hat pre-selection (via `?hat=`) and
     * data loading are handled client-side.
     */
    public function index(): View
    {
        return view('studio');
    }
}

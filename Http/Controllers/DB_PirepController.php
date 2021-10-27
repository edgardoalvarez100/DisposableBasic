<?php

namespace Modules\DisposableBasic\Http\Controllers;

use App\Contracts\Controller;
use App\Models\Pirep;
use App\Models\Enums\PirepState;

class DB_PirepController extends Controller
{
    // All Pireps (except inProgress)
    public function index()
    {
        $units = array('fuel' => setting('units.fuel'), 'weight' => setting('units.weight'));
        $eager_load = array('user', 'aircraft', 'airline', 'dpt_airport', 'arr_airport');

        $pireps = Pirep::with($eager_load)->where('state', '!=', PirepState::IN_PROGRESS)->orderby('submitted_at', 'desc')->paginate(25);

        return view('DBasic::pireps.index', [
            'pireps' => $pireps,
            'units'  => $units,
        ]);
    }
}

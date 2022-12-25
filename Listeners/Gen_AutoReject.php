<?php

namespace Modules\DisposableBasic\Listeners;

use App\Events\PirepFiled;
use App\Models\Enums\PirepState;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Gen_AutoReject
{
    public function handle(PirepFiled $event)
    {
        $auto_reject = DB_Setting('dbasic.autoreject', false);

        $margin_score = DB_Setting('dbasic.ar_marginscore', 0);
        $margin_lrate = DB_Setting('dbasic.ar_marginlrate', 0);
        $margin_ftime = DB_Setting('dbasic.ar_marginftime', 0);
        $margin_presence = DB_Setting('dbasic.networkcheck_margin', 75);
        $reject_presence = DB_Setting('dbasic.ar_presence', false);
        $reject_callsign = DB_Setting('dbasic.ar_callsign', false);

        if ($auto_reject === false) {
            return;
        }

        $use_direct_db = true;
        $poster = false;

        // Pick An Admin User For Comments
        if ($poster === false) {
            $adm_users = DB::table('role_user')->where('role_id', function ($query) {
                return $query->select('id')->from('roles')->where('name', 'admin')->limit(1);
            })->pluck('user_id');
            $poster = $adm_users->random();
        }

        // Get the pirep and aircraft
        $pirep = $event->pirep;
        $aircraft = $pirep->aircraft;

        $pirep_comments = [];
        $now = Carbon::now()->toDateTimeString();
        $default_fields = ['pirep_id' => $pirep->id, 'user_id' => $poster, 'created_at' => $now, 'updated_at' => $now];

        // Read Pirep Field Values
        if ($use_direct_db === true) {
            $network_presence = DB::table('pirep_field_values')->where(['pirep_id' => $pirep->id, 'slug' => 'network-presence'])->value('value');
            $network_callsign = DB::table('pirep_field_values')->where(['pirep_id' => $pirep->id, 'slug' => 'network-callsign'])->value('value');
        } else {
            $network_presence = optional($pirep->fields->where('slug', 'network-presence')->first())->value;
            $network_callsign = optional($pirep->fields->where('slug', 'network-callsign')->first())->value;
        }

        // Reject By Flight Time
        if ($margin_ftime > 0 && $pirep->flight_time < $margin_ftime) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: Flight Time Below VA Approval Criteria']);
            $pirep_state = PirepState::REJECTED;
        }

        // Reject By Score
        if ($margin_score > 0 && $pirep->score < $margin_score) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: Pirep Score Below VA Approval Criteria']);
            $pirep_state = PirepState::REJECTED;
        }

        // Reject By Landing Rate
        if ($margin_lrate != 0 && $pirep->landing_rate > $margin_lrate) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: Landing Rate Above VA Approval Criteria']);
            $pirep_state = PirepState::REJECTED;
        }

        // Reject By Network Presence Check (IVAO/VATSIM only)
        if ($reject_presence && isset($network_presence) && $network_presence < $margin_presence) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: Flights must be operated online! Network Presence below required minimums']);
            $pirep_state = PirepState::REJECTED;
            Log::debug('Disposable Basic | Pirep:' . $pirep->id . ' Rejected automatically by Presence. Check Result:' . $network_presence . '% Requirement:' . $margin_presence . '%');
        }

        // Reject By Network Callsign Check (IVAO/VATSIM only)
        if ($reject_callsign && isset($network_callsign) && $network_callsign < $margin_presence) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: Flights must be operated online with proper callsigns!']);
            $pirep_state = PirepState::REJECTED;
            Log::debug('Disposable Basic | Pirep:' . $pirep->id . ' Rejected automatically by Callsign. Check Result:' . $network_callsign . '% Requirement:' . $margin_presence . '%');
        }

        // Reject By Aircraft (A pirep with No Aircraft is rare but may happen, should be rejected)
        if (!$aircraft) {
            $pirep_comments[] = array_merge($default_fields, ['comment' => 'Reject Reason: No Aircraft Registration Provided']);
            $pirep_state = PirepState::REJECTED;
        }

        // Write Comments
        if (is_countable($pirep_comments) && count($pirep_comments) > 0) {
            DB::table('pirep_comments')->insert($pirep_comments);
        }

        // Write Pirep State (REJECT ONLY)
        if (isset($pirep_state)) {
            $pirep->state = $pirep_state;
            $pirep->save();
        }
    }
}

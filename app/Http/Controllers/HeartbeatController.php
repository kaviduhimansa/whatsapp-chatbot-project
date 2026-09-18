<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function ping(Request $request)
    {
        $user = $request->user();

        $user->last_seen_at = now();
        $user->save();

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
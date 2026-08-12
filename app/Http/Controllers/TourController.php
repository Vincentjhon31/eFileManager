<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marks the sidebar tour as seen.
 *
 * Not audited — finishing or skipping a walkthrough is a UI preference, not
 * an act on a record, and does not belong in a trail kept for RA 10173.
 */
class TourController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $request->user()->forceFill(['tour_completed_at' => now()])->saveQuietly();

        return response()->json(['status' => 'ok']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\QrCodeGenerator;
use App\Support\TrackingLink;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The printable A5 routing slip.
 *
 * This is the bridge between the paper the office already runs on and the
 * system, and it is probably the single most important screen in the project.
 * Nobody has to change how they work: the document still travels by hand, still
 * gets signed for in ink, still gets stapled to a folder. The only new step is
 * that somebody points a phone at a square on the page, and from then on the
 * system knows where the paper is.
 *
 * Rendered as HTML with print styles rather than a PDF. No PDF library to
 * install on a shared host, no fonts to embed, no rendering differences to
 * chase — and staff already know how their browser prints.
 */
class RoutingSlipController extends Controller
{
    public function show(Document $document, QrCodeGenerator $qr): View
    {
        Gate::authorize('view', $document);

        $document->load([
            'type', 'originDepartment', 'registeringDepartment', 'creator',
            'routes.fromDepartment', 'routes.toDepartment',
        ]);

        return view('print.routing-slip', [
            'document' => $document,
            'qr' => $qr->svg(TrackingLink::for($document), 300),

            // Enough blank lines that the slip stays useful for the next few
            // hand-offs without a reprint. A document that visits more offices
            // than this gets a fresh slip, which is also what happens on paper.
            'blankRows' => max(0, 6 - $document->routes->count()),
        ]);
    }
}

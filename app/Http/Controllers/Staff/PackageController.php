<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use App\Models\Visitor;
use App\Registration\Normalizer;
use App\Support\Audit;
use App\Support\PackageCode;
use App\Support\Settings;
use App\Visitors\PackageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Package hand-over (M9 CO-9), for every role. Staff reach a QR registration
 * only here, by its package code or its exact phone number, and see only a
 * minimal card: no phone, email, other fields or links. Not found, trashed,
 * deleted, or an exhibition without a package all read the same.
 */
class PackageController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PackageService $packages,
    ) {}

    /** The search screen for the exhibition chosen for Save & New (or the chooser). */
    public function index(Request $request): View|Response
    {
        if ($request->filled('exhibition') && ($chosen = Exhibition::query()->find((int) $request->query('exhibition')))) {
            $request->session()->put(EntryController::SESSION_KEY, $chosen->id);
        }
        $exhibition = $request->boolean('change') ? null : $this->sessionExhibition($request);
        if ($exhibition === null) {
            return response()->view('staff.packages.choose', ['exhibitions' => Exhibition::query()->orderByDesc('starts_on')->orderByDesc('id')->get()]);
        }

        return view('staff.packages.index', ['exhibition' => $exhibition]);
    }

    /** The code form: a typed code goes to the same page the QR opens. */
    public function find(Request $request): RedirectResponse
    {
        $code = PackageCode::normalize((string) $request->query('code', ''));
        if ($code === null) {
            return redirect()->route('packages.index')->withInput()->withErrors(['code' => 'A package code has 6 letters and digits.']);
        }

        return redirect()->route('packages.show', $code);
    }

    /** The QR target: one card for the code, at any exhibition. */
    public function show(Request $request, string $code): View
    {
        $normalised = PackageCode::normalize($code);
        $visitor = $normalised === null ? null : $this->handOverable(Visitor::query()->where('package_code', $normalised))->first();
        $this->logLookup('code', $visitor !== null ? 'found' : 'not_found', $visitor);

        return view('staff.packages.result', [
            'exhibition' => $visitor?->exhibition ?? $this->sessionExhibition($request),
            'visitors' => $visitor ? new Collection([$visitor]) : new Collection,
            'searched' => 'code',
        ]);
    }

    /** "No code? Phone number": an exact match within the session's exhibition. */
    public function phone(Request $request): View|RedirectResponse
    {
        $exhibition = $this->sessionExhibition($request);
        if ($exhibition === null) {
            return redirect()->route('packages.index');
        }
        $typed = trim((string) $request->query('phone', ''));
        $key = Normalizer::phoneKeyFor($typed, (string) $this->settings->get('default_country'));
        // Partial numbers find nothing: the key must have at least 7 digits and match exactly.
        $visitors = strlen(preg_replace('/\D/', '', $key) ?? '') < 7 || ! $exhibition->offers_package
            ? new Collection
            : $this->handOverable(Visitor::query()->where('exhibition_id', $exhibition->id)->where('phone_key', $key))->orderBy('created_at')->get();
        $this->logLookup('phone', $visitors->isNotEmpty() ? 'found' : 'not_found', $visitors->count() === 1 ? $visitors->first() : null, $visitors->count());

        return view('staff.packages.result', ['exhibition' => $exhibition, 'visitors' => $visitors, 'searched' => 'phone']);
    }

    public function give(Request $request, string $code): RedirectResponse
    {
        $visitor = $this->visitorForCode($code);
        $this->packages->give($visitor->id, $request->user());

        return back(fallback: route('packages.show', $visitor->package_code))->with('status', 'Package marked as received.');
    }

    public function undo(Request $request, string $code): RedirectResponse
    {
        $visitor = $this->visitorForCode($code);
        $this->packages->undo($visitor->id, $request->user());

        return back(fallback: route('packages.show', $visitor->package_code))->with('status', 'Package marked as not received.');
    }

    /** Current visitors at an exhibition that offers a package. */
    private function handOverable($query)
    {
        return $query->current()->with('exhibition', 'packageReceivedBy')
            ->whereHas('exhibition', fn ($exhibition) => $exhibition->where('offers_package', true));
    }

    private function visitorForCode(string $code): Visitor
    {
        $normalised = PackageCode::normalize($code);
        $visitor = $normalised === null ? null : $this->handOverable(Visitor::query()->where('package_code', $normalised))->first();
        abort_if($visitor === null, 404);

        return $visitor;
    }

    private function sessionExhibition(Request $request): ?Exhibition
    {
        $id = $request->session()->get(EntryController::SESSION_KEY);

        return $id ? Exhibition::query()->find($id) : null;
    }

    /** Never the typed number or code (M9 §3a.5): the method, the outcome and, when found, the visitor. */
    private function logLookup(string $method, string $outcome, ?Visitor $visitor, ?int $matches = null): void
    {
        Audit::record('package.lookup', $visitor, $outcome, array_filter(['method' => $method, 'matches' => $matches], fn ($v) => $v !== null));
    }
}

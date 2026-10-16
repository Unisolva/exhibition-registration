<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use App\Support\Audit;
use App\Support\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Work order §7 "Exhibitions" (super admin and admin). The public token is
 * set once at creation; renaming never changes the QR code.
 */
class ExhibitionController extends Controller
{
    public function index(): View
    {
        $exhibitions = Exhibition::query()
            ->withCount(['visitors' => fn ($query) => $query->whereNull('trashed_at')->whereNull('deleted_at')])
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->get();

        return view('staff.exhibitions.index', ['exhibitions' => $exhibitions]);
    }

    public function create(): View
    {
        return view('staff.exhibitions.form', ['exhibition' => new Exhibition]);
    }

    public function store(Request $request): RedirectResponse
    {
        $exhibition = new Exhibition($this->validated($request));
        $exhibition->created_by = $request->user()->id;
        $exhibition->save();

        Audit::record('exhibition_created', $exhibition);

        return redirect()->route('exhibitions.show', $exhibition)->with('status', 'Exhibition created. Its registration page is open.');
    }

    public function show(Exhibition $exhibition): View
    {
        return view('staff.exhibitions.show', [
            'exhibition' => $exhibition,
            'qr' => QrCode::svg($exhibition->publicUrl()),
        ]);
    }

    public function edit(Exhibition $exhibition): View
    {
        return view('staff.exhibitions.form', ['exhibition' => $exhibition]);
    }

    public function update(Request $request, Exhibition $exhibition): RedirectResponse
    {
        $exhibition->fill($this->validated($request));
        $changed = array_keys($exhibition->getDirty());
        $exhibition->save();

        if ($changed !== []) {
            Audit::record('exhibition_updated', $exhibition, meta: ['fields' => $changed]);
        }

        return redirect()->route('exhibitions.show', $exhibition)->with('status', 'Exhibition saved.');
    }

    public function open(Exhibition $exhibition): RedirectResponse
    {
        return $this->setOpen($exhibition, true);
    }

    public function close(Exhibition $exhibition): RedirectResponse
    {
        return $this->setOpen($exhibition, false);
    }

    public function qr(Exhibition $exhibition): Response
    {
        $filename = 'qr-'.Str::slug($exhibition->name ?: 'exhibition').'-'.$exhibition->public_token.'.svg';

        return response(QrCode::svg($exhibition->publicUrl()), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function poster(Exhibition $exhibition): View
    {
        return view('staff.exhibitions.poster', [
            'exhibition' => $exhibition,
            'qr' => QrCode::svg($exhibition->publicUrl()),
        ]);
    }

    private function setOpen(Exhibition $exhibition, bool $open): RedirectResponse
    {
        if ($exhibition->is_open !== $open) {
            $exhibition->is_open = $open;
            $exhibition->save();
            Audit::record($open ? 'exhibition_opened' : 'exhibition_closed', $exhibition);
        }

        return redirect()->route('exhibitions.show', $exhibition)
            ->with('status', $open ? 'Registration is open.' : 'Registration is closed. Staff can still enter visitors.');
    }

    /**
     * @return array{name: string, starts_on: string, ends_on: string|null, default_locale: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'default_locale' => ['required', Rule::in(Exhibition::LOCALES)],
        ], ['default_locale.*' => 'Choose Arabic or English.']);

        return [
            'name' => trim($data['name']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'default_locale' => $data['default_locale'],
        ];
    }
}

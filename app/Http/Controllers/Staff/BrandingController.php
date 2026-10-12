<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Branding;
use App\Support\LogoValidator;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Branding settings (super admin, work order §1a): names, logo, colour, the
 * "Powered by" link and the privacy link.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(Branding $branding): View
    {
        return view('staff.settings.branding', [
            'settings' => $this->settings,
            'logoUrl' => $branding->logoUrl(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'org_name_en' => ['nullable', 'string', 'max:100'],
            'org_name_ar' => ['nullable', 'string', 'max:100'],
            'brand_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'privacy_url' => ['nullable', 'string', 'max:255', 'url:https'],
            'logo' => ['nullable', 'file'],
        ]);

        $contrast = Branding::contrastWithWhite($data['brand_color']);
        if ($contrast < Branding::MIN_CONTRAST) {
            throw ValidationException::withMessages([
                'brand_color' => sprintf('White text on this colour has a contrast of %.1f:1; it needs at least 4.5:1. Choose a darker colour.', $contrast),
            ]);
        }

        $values = [
            'org_name_en' => trim((string) ($data['org_name_en'] ?? '')),
            'org_name_ar' => trim((string) ($data['org_name_ar'] ?? '')),
            'brand_color' => strtoupper($data['brand_color']),
            'privacy_url' => ($data['privacy_url'] ?? null) ?: null,
            'show_powered_by' => $request->boolean('show_powered_by'),
        ];

        if ($request->hasFile('logo')) {
            $bytes = (string) file_get_contents($request->file('logo')->getRealPath());
            $result = LogoValidator::check($bytes);
            if (is_string($result)) {
                throw ValidationException::withMessages(['logo' => $result]);
            }
            $values['logo'] = ['mime' => $result['mime'], 'data' => base64_encode($bytes)];
            $values['logo_hash'] = substr(hash('sha256', $bytes), 0, 16);
        } elseif ($request->boolean('remove_logo')) {
            $values['logo'] = null;
            $values['logo_hash'] = null;
        }

        $changed = [];
        foreach ($values as $key => $value) {
            if ($this->settings->get($key) !== $value) {
                $this->settings->set($key, $value, $request->user()->id);
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            Audit::record('settings_updated', meta: ['keys' => array_values(array_diff($changed, ['logo_hash']))]);
        }

        return redirect()->route('settings.branding')->with('status', 'Branding saved.');
    }
}

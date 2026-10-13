<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Settings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * General settings (super admin, work order §7 "Settings"): the public
 * registration kill switch, default country, display time zone and
 * retention. ERP settings live with the Odoo connections.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(): View
    {
        return view('staff.settings.general', [
            'settings' => $this->settings,
            'countries' => $this->countries(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_country' => ['required', Rule::in($this->countries())],
            'display_timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'retention_months' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $values = [
            'public_registration_enabled' => $request->boolean('public_registration_enabled'),
            'default_country' => $data['default_country'],
            'display_timezone' => $data['display_timezone'],
            'retention_months' => (int) $data['retention_months'],
        ];

        $changed = [];
        foreach ($values as $key => $value) {
            if ($this->settings->get($key) !== $value) {
                $this->settings->set($key, $value, $request->user()->id);
                $changed[] = $key;
            }
        }
        if ($changed !== []) {
            Audit::record('settings_updated', meta: ['keys' => $changed]);
        }

        return redirect()->route('settings.general')->with('status', 'Settings saved.');
    }

    /**
     * Countries that have a region data file loaded.
     *
     * @return list<string>
     */
    private function countries(): array
    {
        return DB::table('regions')->distinct()->orderBy('country_code')->pluck('country_code')->all();
    }
}

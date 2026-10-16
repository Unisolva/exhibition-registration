<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Erp\ConnectionManager;
use App\Erp\ConnectorFactory;
use App\Erp\PushService;
use App\Http\Controllers\Controller;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Odoo connection screen (super admin, work order §7): version selector,
 * Test and Live blocks with a radio button, push switch, lead settings.
 */
class ErpController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionManager $connections,
    ) {}

    public function show(): View
    {
        $active = ErpConnection::active();

        return view('staff.erp.show', [
            'settings' => $this->settings,
            'versions' => ConnectorFactory::versions(),
            'blocks' => ['test' => ErpConnection::current('test'), 'live' => ErpConnection::current('live')],
            'active' => $active,
            'unfinished' => $active ? ErpPushItem::query()->where('connection_id', $active->id)
                ->whereIn('state', ErpPushItem::UNFINISHED)->with('visitor:id,name')->orderBy('id')->limit(100)->get() : collect(),
            'report' => session('connection_report'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'erp_mode' => ['required', Rule::in(['none', 'odoo'])],
            'odoo_version' => ['required', Rule::in(ConnectorFactory::versions())],
            'lead_name_template' => ['required', 'string', 'max:200'],
            'odoo_campaign_id' => ['nullable', 'integer', 'min:1'],
            'odoo_source_id' => ['nullable', 'integer', 'min:1'],
            'odoo_tag_ids' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
        ]);
        $user = $request->user();

        $this->connections->setMode($data['erp_mode'], $user);
        $this->connections->setVersion($data['odoo_version'], $user);

        $values = [
            'lead_name_template' => trim($data['lead_name_template']),
            'odoo_campaign_id' => isset($data['odoo_campaign_id']) ? (int) $data['odoo_campaign_id'] : null,
            'odoo_source_id' => isset($data['odoo_source_id']) ? (int) $data['odoo_source_id'] : null,
            'odoo_tag_ids' => array_values(array_map('intval', array_filter(preg_split('/\s*,\s*/', trim((string) ($data['odoo_tag_ids'] ?? ''))) ?: []))),
        ];
        $changed = [];
        foreach ($values as $key => $value) {
            if ($this->settings->get($key) !== $value) {
                $this->settings->set($key, $value, $user->id);
                $changed[] = $key;
            }
        }
        if ($changed !== []) {
            Audit::record('settings_updated', meta: ['keys' => $changed]);
        }

        return redirect()->route('erp.show')->with('status', 'ERP settings saved.');
    }

    public function saveBlock(Request $request, string $kind): RedirectResponse
    {
        // Errors go to the block's own bag, so they show inline in that block only (M8 CO-9).
        try {
            $data = $request->validate([
                'base_url' => ['required', 'string', 'max:255'],
                'database' => ['required', 'string', 'max:100'],
                'login' => ['required', 'string', 'max:254'],
                'api_key' => ['nullable', 'string', 'max:255'],
            ]);
            $this->connections->saveBlock($kind, $data, $request->user());
        } catch (ValidationException $e) {
            throw $e->errorBag('block_'.$kind);
        }

        return redirect()->route('erp.show')->with('status', ucfirst($kind).' connection saved. Run a connection test before using it.');
    }

    public function test(Request $request, string $kind): RedirectResponse
    {
        $connection = ErpConnection::current($kind);
        abort_if($connection === null, 404);

        $report = $this->connections->test($connection, $request->user());

        return redirect()->route('erp.show')->with('connection_report', ['kind' => $kind, 'ok' => $report->ok, 'message' => $report->message]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['test', 'live'])]]);
        $this->connections->activate($data['kind'], $request->user());

        return redirect()->route('erp.show')->with('status', strtoupper($data['kind']).' is now the active connection.');
    }

    public function push(Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        $this->connections->setPushEnabled($on, $request->user());

        return redirect()->route('erp.show')->with('status', $on ? 'Pushing is on.' : 'Pushing is off.');
    }

    public function cancelWaiting(Request $request, PushService $push): RedirectResponse
    {
        $active = ErpConnection::active();
        abort_if($active === null, 404);

        $ids = ErpPushItem::query()->where('connection_id', $active->id)->whereIn('state', ['queued', 'failed_retry'])->pluck('id')->all();
        $count = $push->cancel($ids, $request->user());

        return redirect()->route('erp.show')->with('status', "{$count} waiting item(s) cancelled.");
    }
}

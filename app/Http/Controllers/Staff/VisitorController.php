<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Region;
use App\Models\Visitor;
use App\Registration\FormView;
use App\Support\Settings;
use App\Visitors\VisitorLock;
use App\Visitors\VisitorQuery;
use App\Visitors\VisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Work order §7 "Visitor list" and "Edit and delete". Staff see and edit only
 * their own entries and can never delete; the server enforces every rule the
 * screen hides.
 */
class VisitorController extends Controller
{
    public function __construct(
        private readonly VisitorQuery $visitorQuery,
        private readonly VisitorService $visitors,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $this->visitorQuery->filters($request);
        $page = $this->visitorQuery->build($user, $filters)->cursorPaginate(50)->withQueryString();
        $ids = collect($page->items())->pluck('id')->all();

        return view('staff.visitors.index', [
            'page' => $page,
            'filters' => $filters,
            'locks' => VisitorLock::causesFor($ids),
            'exhibitions' => Exhibition::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'regions' => Region::forCountry((string) $this->settings->get('default_country')),
            'occupation' => FieldDefinition::query()->where('key', 'occupation')->first(),
            'timezone' => (string) $this->settings->get('display_timezone'),
            'canPush' => Gate::allows('registrations.push') && $this->settings->get('erp_mode') === 'odoo',
        ]);
    }

    public function show(Request $request, Visitor $visitor): View
    {
        $this->ensureCurrent($visitor);
        Gate::authorize('view', $visitor);

        $edits = DB::table('visitor_edits')
            ->leftJoin('users', 'users.id', '=', 'visitor_edits.user_id')
            ->where('visitor_id', $visitor->id)
            ->orderByDesc('visitor_edits.at')->orderByDesc('visitor_edits.id')
            ->get(['visitor_edits.at', 'visitor_edits.changes', 'users.name as user_name']);

        return view('staff.visitors.show', [
            'visitor' => $visitor->load('exhibition', 'enteredBy'),
            'fields' => FieldDefinition::query()->orderBy('sort_order')->orderBy('id')->get(),
            'edits' => $edits,
            'lock' => VisitorLock::causeFor($visitor->id),
            'timezone' => (string) $this->settings->get('display_timezone'),
            // Staff see the duplicate flag on their own entries but never the matching record.
            'showDuplicateLink' => $request->user()->isManager(),
        ]);
    }

    public function edit(Visitor $visitor, FormView $formView): View
    {
        $this->ensureCurrent($visitor);
        Gate::authorize('update', $visitor);

        $bag = session('errors')?->getBag('default');

        return view('staff.visitors.edit', [
            'visitor' => $visitor,
            'fields' => $formView->fields('en'),
            'old' => old() ?: $this->currentValues($visitor),
            'fieldErrors' => $bag ? array_map(fn (array $messages) => $messages[0], $bag->messages()) : [],
            'lock' => VisitorLock::causeFor($visitor->id),
        ]);
    }

    public function update(Request $request, Visitor $visitor): RedirectResponse|JsonResponse
    {
        $this->ensureCurrent($visitor);
        Gate::authorize('update', $visitor);

        $changes = $this->visitors->edit($visitor->id, $request->all(), $request->user());

        if ($request->expectsJson()) {
            return response()->json(['status' => 'saved', 'changed' => array_keys($changes)]);
        }

        return redirect()->route('visitors.show', $visitor)
            ->with('status', $changes === [] ? 'Nothing changed.' : 'Saved. The change is in the edit log.');
    }

    public function confirmTrash(Visitor $visitor): View
    {
        $this->ensureCurrent($visitor);
        Gate::authorize('trash', $visitor);

        return view('staff.visitors.trash-confirm', ['visitor' => $visitor, 'lock' => VisitorLock::causeFor($visitor->id)]);
    }

    public function destroy(Request $request, Visitor $visitor): RedirectResponse|JsonResponse
    {
        $this->ensureCurrent($visitor);
        Gate::authorize('trash', $visitor);

        $this->visitors->trash($visitor->id, $request->user());

        return $request->expectsJson()
            ? response()->json(['status' => 'trashed'])
            : redirect()->route('visitors.index')->with('status', 'Moved to the trash. It can be restored for 30 days.');
    }

    /**
     * @return array<string, string>
     */
    private function currentValues(Visitor $visitor): array
    {
        $values = [
            'name' => $visitor->name,
            'phone' => $visitor->phone_raw,
            'email' => (string) $visitor->email,
            'occupation' => (string) $visitor->occupation,
            'governorate' => (string) $visitor->region_code,
        ];
        foreach ($visitor->extra ?? [] as $key => $value) {
            $values[$key] = (string) $value;
        }

        return $values;
    }

    /** Trashed visitors appear only on the trash screen; deleted ones nowhere. */
    private function ensureCurrent(Visitor $visitor): void
    {
        if ($visitor->trashed_at !== null || $visitor->deleted_at !== null) {
            throw new NotFoundHttpException;
        }
    }
}

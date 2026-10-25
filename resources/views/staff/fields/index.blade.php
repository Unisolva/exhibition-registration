{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Form fields')

@section('content')
<x-page-header title="Form fields">
  <a class="btn btn-primary" href="{{ route('fields.create') }}"><x-icon name="plus" /> Add a field</a>
</x-page-header>
<p>The fields of the public form and of Save &amp; New, in this order. Fields are never deleted: deactivate one to hide it and keep what was stored. Custom fields active: {{ $activeCustom }} of {{ \App\Models\FieldDefinition::MAX_ACTIVE_CUSTOM }}.</p>
<div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
  <thead><tr><th>Order</th><th>Label (English / Arabic)</th><th>Key</th><th>Type</th><th>Status</th><th><span class="hint">Actions</span></th></tr></thead>
  <tbody>
  @foreach ($fields as $field)
    <tr>
      <td data-label="Order">{{ $field->sort_order }}</td>
      <td data-label="Label">{{ $field->label_en }}@if ($field->label_ar !== '') / <span lang="ar" dir="rtl">{{ $field->label_ar }}</span>@endif</td>
      <td data-label="Key"><code>{{ $field->key }}</code>@if ($field->is_system) <span class="badge bg-blue-lt">system</span>@endif</td>
      <td data-label="Type">{{ $field->type }}@if ($field->type === 'select') ({{ count($field->activeOptions()) ?: 'free text' }}{{ count($field->activeOptions()) ? ' options' : '' }})@endif @if ($field->isRadio())<span class="badge bg-blue-lt">Radio</span>@endif</td>
      <td data-label="Status">
        @if ($field->is_active)<span class="badge bg-success-lt">Active</span>@else<span class="badge bg-secondary-lt">Inactive</span>@endif
        @if ($field->isStaffOnly())<span class="badge bg-orange-lt badge-staff">Staff only</span>@endif
        @if ($field->required())<span class="badge bg-blue-lt">Required</span>@endif
        @unless ($field->send_to_erp)<span class="badge bg-secondary-lt">Not sent to ERP</span>@endunless
      </td>
      <td><a href="{{ route('fields.edit', $field) }}">Edit</a></td>
    </tr>
  @endforeach
  </tbody>
</table></div></div>
@endsection

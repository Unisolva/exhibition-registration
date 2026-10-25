{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Packages')

@section('content')
@include('staff.packages.bar')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Packages</h1></div>
  <p>Scan the code on the visitor's thank-you screen with your phone camera. It opens their package directly.</p>
  @unless ($exhibition->offers_package)
    <div class="alert alert-warning notice notice-warn" role="status">{{ $exhibition->name }} does not offer a welcome package.</div>
  @endunless

  <form method="get" action="{{ route('packages.find') }}" class="card card-body spaced" role="search">
    <x-field name="code" label="Package code" :value="old('code')" maxlength="10" autocomplete="off" autocapitalize="characters" spellcheck="false" dir="ltr"
             hint="6 letters and digits, as on the visitor's screen." />
    <div class="actions"><button type="submit" class="btn btn-primary">Find</button></div>
  </form>

  <form method="get" action="{{ route('packages.phone') }}" class="card card-body spaced" role="search">
    <x-field type="tel" name="phone" label="No code? Phone number" maxlength="40" inputmode="tel" dir="ltr" autocomplete="off"
             :hint="'Searches '.$exhibition->name.' only. The whole number, as the visitor registered it.'" />
    <div class="actions"><button type="submit" class="btn btn-outline-primary">Search</button></div>
  </form>
</div>
@endsection

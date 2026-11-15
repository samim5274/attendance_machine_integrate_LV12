
@extends('admin.layouts.master')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0">Edit Employee PIN Mapping</h4>
        </div>

        <div class="card-body">
            <form method="POST"
                  action="{{ route('admin.employee-device-mappings.update', $mapping->id) }}">
                @csrf
                @method('PUT')

                @include('admin.employee-device-mappings.form')
            </form>
        </div>
    </div>
</div>
@endsection

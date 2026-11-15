
@extends('admin.layouts.master')

@section('content')
<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Employee PIN Mappings</h4>

        <a href="{{ route('admin.employee-device-mappings.create') }}"
           class="btn btn-primary">
            + Add Mapping
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="card">
        <div class="card-body table-responsive">

            <table class="table table-bordered table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Device</th>
                        <th>Machine PIN</th>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Status</th>
                        <th width="160">Action</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($mappings as $mapping)
                        <tr>
                            <td>{{ $mappings->firstItem() + $loop->index }}</td>

                            <td>
                                {{ $mapping->device->name ?? 'Device #' . $mapping->device_id }}
                            </td>

                            <td>
                                <strong>{{ $mapping->device_user_id }}</strong>
                            </td>

                            <td>
                                {{ $mapping->employee->employee_id ?? '-' }}
                            </td>

                            <td>
                                {{ $mapping->employee->name ?? 'Employee not found' }}
                            </td>

                            <td>
                                @if($mapping->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>

                            <td>
                                <a href="{{ route('admin.employee-device-mappings.edit', $mapping->id) }}"
                                   class="btn btn-sm btn-warning">
                                    Edit
                                </a>

                                <form method="POST"
                                      action="{{ route('admin.employee-device-mappings.destroy', $mapping->id) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Are you sure you want to delete this mapping?')">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-danger">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center">
                                No employee mappings found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            {{ $mappings->links() }}

        </div>
    </div>
</div>
@endsection

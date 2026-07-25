<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Models\OfficeMembership;
use App\Models\WorkDepartment;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkDepartmentController extends Controller
{
    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('viewAny', WorkDepartment::class);
        RejectClientOfficeId::strip($request);

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $q = WorkDepartment::query()->where('office_id', $currentOffice->id())->orderBy('name');

        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (WorkDepartment $d) => $this->public($d)),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function store(Request $request, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        $this->authorize('create', WorkDepartment::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('work_departments', 'name')->where('office_id', $currentOffice->id())],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('work_departments', 'code')->where('office_id', $currentOffice->id())],
            'color' => ['nullable', 'string', 'max:16', 'regex:/^#?[0-9A-Fa-f]{3,8}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $dept = WorkDepartment::query()->create([
            'office_id' => $currentOffice->id(),
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'color' => $data['color'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $audit->record('work.department.create', 'SUCCESS', $dept);

        return response()->json(['data' => $this->public($dept)], 201);
    }

    public function update(Request $request, WorkDepartment $department, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $department);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('work_departments', 'name')->where('office_id', $currentOffice->id())->ignore($department->id)],
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('work_departments', 'code')->where('office_id', $currentOffice->id())->ignore($department->id)],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $department->fill($data)->save();
        $audit->record('work.department.update', 'SUCCESS', $department, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->public($department)]);
    }

    public function assignMembership(Request $request, WorkDepartment $department, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $department);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'membership_id' => ['required', 'integer'],
        ]);

        $membership = OfficeMembership::query()
            ->where('id', $data['membership_id'])
            ->where('office_id', $currentOffice->id())
            ->where('is_active', true)
            ->firstOrFail();

        if (! $department->is_active) {
            return response()->json(['message' => 'Departamento inativo.'], 422);
        }

        $membership->forceFill(['work_department_id' => $department->id])->save();
        $audit->record('work.department.assign_membership', 'SUCCESS', $department, [
            'membership_id' => $membership->id,
        ]);

        return response()->json([
            'data' => [
                'membership_id' => $membership->id,
                'work_department_id' => $department->id,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function public(WorkDepartment $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'color' => $d->color,
            'is_active' => $d->is_active,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

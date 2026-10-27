<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCamp;
use App\Models\ReliefWarehouse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Peninjauan pendaftaran akun petugas oleh operator BPBD. */
class RegistrationReviewController extends Controller
{
    /** Peran yang boleh diberikan. Hanya super admin yang boleh mengangkat operator BPBD. */
    private function grantable(User $reviewer): array
    {
        $roles = [Role::FieldVolunteer, Role::CampCoordinator, Role::WarehouseManager];

        if ($reviewer->hasRole(Role::SuperAdmin->value)) {
            $roles[] = Role::BpbdOperator;
        }

        return $roles;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('user.review');

        $present = fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone_number' => $u->phone_number,
            'requested_role' => $u->requested_role,
            'organization' => $u->organization,
            'request_note' => $u->request_note,
            'status' => $u->account_status,
            'registered_at' => $u->created_at?->toIso8601String(),
            'reviewed_at' => $u->reviewed_at?->toIso8601String(),
            'reject_reason' => $u->reject_reason,
        ];

        return response()->json([
            'pending' => User::where('account_status', User::PENDING)->orderBy('created_at')->get()->map($present)->values(),
            'recent' => User::whereIn('account_status', [User::APPROVED, User::REJECTED])->whereNotNull('reviewed_at')
                ->orderByDesc('reviewed_at')->limit(15)->get()->map($present)->values(),
            'roles' => collect($this->grantable($request->user()))->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'camps' => EvacuationCamp::query()->orderBy('name')->get(['id', 'code', 'name', 'coordinator_id'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'has_coordinator' => $c->coordinator_id !== null])->values(),
            'warehouses' => ReliefWarehouse::query()->orderBy('name')->get(['id', 'code', 'name', 'manager_id'])
                ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'has_manager' => $w->manager_id !== null])->values(),
        ]);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.review');

        $roles = collect($this->grantable($request->user()))->map(fn (Role $r) => $r->value)->all();

        $data = $request->validate([
            'role' => ['required', Rule::in($roles)],
            'camp_id' => ['nullable', 'uuid', 'exists:evacuation_camps,id'],
            'warehouse_id' => ['nullable', 'uuid', 'exists:relief_warehouses,id'],
        ]);

        // Peran yang bertumpu pada satu tempat wajib menyebut tempatnya; akun tanpa itu tidak bisa melakukan apa-apa.
        $needs = ['field-volunteer' => 'camp_id', 'camp-coordinator' => 'camp_id', 'warehouse-manager' => 'warehouse_id'][$data['role']] ?? null;

        if ($needs && blank($data[$needs] ?? null)) {
            throw ValidationException::withMessages([$needs => $needs === 'camp_id' ? 'Pilih posko tempat petugas ini bertugas.' : 'Pilih gudang yang dikelola.']);
        }

        DB::transaction(function () use ($request, $user, $data) {
            // Dikunci: dua operator yang menyetujui orang yang sama atau merebut posko yang sama tidak boleh sama-sama berhasil.
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->account_status !== User::PENDING) {
                throw ValidationException::withMessages(['role' => 'Pendaftaran ini sudah ditinjau.']);
            }

            if ($data['role'] === Role::CampCoordinator->value) {
                $camp = EvacuationCamp::whereKey($data['camp_id'])->lockForUpdate()->firstOrFail();

                if ($camp->coordinator_id !== null) {
                    throw ValidationException::withMessages(['camp_id' => 'Posko ini sudah punya koordinator.']);
                }

                $camp->update(['coordinator_id' => $locked->id]);
            }

            if ($data['role'] === Role::FieldVolunteer->value) {
                EvacuationCamp::findOrFail($data['camp_id'])->volunteers()->syncWithoutDetaching([$locked->id]);
            }

            if ($data['role'] === Role::WarehouseManager->value) {
                $warehouse = ReliefWarehouse::whereKey($data['warehouse_id'])->lockForUpdate()->firstOrFail();

                if ($warehouse->manager_id !== null) {
                    throw ValidationException::withMessages(['warehouse_id' => 'Gudang ini sudah punya pengelola.']);
                }

                $warehouse->update(['manager_id' => $locked->id]);
            }

            $locked->forceFill([
                'account_status' => User::APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'reject_reason' => null,
            ])->save();

            $locked->syncRoles([$data['role']]);
        });

        Log::info('Pendaftaran petugas disetujui', ['user' => $user->id, 'role' => $data['role'], 'by' => $request->user()->id]);

        return response()->json(['status' => User::APPROVED]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.review');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $updated = User::whereKey($user->id)->where('account_status', User::PENDING)->update([
            'account_status' => User::REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'reject_reason' => $data['reason'],
        ]);

        if (! $updated) {
            throw ValidationException::withMessages(['reason' => 'Pendaftaran ini sudah ditinjau.']);
        }

        Log::info('Pendaftaran petugas ditolak', ['user' => $user->id, 'by' => $request->user()->id]);

        return response()->json(['status' => User::REJECTED]);
    }
}

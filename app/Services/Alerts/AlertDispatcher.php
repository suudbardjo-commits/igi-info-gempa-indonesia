<?php

namespace App\Services\Alerts;

use App\Events\CampThreatenedEvent;
use App\Events\LogisticsAlertEvent;
use App\Jobs\SendEmergencyAlertNotificationJob;
use App\Models\AlertDelivery;
use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Memutuskan siapa diberi tahu saat ada gempa baru, dan lewat apa:
 *  - tiap posko dalam radius dampak: siaran WebSocket ke channel private posko itu, dan Telegram ke koordinatornya;
 *  - konsol logistik: ringkasan dampak lewat WebSocket;
 *  - operator BPBD: Telegram untuk gempa besar atau berpotensi tsunami, di mana pun lokasinya.
 *
 * Idempoten: satu orang paling banyak sekali per gempa dan kanal (tabel alert_deliveries), dan siaran
 * WebSocket dijaga supaya tidak terkirim ulang bila job dijalankan lagi.
 */
class AlertDispatcher
{
    public function __construct(private AlertMessageBuilder $messages, private TelegramClient $telegram) {}

    /**
     * @param  Collection<int, object{id: string, name: string, code: string, distance_km: string|float}>  $camps  hasil ImpactAssessmentService
     */
    public function dispatch(DisasterEvent $disaster, Collection $camps): void
    {
        // Data riwayat dan laporan duplikat dari sumber lain tidak boleh memicu peringatan.
        if (! $disaster->isFresh() || $disaster->canonical_event_id !== null) {
            return;
        }

        $payload = $this->disasterPayload($disaster);
        $coordinators = $this->coordinators($camps);

        foreach ($camps as $camp) {
            $distance = (float) $camp->distance_km;

            if (Cache::add("alert:camp-broadcast:{$disaster->id}:{$camp->id}", 1, now()->addHours(6))) {
                CampThreatenedEvent::dispatch($camp->id, $payload, ['id' => $camp->id, 'name' => $camp->name, 'code' => $camp->code], $distance);
            }

            if ($coordinator = $coordinators->get($camp->id)) {
                $this->telegram($disaster, $coordinator, 'camp_coordinator', $this->messages->forCamp($disaster, $camp->name, $distance));
            }
        }

        if ($camps->isNotEmpty() && Cache::add("alert:logistics-broadcast:{$disaster->id}", 1, now()->addHours(6))) {
            LogisticsAlertEvent::dispatch($payload, $camps->take(10)->map(fn ($c) => [
                'name' => $c->name, 'code' => $c->code, 'distance_km' => (float) $c->distance_km,
            ])->values()->all());
        }

        $this->notifyOperators($disaster, $camps);
    }

    private function notifyOperators(DisasterEvent $disaster, Collection $camps): void
    {
        $big = (float) $disaster->magnitude >= (float) config('disaster.alerts.operator_min_magnitude');
        $tsunami = $disaster->tsunami_potential
            && preg_match('/tsunami/i', $disaster->tsunami_potential)
            && ! preg_match('/tidak/i', $disaster->tsunami_potential);

        if (! $big && ! $tsunami) {
            return;
        }

        try {
            $operators = User::permission('evacuation.instruct')->get();
        } catch (PermissionDoesNotExist) {
            // Peran belum diseed (mis. lingkungan baru): tidak ada operator yang bisa diberi tahu.
            return;
        }

        $html = $this->messages->forOperator($disaster, $camps);

        foreach ($operators as $operator) {
            $this->telegram($disaster, $operator, 'operator', $html);
        }
    }

    /** Catat pengiriman (sekali per orang per gempa) lalu antrekan job kirimnya. */
    private function telegram(DisasterEvent $disaster, User $user, string $reason, string $html): void
    {
        $linked = DB::table('telegram_links')->where('user_id', $user->id)->whereNotNull('chat_id')->exists();

        // insertOrIgnore + kunci unik: dua job yang berjalan bersamaan tidak bisa sama-sama "memenangkan" pengiriman.
        $delivery = new AlertDelivery([
            'disaster_event_id' => $disaster->id,
            'user_id' => $user->id,
            'channel' => 'telegram',
            'reason' => $reason,
            'status' => $linked && $this->telegram->enabled() ? 'pending' : 'skipped',
            'error' => ! $this->telegram->enabled() ? 'Bot Telegram nonaktif.' : (! $linked ? 'Telegram belum ditautkan.' : null),
        ]);
        $delivery->id = (string) \Illuminate\Support\Str::uuid7();

        $inserted = DB::table('alert_deliveries')->insertOrIgnore($delivery->getAttributes() + ['created_at' => now(), 'updated_at' => now()]);

        if ($inserted && $delivery->status === 'pending') {
            SendEmergencyAlertNotificationJob::dispatch($delivery->id, $html);
        }
    }

    /** @return Collection<string, User> koordinator per ID posko */
    private function coordinators(Collection $camps): Collection
    {
        $map = EvacuationCamp::whereIn('id', $camps->pluck('id'))->whereNotNull('coordinator_id')->pluck('coordinator_id', 'id');
        $users = User::whereIn('id', $map->values())->get()->keyBy('id');

        return $map->map(fn ($userId) => $users->get($userId))->filter();
    }

    /** @return array<string, mixed> */
    private function disasterPayload(DisasterEvent $d): array
    {
        $point = DisasterEvent::query()->withCoordinates()->whereKey($d->id)->first();

        return [
            'id' => $d->id,
            'source' => $d->source->value,
            'magnitude' => $d->magnitude,
            'depth_km' => $d->depth_km,
            'location' => $d->location_name,
            'tsunami_potential' => $d->tsunami_potential,
            'impact_radius_km' => $d->impact_radius_km,
            'occurred_at' => $d->occurred_at->toIso8601String(),
            'latitude' => $point ? (float) $point->latitude : null,
            'longitude' => $point ? (float) $point->longitude : null,
        ];
    }
}

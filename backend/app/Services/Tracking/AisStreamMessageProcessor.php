<?php

namespace App\Services\Tracking;

use App\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AisStreamMessageProcessor
{
    private const POSITION_TYPES = [
        'PositionReport',
        'StandardClassBPositionReport',
        'ExtendedClassBPositionReport',
        'LongRangeAisBroadcastMessage',
    ];

    private const STATIC_TYPES = [
        'ShipStaticData',
        'StaticDataReport',
        // Type 19 combines a Class B position with name, ship type, and
        // dimensions, so it enriches both dynamic and static snapshots.
        'ExtendedClassBPositionReport',
    ];

    public function __construct(
        private readonly ShipmentAlertService $alerts,
        private readonly ShipmentRiskService $risk,
        private readonly GeoCalculator $geo,
    ) {}

    public function process(array $payload): int
    {
        $type = (string) ($payload['MessageType'] ?? '');
        if (! in_array($type, array_values(array_unique([...self::POSITION_TYPES, ...self::STATIC_TYPES])), true)) {
            return 0;
        }

        $meta = (array) ($payload['MetaData'] ?? $payload['Metadata'] ?? []);
        $body = data_get($payload, "Message.{$type}");
        if (! is_array($body)) {
            Log::debug('AISStream message ignored because its typed body is missing.', [
                'message_type' => $type,
            ]);

            return 0;
        }

        $flatBody = $this->flattenBody($body);
        $mmsi = preg_replace('/\D/', '', (string) ($meta['MMSI'] ?? $flatBody['UserID'] ?? ''));
        if (! preg_match('/^\d{9}$/', $mmsi)) {
            Log::debug('AISStream message ignored because its MMSI is invalid.', [
                'message_type' => $type,
            ]);

            return 0;
        }

        Log::debug('AISStream message received for a subscribed vessel.', [
            'message_type' => $type,
            'mmsi' => $mmsi,
        ]);

        $shipments = Shipment::withoutGlobalScopes()
            ->active()
            ->where('tracking_provider', 'aisstream')
            ->where(fn ($query) => $query->where('transport_mode', 'sea')->orWhereNull('transport_mode'))
            ->where('mmsi', $mmsi)
            ->get();

        if ($shipments->isEmpty()) {
            Log::debug('AISStream MMSI did not match an active AIMS AIS tracking record.', [
                'message_type' => $type,
                'mmsi' => $mmsi,
            ]);
            $this->recordHeartbeat();

            return 0;
        }

        $receivedAt = now();
        $updated = 0;
        foreach ($shipments as $candidate) {
            $didUpdate = DB::transaction(function () use ($candidate, $type, $meta, $body, $flatBody, $receivedAt, $mmsi) {
                $shipment = Shipment::withoutGlobalScopes()->lockForUpdate()->find($candidate->id);
                if (! $shipment
                    || $shipment->archived_at
                    || $shipment->tracking_provider !== 'aisstream'
                    || ! in_array($shipment->transport_mode, ['sea', null], true)) {
                    return false;
                }

                $before = [
                    'eta' => $shipment->eta?->copy(),
                    'status' => $shipment->status,
                    'risk_level' => $shipment->risk_level,
                    'distance_to_port_km' => $shipment->distance_to_port_km,
                ];
                $firstPosition = $shipment->position_updated_at === null;
                $changed = false;
                $details = is_array($shipment->vessel_details) ? $shipment->vessel_details : [];
                $aisDetails = is_array($details['aisstream'] ?? null) ? $details['aisstream'] : [];

                // Metadata can contain a normalized name even when the current
                // message is positional. Use it immediately instead of waiting
                // for a later type 5 or type 24 static broadcast.
                $name = $this->cleanText($meta['ShipName'] ?? $flatBody['Name'] ?? null);
                if ($name && $name !== $shipment->vessel_name) {
                    $shipment->vessel_name = $name;
                    $changed = true;
                }

                $bodyValid = ! array_key_exists('Valid', $body) || (bool) $body['Valid'];
                if (! $bodyValid) {
                    Log::debug('AISStream payload marked invalid; only safe metadata was considered.', [
                        'message_type' => $type,
                        'mmsi' => $mmsi,
                    ]);
                }

                $positionChanged = false;
                if ($bodyValid && in_array($type, self::POSITION_TYPES, true)) {
                    $lat = $this->coordinate($flatBody['Latitude'] ?? $meta['Latitude'] ?? $meta['latitude'] ?? null, -90, 90);
                    $lng = $this->coordinate($flatBody['Longitude'] ?? $meta['Longitude'] ?? $meta['longitude'] ?? null, -180, 180);
                    $speed = $this->numberInRange($flatBody['Sog'] ?? null, 0, 102.3, false);
                    $course = $this->numberInRange($flatBody['Cog'] ?? null, 0, 360, false);
                    $heading = $this->integerInRange($flatBody['TrueHeading'] ?? null, 0, 359);
                    $navigationStatus = $this->integerInRange($flatBody['NavigationalStatus'] ?? null, 0, 15);

                    if ($lat !== null && $lng !== null) {
                        $shipment->current_lat = $lat;
                        $shipment->current_lng = $lng;
                        $shipment->position_updated_at = $receivedAt;
                        $shipment->last_location_label = sprintf('AIS last-known position: %.4f, %.4f', $lat, $lng);
                        $positionChanged = true;

                        if ($this->validPoint($shipment->destination_lat, $shipment->destination_lng)) {
                            $shipment->distance_to_port_km = $this->geo->distanceKm(
                                $lat,
                                $lng,
                                (float) $shipment->destination_lat,
                                (float) $shipment->destination_lng,
                            );
                        }
                    }

                    if ($speed !== null) {
                        $shipment->speed_knots = $speed;
                        $positionChanged = true;
                    }
                    if ($course !== null) {
                        $shipment->course = $course;
                        $positionChanged = true;
                    }
                    if ($heading !== null) {
                        $shipment->heading = $heading;
                        $positionChanged = true;
                    }
                    if ($navigationStatus !== null) {
                        $shipment->navigation_status = $navigationStatus;
                        $positionChanged = true;
                    }

                    if ($speed !== null && $speed > 0.5 && $shipment->status === 'registered') {
                        $shipment->status = 'in_transit';
                    }

                    if ($positionChanged) {
                        $aisDetails['position'] = $this->mergeUseful(
                            is_array($aisDetails['position'] ?? null) ? $aisDetails['position'] : [],
                            [
                                'message_type' => $type,
                                'received_at' => $receivedAt->toIso8601String(),
                                'latitude' => $lat,
                                'longitude' => $lng,
                                'speed_knots' => $speed,
                                'course' => $course,
                                'heading' => $heading,
                                'navigation_status' => $navigationStatus,
                                'position_latency' => array_key_exists('PositionLatency', $flatBody)
                                    ? (bool) $flatBody['PositionLatency']
                                    : null,
                            ],
                        );
                        Log::debug('AISStream position parsed.', [
                            'mmsi' => $mmsi,
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'speed_knots' => $speed,
                            'course' => $course,
                        ]);
                    }
                }

                $staticChanged = false;
                if ($bodyValid && in_array($type, self::STATIC_TYPES, true)) {
                    $imo = preg_replace('/\D/', '', (string) ($flatBody['ImoNumber'] ?? $flatBody['IMO'] ?? $flatBody['Imo'] ?? ''));
                    $callSign = $this->cleanText($flatBody['CallSign'] ?? null);
                    $destination = $this->cleanText($flatBody['Destination'] ?? null);
                    $shipTypeCode = $this->integerInRange($flatBody['ShipType'] ?? $flatBody['Type'] ?? null, 1, 99);
                    $eta = $this->aisEta((array) ($flatBody['Eta'] ?? $flatBody['ETA'] ?? []));
                    $draught = $this->numberInRange($flatBody['MaximumStaticDraught'] ?? null, 0, 25.5, true);
                    $dimensions = $this->dimensions((array) ($flatBody['Dimension'] ?? []));

                    if ($this->validImo($imo) && $imo !== $shipment->imo) {
                        $shipment->imo = $imo;
                        $staticChanged = true;
                    }
                    if ($callSign && $callSign !== $shipment->call_sign) {
                        $shipment->call_sign = $callSign;
                        $staticChanged = true;
                    }
                    if ($shipTypeCode !== null) {
                        $vesselType = "AIS type {$shipTypeCode}";
                        if ($vesselType !== $shipment->vessel_type) {
                            $shipment->vessel_type = $vesselType;
                            $staticChanged = true;
                        }
                    }
                    if ($destination) {
                        $coordinates = $this->portCoordinates($destination);
                        $destinationName = $coordinates['name'] ?? $destination;
                        if ($destinationName !== $shipment->destination_port) {
                            $shipment->destination_port = $destinationName;
                            $staticChanged = true;
                        }
                        if ($coordinates) {
                            $shipment->destination_lat = $coordinates['lat'];
                            $shipment->destination_lng = $coordinates['lng'];
                        }
                    }
                    if ($eta) {
                        if ($shipment->eta && ! $shipment->eta->equalTo($eta)) {
                            $shipment->previous_eta = $shipment->eta->copy();
                        }
                        if (! $shipment->eta || ! $shipment->eta->equalTo($eta)) {
                            $shipment->eta = $eta;
                            $staticChanged = true;
                        }
                    }

                    $staticSnapshot = [
                        'message_type' => $type,
                        'received_at' => $receivedAt->toIso8601String(),
                        'name' => $name,
                        'imo' => $this->validImo($imo) ? $imo : null,
                        'call_sign' => $callSign,
                        'ship_type_code' => $shipTypeCode,
                        'dimensions' => $dimensions,
                    ];
                    $voyageSnapshot = [
                        'message_type' => $type,
                        'received_at' => $receivedAt->toIso8601String(),
                        'destination' => $destination,
                        'eta' => $eta?->toIso8601String(),
                        'maximum_static_draught_m' => $draught,
                    ];
                    $newStatic = $this->mergeUseful(
                        is_array($aisDetails['static'] ?? null) ? $aisDetails['static'] : [],
                        $staticSnapshot,
                    );
                    $newVoyage = $this->mergeUseful(
                        is_array($aisDetails['voyage'] ?? null) ? $aisDetails['voyage'] : [],
                        $voyageSnapshot,
                    );
                    if ($newStatic !== ($aisDetails['static'] ?? []) || $newVoyage !== ($aisDetails['voyage'] ?? [])) {
                        $aisDetails['static'] = $newStatic;
                        $aisDetails['voyage'] = $newVoyage;
                        $staticChanged = true;
                    }
                }

                if (! $changed && ! $positionChanged && ! $staticChanged) {
                    return false;
                }

                $aisDetails['last_message'] = [
                    'message_type' => $type,
                    'received_at' => $receivedAt->toIso8601String(),
                ];
                $details['aisstream'] = $aisDetails;
                $shipment->vessel_details = $details;
                $shipment->details_provider = 'aisstream';
                $shipment->details_updated_at = ($staticChanged || $changed)
                    ? $receivedAt
                    : $shipment->details_updated_at;
                $shipment->last_refreshed_at = $receivedAt;
                $shipment->tracking_mode = 'live_ais';
                $shipment->risk_level = $this->risk->assess($shipment);
                $this->alerts->sync($shipment, $before);
                $shipment->save();

                if ($firstPosition && $shipment->position_updated_at) {
                    $this->alerts->notifyOnce($shipment->fresh(), 'vessel_position_available');
                }
                if ($staticChanged
                    && ! $shipment->histories()->where('event_type', 'vessel_details_received')->exists()) {
                    $this->alerts->logHistory(
                        $shipment,
                        'vessel_details_received',
                        'Verified vessel identity or voyage details received from AISStream.',
                    );
                }

                Log::debug('AISStream vessel record updated in the database.', [
                    'shipment_id' => $shipment->id,
                    'company_id' => $shipment->company_id,
                    'mmsi' => $mmsi,
                    'position_updated' => $positionChanged,
                    'static_or_voyage_updated' => $staticChanged || $changed,
                ]);

                return true;
            });

            if ($didUpdate) {
                $updated++;
            }
        }

        $this->recordHeartbeat();

        return $updated;
    }

    private function flattenBody(array $body): array
    {
        $flat = $body;
        foreach (['ReportA', 'ReportB'] as $part) {
            $report = $body[$part] ?? null;
            if (is_array($report) && (! array_key_exists('Valid', $report) || (bool) $report['Valid'])) {
                $flat = array_merge($flat, $report);
            }
        }

        return $flat;
    }

    private function mergeUseful(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergeUseful($existing[$key], $value);
            } else {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $clean = trim((string) $value, " \t\n\r\0\x0B@");

        return $clean === '' ? null : $clean;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        return $this->numberInRange($value, $minimum, $maximum, true);
    }

    private function numberInRange(
        mixed $value,
        float $minimum,
        float $maximum,
        bool $inclusiveMaximum,
    ): ?float {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        $insideMaximum = $inclusiveMaximum ? $number <= $maximum : $number < $maximum;

        return is_finite($number) && $number >= $minimum && $insideMaximum ? $number : null;
    }

    private function integerInRange(mixed $value, int $minimum, int $maximum): ?int
    {
        if (! is_numeric($value) || (float) $value !== (float) (int) $value) {
            return null;
        }
        $number = (int) $value;

        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function validPoint(mixed $lat, mixed $lng): bool
    {
        return $this->coordinate($lat, -90, 90) !== null
            && $this->coordinate($lng, -180, 180) !== null;
    }

    private function validImo(string $imo): bool
    {
        if (! preg_match('/^\d{7}$/', $imo)) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 6; $index++) {
            $sum += ((int) $imo[$index]) * (7 - $index);
        }

        return $sum % 10 === (int) $imo[6];
    }

    private function dimensions(array $value): ?array
    {
        $parts = [];
        foreach (['A', 'B', 'C', 'D'] as $key) {
            $number = $this->integerInRange($value[$key] ?? null, 0, 511);
            if ($number !== null && $number > 0) {
                $parts[strtolower($key)] = $number;
            }
        }
        if ($parts === []) {
            return null;
        }

        $length = ($parts['a'] ?? 0) + ($parts['b'] ?? 0);
        $beam = ($parts['c'] ?? 0) + ($parts['d'] ?? 0);
        if ($length > 0) {
            $parts['length_m'] = $length;
        }
        if ($beam > 0) {
            $parts['beam_m'] = $beam;
        }

        return $parts;
    }

    private function aisEta(array $eta): ?Carbon
    {
        $month = (int) ($eta['Month'] ?? $eta['month'] ?? 0);
        $day = (int) ($eta['Day'] ?? $eta['day'] ?? 0);
        $hour = (int) ($eta['Hour'] ?? $eta['hour'] ?? -1);
        $minute = (int) ($eta['Minute'] ?? $eta['minute'] ?? -1);
        if (! checkdate($month, $day, now('UTC')->year)
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59) {
            return null;
        }
        $value = Carbon::create(now('UTC')->year, $month, $day, $hour, $minute, 0, 'UTC');
        if ($value->lt(now('UTC')->subDays(2))) {
            $value->addYear();
        }

        return $value;
    }

    private function portCoordinates(string $name): ?array
    {
        $needle = $this->portKey($name);

        return collect(config('ports', []))->first(function (array $port) use ($needle) {
            $portName = $this->portKey((string) ($port['name'] ?? ''));
            $city = $this->portKey(explode(',', (string) ($port['name'] ?? ''), 2)[0]);
            $code = $this->portKey((string) ($port['code'] ?? ''));

            return $needle === $portName
                || ($code !== '' && $needle === $code)
                || ($city !== '' && (str_starts_with($needle, $city) || str_starts_with($portName, $needle)));
        });
    }

    private function portKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii(trim($value)))) ?: '';
    }

    private function recordHeartbeat(): void
    {
        $now = now();
        Cache::put('tracking.aisstream.heartbeat', $now, now()->addDay());
        Cache::put('tracking.aisstream.last_message_at', $now, now()->addDay());

        $connection = Cache::get('tracking.aisstream.connection', []);
        if (is_array($connection)) {
            $connection['state'] = 'connected';
            $connection['last_message_at'] = $now->toIso8601String();
            $connection['updated_at'] = $now->toIso8601String();
            unset($connection['message'], $connection['retry_in_seconds']);
            Cache::put('tracking.aisstream.connection', $connection, now()->addDay());
        }
    }
}

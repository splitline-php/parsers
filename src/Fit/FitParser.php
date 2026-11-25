<?php

declare(strict_types=1);

namespace Splitline\Parsers\Fit;

use Splitline\Core\Data\ActivityStreamData;
use Splitline\Parsers\Exceptions\ParseException;
use ZipArchive;

/**
 * Pure PHP binary FIT protocol v1/v2 reader for endurance activity files and ZIP archives.
 *
 * @phpstan-import-type FitRecord from ActivityStreamData
 * @phpstan-import-type FitSeriesPoint from ActivityStreamData
 * @phpstan-import-type FitLap from ActivityStreamData
 * @phpstan-import-type FitKmSplit from ActivityStreamData
 * @phpstan-import-type FitRunWalkSegment from ActivityStreamData
 * @phpstan-import-type FitJumpEvent from ActivityStreamData
 */
class FitParser
{
    private const int FIT_EPOCH_OFFSET = 631065600;

    private const float SEMICIRCLE_TO_DEGREES = 180.0 / 2147483648.0;

    private const int MSG_FILE_ID = 0;

    private const int MSG_SESSION = 18;

    private const int MSG_LAP = 19;

    private const int MSG_RECORD = 20;

    private const int MSG_WORKOUT = 26;

    private const int MSG_WORKOUT_STEP = 27;

    private const int MSG_TIME_IN_ZONE = 216;

    private const int MSG_JUMP = 285;

    private const int MSG_SPLIT = 312;

    private const int MSG_SPLIT_SUMMARY = 313;

    /**
     * @var array<int, true>
     */
    private const array RECORD_FIELD_NUMS = [
        0 => true,   // position_lat
        1 => true,   // position_long
        2 => true,   // altitude (legacy)
        3 => true,   // heart_rate
        4 => true,   // cadence
        5 => true,   // distance
        6 => true,   // speed (legacy)
        7 => true,   // power
        13 => true,  // temperature
        30 => true,  // left_right_balance
        39 => true,  // vertical_oscillation
        41 => true,  // stance_time (GCT)
        43 => true,  // left_torque_effectiveness
        44 => true,  // right_torque_effectiveness
        45 => true,  // left_pedal_smoothness
        46 => true,  // right_pedal_smoothness
        53 => true,  // fractional_cadence
        73 => true,  // enhanced_speed
        78 => true,  // enhanced_altitude
        83 => true,  // vertical_ratio
        85 => true,  // step_length (stride length)
        90 => true,  // performance_condition
        108 => true, // enhanced_respiration_rate
        114 => true, // grit
        115 => true, // flow
        137 => true, // stamina_potential
        138 => true, // stamina
        140 => true, // grade_adjusted_speed
        253 => true, // timestamp
    ];

    /**
     * @var list<int>
     */
    private const array DOUBLE_CADENCE_SPORTS = [
        1,  // running
        11, // walking
        17, // hiking
    ];

    /**
     * @var array<int, true>
     */
    private const array TIMESTAMP_ONLY_FIELD_NUMS = [
        253 => true,
    ];

    private const int MAX_SERIES_POINTS = 600;

    private const int MAX_POLYLINE_POINTS = 400;

    private const float EARTH_RADIUS_METERS = 6371000.0;

    private const float MINETTI_FLAT_COST = 3.6;

    /**
     * Extract and return the first .fit file entry from raw ZIP bytes.
     *
     * @throws ParseException
     */
    public function extractFitFromZip(string $zipBytes): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'splitline_fit_');

        if ($tempPath === false) {
            throw new ParseException('Unable to create temporary file for FIT ZIP extraction.');
        }

        try {
            if (file_put_contents($tempPath, $zipBytes) === false) {
                throw new ParseException('Unable to write FIT ZIP bytes to temporary file.');
            }

            $zip = new ZipArchive;
            $openResult = $zip->open($tempPath);

            if ($openResult !== true) {
                throw new ParseException("Unable to open FIT ZIP archive (code {$openResult}).");
            }

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entryName = $zip->getNameIndex($index);

                    if (! is_string($entryName)) {
                        continue;
                    }

                    if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) === 'fit') {
                        $contents = $zip->getFromIndex($index);

                        if (is_string($contents)) {
                            return $contents;
                        }
                    }
                }
            } finally {
                $zip->close();
            }

            throw new ParseException('No .fit file found inside FIT ZIP archive.');
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Extract the first .fit file from raw ZIP bytes and parse it.
     *
     * @throws ParseException
     */
    public function parseZip(string $zipBytes): ActivityStreamData
    {
        return $this->parseBinary($this->extractFitFromZip($zipBytes));
    }

    /**
     * Parse raw FIT binary bytes into normalized telemetry records, series, polyline, laps, and km splits.
     *
     * @throws ParseException
     */
    public function parseBinary(string $binary): ActivityStreamData
    {
        $length = strlen($binary);

        if ($length < 12) {
            throw new ParseException('FIT binary is too short to contain a valid header.');
        }

        $headerSize = ord($binary[0]);

        if ($headerSize !== 12 && $headerSize !== 14) {
            throw new ParseException("Unsupported FIT header size: {$headerSize}.");
        }

        if ($length < $headerSize) {
            throw new ParseException('FIT binary is truncated within the header.');
        }

        if (substr($binary, 8, 4) !== '.FIT') {
            throw new ParseException('Invalid FIT header signature (expected .FIT).');
        }

        $dataSize = $this->unpackUint32(substr($binary, 4, 4), isBigEndian: false);
        $endOffset = min($length, $headerSize + $dataSize);

        /**
         * @var array<int, array{
         *     global_msg: int,
         *     big_endian: bool,
         *     fields: list<array{num: int, size: int, base_type: int}>,
         *     dev_size: int,
         *     data_size: int
         * }> $definitions
         */
        $definitions = [];

        /** @var list<FitRecord> $records */
        $records = [];

        /** @var list<?float> $recordFractionalCadences */
        $recordFractionalCadences = [];

        /** @var list<array<int, mixed>> $rawLaps */
        $rawLaps = [];

        /** @var array<int, mixed>|null $fileId */
        $fileId = null;

        /** @var array<int, mixed>|null $session */
        $session = null;

        /** @var array<int, mixed>|null $workout */
        $workout = null;

        /** @var list<array<int, mixed>> $workoutSteps */
        $workoutSteps = [];

        /** @var list<array<int, mixed>> $timeInZones */
        $timeInZones = [];

        /** @var list<array<int, mixed>> $rawSplits */
        $rawSplits = [];

        /** @var list<array<int, mixed>> $rawSplitSummaries */
        $rawSplitSummaries = [];

        /** @var list<array<int, mixed>> $rawJumps */
        $rawJumps = [];

        $firstUnixTimestamp = null;
        $lastTimestamp = null;
        $offset = $headerSize;

        while ($offset < $endOffset) {
            $header = ord($binary[$offset]);
            $offset++;

            if (($header & 0x80) === 0x80) {
                $localType = ($header >> 5) & 0x03;
                $timeOffset = $header & 0x1F;

                if (! isset($definitions[$localType])) {
                    break;
                }

                $definition = $definitions[$localType];

                if ($offset + $definition['data_size'] > $endOffset) {
                    break;
                }

                $fields = $this->readDataFields($binary, $offset, $definition);
                $offset += $definition['data_size'];

                if ($lastTimestamp !== null) {
                    if ($timeOffset >= ($lastTimestamp & 0x1F)) {
                        $timestamp = ($lastTimestamp & ~0x1F) + $timeOffset;
                    } else {
                        $timestamp = ($lastTimestamp & ~0x1F) + 0x20 + $timeOffset;
                    }

                    $lastTimestamp = $timestamp;

                    if (! isset($fields[253])) {
                        $fields[253] = $timestamp;
                    }
                }

                $this->collectMessage(
                    $definition['global_msg'],
                    $fields,
                    $records,
                    $recordFractionalCadences,
                    $firstUnixTimestamp,
                    $rawLaps,
                    $fileId,
                    $session,
                    $workout,
                    $workoutSteps,
                    $timeInZones,
                    $rawSplits,
                    $rawSplitSummaries,
                    $rawJumps,
                );

                continue;
            }

            if (($header & 0x40) === 0x40) {
                $hasDeveloperFields = ($header & 0x20) === 0x20;
                $localType = $header & 0x0F;

                if ($offset + 5 > $endOffset) {
                    break;
                }

                $offset++; // reserved byte
                $isBigEndian = ord($binary[$offset]) === 1;
                $offset++;

                $globalMsg = $this->unpackUint16(substr($binary, $offset, 2), $isBigEndian);
                $offset += 2;

                $fieldCount = ord($binary[$offset]);
                $offset++;

                if ($offset + ($fieldCount * 3) > $endOffset) {
                    break;
                }

                $fields = [];
                $standardDataSize = 0;

                for ($i = 0; $i < $fieldCount; $i++) {
                    $fieldNum = ord($binary[$offset]);
                    $fieldSize = ord($binary[$offset + 1]);
                    $baseType = ord($binary[$offset + 2]);
                    $offset += 3;

                    $fields[] = [
                        'num' => $fieldNum,
                        'size' => $fieldSize,
                        'base_type' => $baseType,
                    ];

                    $standardDataSize += $fieldSize;
                }

                $developerDataSize = 0;

                if ($hasDeveloperFields) {
                    if ($offset + 1 > $endOffset) {
                        break;
                    }

                    $devFieldCount = ord($binary[$offset]);
                    $offset++;

                    if ($offset + ($devFieldCount * 3) > $endOffset) {
                        break;
                    }

                    for ($i = 0; $i < $devFieldCount; $i++) {
                        $developerDataSize += ord($binary[$offset + 1]);
                        $offset += 3;
                    }
                }

                $definitions[$localType] = [
                    'global_msg' => $globalMsg,
                    'big_endian' => $isBigEndian,
                    'fields' => $fields,
                    'dev_size' => $developerDataSize,
                    'data_size' => $standardDataSize + $developerDataSize,
                ];

                continue;
            }

            $localType = $header & 0x0F;

            if (! isset($definitions[$localType])) {
                break;
            }

            $definition = $definitions[$localType];

            if ($offset + $definition['data_size'] > $endOffset) {
                break;
            }

            $fields = $this->readDataFields($binary, $offset, $definition);
            $offset += $definition['data_size'];

            if (isset($fields[253]) && is_int($fields[253])) {
                $lastTimestamp = $fields[253];
            }

            $this->collectMessage(
                $definition['global_msg'],
                $fields,
                $records,
                $recordFractionalCadences,
                $firstUnixTimestamp,
                $rawLaps,
                $fileId,
                $session,
                $workout,
                $workoutSteps,
                $timeInZones,
                $rawSplits,
                $rawSplitSummaries,
                $rawJumps,
            );
        }

        $isDoubleCadenceSport = $this->isDoubleCadenceSport($session);
        $records = $this->applyRunningCadenceNormalization($records, $recordFractionalCadences, $isDoubleCadenceSport);
        $records = $this->populateHaversineDistanceIfMissing($records);
        $series = $this->buildDownsampledSeries($records);
        $polyline = $this->buildSimplifiedPolyline($records);
        $laps = $this->normalizeLaps($rawLaps, $isDoubleCadenceSport);
        $kmSplits = $this->buildKmSplits($records);
        $runWalkSegments = $this->normalizeRunWalkSegments($rawSplits, $firstUnixTimestamp);
        $jumps = $this->normalizeJumps($rawJumps, $firstUnixTimestamp);
        $sessionSummary = $this->buildSessionSummary(
            $session,
            $records,
            $timeInZones,
            $workout,
            $workoutSteps,
            $rawSplitSummaries,
            $runWalkSegments,
        );
        $sessionMetadata = $this->buildSessionMetadata($session, $fileId);

        return new ActivityStreamData(
            records: $records,
            series: $series,
            polyline: $polyline,
            laps: $laps,
            kmSplits: $kmSplits,
            runWalkSegments: $runWalkSegments,
            jumps: $jumps,
            sessionSummary: $sessionSummary,
            sessionMetadata: $sessionMetadata,
        );
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  list<FitRecord>  $records
     * @param  list<?float>  $recordFractionalCadences
     * @param  list<array<int, mixed>>  $rawLaps
     * @param  array<int, mixed>|null  $fileId
     * @param  array<int, mixed>|null  $session
     * @param  array<int, mixed>|null  $workout
     * @param  list<array<int, mixed>>  $workoutSteps
     * @param  list<array<int, mixed>>  $timeInZones
     * @param  list<array<int, mixed>>  $rawSplits
     * @param  list<array<int, mixed>>  $rawSplitSummaries
     * @param  list<array<int, mixed>>  $rawJumps
     */
    private function collectMessage(
        int $globalMsg,
        array $fields,
        array &$records,
        array &$recordFractionalCadences,
        ?int &$firstUnixTimestamp,
        array &$rawLaps,
        ?array &$fileId,
        ?array &$session,
        ?array &$workout,
        array &$workoutSteps,
        array &$timeInZones,
        array &$rawSplits,
        array &$rawSplitSummaries,
        array &$rawJumps,
    ): void {
        if ($globalMsg === self::MSG_RECORD) {
            $normalized = $this->normalizeRecord($fields, $firstUnixTimestamp);

            if ($normalized !== null) {
                $records[] = $normalized;
                $rawFractional = $this->fieldInt($fields, 53);
                $recordFractionalCadences[] = $rawFractional !== null ? ($rawFractional / 128.0) : null;
            }
        } elseif ($globalMsg === self::MSG_LAP) {
            $rawLaps[] = $fields;
        } elseif ($globalMsg === self::MSG_FILE_ID) {
            $fileId = $fields;
        } elseif ($globalMsg === self::MSG_SESSION) {
            $session = $fields;
        } elseif ($globalMsg === self::MSG_WORKOUT) {
            $workout = $fields;
        } elseif ($globalMsg === self::MSG_WORKOUT_STEP) {
            $workoutSteps[] = $fields;
        } elseif ($globalMsg === self::MSG_TIME_IN_ZONE) {
            $timeInZones[] = $fields;
        } elseif ($globalMsg === self::MSG_SPLIT) {
            $rawSplits[] = $fields;
        } elseif ($globalMsg === self::MSG_SPLIT_SUMMARY) {
            $rawSplitSummaries[] = $fields;
        } elseif ($globalMsg === self::MSG_JUMP) {
            $rawJumps[] = $fields;
        }
    }

    /**
     * @param  array{
     *     global_msg: int,
     *     big_endian: bool,
     *     fields: list<array{num: int, size: int, base_type: int}>,
     *     dev_size: int,
     *     data_size: int
     * }  $definition
     * @return array<int, mixed>
     */
    private function readDataFields(string $binary, int $startOffset, array $definition): array
    {
        $fields = [];
        $cursor = $startOffset;
        $isBigEndian = $definition['big_endian'];
        $allowedFields = match ($definition['global_msg']) {
            self::MSG_RECORD => self::RECORD_FIELD_NUMS,
            self::MSG_LAP,
            self::MSG_FILE_ID,
            self::MSG_SESSION,
            self::MSG_WORKOUT,
            self::MSG_WORKOUT_STEP,
            self::MSG_TIME_IN_ZONE,
            self::MSG_JUMP,
            self::MSG_SPLIT,
            self::MSG_SPLIT_SUMMARY => null,
            default => self::TIMESTAMP_ONLY_FIELD_NUMS,
        };

        foreach ($definition['fields'] as $fieldDef) {
            $size = $fieldDef['size'];
            $fieldNum = $fieldDef['num'];

            if ($allowedFields !== null && ! isset($allowedFields[$fieldNum])) {
                $cursor += $size;

                continue;
            }

            $raw = substr($binary, $cursor, $size);
            $cursor += $size;

            $decoded = $this->decodeFieldValue($raw, $fieldDef['base_type'], $isBigEndian);

            if ($decoded !== null) {
                $fields[$fieldNum] = $decoded;
            }
        }

        return $fields;
    }

    private function decodeFieldValue(string $raw, int $baseType, bool $isBigEndian): mixed
    {
        $size = strlen($raw);

        if ($size === 0) {
            return null;
        }

        $typeNum = $baseType & 0x1F;

        if ($typeNum === 7) {
            $nullPos = strpos($raw, "\0");
            $value = $nullPos !== false ? substr($raw, 0, $nullPos) : $raw;

            return $value !== '' ? $value : null;
        }

        if ($typeNum === 13) {
            if ($raw === str_repeat("\xFF", $size)) {
                return null;
            }

            return $size === 1 ? ord($raw[0]) : $raw;
        }

        $unitSize = $this->baseTypeSize($typeNum);

        if ($unitSize === 0 || $size < $unitSize) {
            return null;
        }

        if ($size === $unitSize) {
            return $this->decodeScalar($raw, $typeNum, $isBigEndian);
        }

        if ($size % $unitSize !== 0) {
            return null;
        }

        $values = [];
        $count = intdiv($size, $unitSize);

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($raw, $i * $unitSize, $unitSize);
            $values[] = $this->decodeScalar($chunk, $typeNum, $isBigEndian);
        }

        foreach ($values as $value) {
            if ($value !== null) {
                return $values;
            }
        }

        return null;
    }

    private function baseTypeSize(int $typeNum): int
    {
        return match ($typeNum) {
            0, 1, 2, 10, 13 => 1,
            3, 4, 11 => 2,
            5, 6, 8, 12 => 4,
            9, 14, 15, 16 => 8,
            default => 0,
        };
    }

    private function decodeScalar(string $raw, int $typeNum, bool $isBigEndian): int|float|null
    {
        return match ($typeNum) {
            0, 2 => $this->decodeUint8($raw, invalidSentinel: 0xFF),
            10 => $this->decodeUint8($raw, invalidSentinel: 0x00),
            1 => $this->decodeSint8($raw),
            3 => $this->decodeSint16($raw, $isBigEndian),
            4 => $this->decodeUint16($raw, $isBigEndian, invalidSentinel: 0xFFFF),
            11 => $this->decodeUint16($raw, $isBigEndian, invalidSentinel: 0x0000),
            5 => $this->decodeSint32($raw, $isBigEndian),
            6 => $this->decodeUint32($raw, $isBigEndian, invalidSentinel: 0xFFFFFFFF),
            12 => $this->decodeUint32($raw, $isBigEndian, invalidSentinel: 0x00000000),
            8 => $this->decodeFloat32($raw, $isBigEndian),
            9 => $this->decodeFloat64($raw, $isBigEndian),
            14 => $this->decodeSint64($raw, $isBigEndian),
            15 => $this->decodeUint64($raw, $isBigEndian, invalidBytes: "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"),
            16 => $this->decodeUint64($raw, $isBigEndian, invalidBytes: "\x00\x00\x00\x00\x00\x00\x00\x00"),
            default => null,
        };
    }

    private function decodeUint8(string $raw, int $invalidSentinel): ?int
    {
        $value = ord($raw[0]);

        return $value === $invalidSentinel ? null : $value;
    }

    private function decodeSint8(string $raw): ?int
    {
        $value = ord($raw[0]);

        if ($value === 0x7F) {
            return null;
        }

        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    private function decodeSint16(string $raw, bool $isBigEndian): ?int
    {
        $unsigned = $this->unpackUint16($raw, $isBigEndian);

        if ($unsigned === 0x7FFF) {
            return null;
        }

        return $unsigned >= 0x8000 ? $unsigned - 0x10000 : $unsigned;
    }

    private function decodeUint16(string $raw, bool $isBigEndian, int $invalidSentinel): ?int
    {
        $value = $this->unpackUint16($raw, $isBigEndian);

        return $value === $invalidSentinel ? null : $value;
    }

    private function decodeSint32(string $raw, bool $isBigEndian): ?int
    {
        $unsigned = $this->unpackUint32($raw, $isBigEndian);

        if ($unsigned === 0x7FFFFFFF) {
            return null;
        }

        return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
    }

    private function decodeUint32(string $raw, bool $isBigEndian, int $invalidSentinel): ?int
    {
        $value = $this->unpackUint32($raw, $isBigEndian);

        return $value === $invalidSentinel ? null : $value;
    }

    private function decodeFloat32(string $raw, bool $isBigEndian): ?float
    {
        if ($this->unpackUint32($raw, $isBigEndian) === 0xFFFFFFFF) {
            return null;
        }

        $unpacked = unpack($isBigEndian ? 'Gvalue' : 'gvalue', $raw);

        if ($unpacked === false || ! isset($unpacked['value']) || ! is_float($unpacked['value'])) {
            return null;
        }

        $value = $unpacked['value'];

        return is_nan($value) || is_infinite($value) ? null : $value;
    }

    private function decodeFloat64(string $raw, bool $isBigEndian): ?float
    {
        if ($raw === "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF") {
            return null;
        }

        $unpacked = unpack($isBigEndian ? 'Evalue' : 'evalue', $raw);

        if ($unpacked === false || ! isset($unpacked['value']) || ! is_float($unpacked['value'])) {
            return null;
        }

        $value = $unpacked['value'];

        return is_nan($value) || is_infinite($value) ? null : $value;
    }

    private function decodeSint64(string $raw, bool $isBigEndian): ?int
    {
        $value = $this->unpackInt64($raw, $isBigEndian);

        return $value === PHP_INT_MAX ? null : $value;
    }

    private function decodeUint64(string $raw, bool $isBigEndian, string $invalidBytes): ?int
    {
        if ($raw === $invalidBytes) {
            return null;
        }

        return $this->unpackInt64($raw, $isBigEndian);
    }

    private function unpackUint16(string $raw, bool $isBigEndian): int
    {
        $unpacked = unpack($isBigEndian ? 'nvalue' : 'vvalue', $raw);

        return $unpacked !== false && isset($unpacked['value']) && is_int($unpacked['value'])
            ? $unpacked['value']
            : 0;
    }

    private function unpackUint32(string $raw, bool $isBigEndian): int
    {
        $unpacked = unpack($isBigEndian ? 'Nvalue' : 'Vvalue', $raw);

        return $unpacked !== false && isset($unpacked['value']) && is_int($unpacked['value'])
            ? $unpacked['value']
            : 0;
    }

    private function unpackInt64(string $raw, bool $isBigEndian): int
    {
        $unpacked = unpack($isBigEndian ? 'Jvalue' : 'Pvalue', $raw);

        return $unpacked !== false && isset($unpacked['value']) && is_int($unpacked['value'])
            ? $unpacked['value']
            : 0;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return FitRecord|null
     */
    private function normalizeRecord(array $raw, ?int &$firstUnixTimestamp): ?array
    {
        $fitTimestamp = $this->fieldInt($raw, 253);

        if ($fitTimestamp === null) {
            return null;
        }

        $unixTimestamp = $fitTimestamp + self::FIT_EPOCH_OFFSET;

        if ($firstUnixTimestamp === null) {
            $firstUnixTimestamp = $unixTimestamp;
        }

        $elapsedTime = (float) max(0, $unixTimestamp - $firstUnixTimestamp);

        $rawLat = $this->fieldInt($raw, 0);
        $rawLng = $this->fieldInt($raw, 1);
        $lat = $rawLat !== null ? round($rawLat * self::SEMICIRCLE_TO_DEGREES, 7) : null;
        $lng = $rawLng !== null ? round($rawLng * self::SEMICIRCLE_TO_DEGREES, 7) : null;

        if ($lat !== null && ($lat < -90.0 || $lat > 90.0)) {
            $lat = null;
        }

        if ($lng !== null && ($lng < -180.0 || $lng > 180.0)) {
            $lng = null;
        }

        $enhancedAltitude = $this->fieldNumber($raw, 78);
        $legacyAltitude = $this->fieldNumber($raw, 2);
        $rawAltitude = $enhancedAltitude ?? $legacyAltitude;
        $altitude = $rawAltitude !== null ? round(($rawAltitude / 5.0) - 500.0, 2) : null;

        $rawDistance = $this->fieldNumber($raw, 5);
        $distance = $rawDistance !== null ? round($rawDistance / 100.0, 2) : null;

        $enhancedSpeed = $this->fieldNumber($raw, 73);
        $legacySpeed = $this->fieldNumber($raw, 6);
        $rawSpeed = $enhancedSpeed ?? $legacySpeed;
        $speed = $rawSpeed !== null ? round($rawSpeed / 1000.0, 3) : null;

        $rawGapSpeed = $this->fieldNumber($raw, 140);
        $gapSpeed = $rawGapSpeed !== null && $rawGapSpeed > 0.0 ? round($rawGapSpeed / 1000.0, 3) : null;

        $rawStaminaPotential = $this->fieldInt($raw, 137);
        $staminaPotential = $rawStaminaPotential !== null && $rawStaminaPotential >= 0 && $rawStaminaPotential <= 100
            ? $rawStaminaPotential
            : null;

        $rawStamina = $this->fieldInt($raw, 138);
        $stamina = $rawStamina !== null && $rawStamina >= 0 && $rawStamina <= 100
            ? $rawStamina
            : null;

        $rawPerfCondition = $this->fieldInt($raw, 90);
        $perfCondition = $rawPerfCondition !== null && $rawPerfCondition >= -20 && $rawPerfCondition <= 20
            ? $rawPerfCondition
            : null;

        $rawStepLength = $this->fieldNumber($raw, 85);
        $strideLength = $rawStepLength !== null && $rawStepLength > 0.0
            ? round($rawStepLength / 10000.0, 3)
            : null;

        $rawVertOsc = $this->fieldNumber($raw, 39);
        $vertOsc = $rawVertOsc !== null && $rawVertOsc > 0.0
            ? round($rawVertOsc / 100.0, 2)
            : null;

        $rawVertRatio = $this->fieldNumber($raw, 83);
        $vertRatio = $rawVertRatio !== null && $rawVertRatio > 0.0
            ? round($rawVertRatio / 100.0, 2)
            : null;

        $rawGct = $this->fieldNumber($raw, 41);
        $gct = $rawGct !== null && $rawGct > 0.0
            ? round($rawGct / 10.0, 1)
            : null;

        $rawResp = $this->fieldNumber($raw, 108);
        $respiration = $rawResp !== null && $rawResp > 0.0
            ? round($rawResp / 100.0, 1)
            : null;

        $rawTemp = $this->fieldNumber($raw, 13);
        $temperature = $rawTemp !== null && $rawTemp >= -50.0 && $rawTemp <= 80.0
            ? round($rawTemp, 1)
            : null;

        $leftBalance = $this->decodeLeftBalance($this->fieldInt($raw, 30));

        $rawLeftTe = $this->fieldNumber($raw, 43);
        $leftTe = $rawLeftTe !== null && $rawLeftTe <= 200.0 ? round($rawLeftTe / 2.0, 1) : null;

        $rawRightTe = $this->fieldNumber($raw, 44);
        $rightTe = $rawRightTe !== null && $rawRightTe <= 200.0 ? round($rawRightTe / 2.0, 1) : null;

        $rawLeftPs = $this->fieldNumber($raw, 45);
        $leftPs = $rawLeftPs !== null && $rawLeftPs <= 200.0 ? round($rawLeftPs / 2.0, 1) : null;

        $rawRightPs = $this->fieldNumber($raw, 46);
        $rightPs = $rawRightPs !== null && $rawRightPs <= 200.0 ? round($rawRightPs / 2.0, 1) : null;

        $rawGrit = $this->fieldNumber($raw, 114);
        $grit = $rawGrit !== null && $rawGrit >= 0.0 ? round($rawGrit, 2) : null;

        $rawFlow = $this->fieldNumber($raw, 115);
        $flow = $rawFlow !== null && $rawFlow >= 0.0 ? round($rawFlow, 2) : null;

        $record = [
            'time' => $elapsedTime,
            'distance' => $distance,
            'altitude' => $altitude,
            'speed' => $speed,
            'hr' => $this->fieldInt($raw, 3),
            'cadence' => $this->fieldInt($raw, 4),
            'power' => $this->fieldInt($raw, 7),
            'lat' => $lat,
            'lng' => $lng,
        ];

        if ($gapSpeed !== null) {
            $record['gap_speed'] = $gapSpeed;
        }

        if ($stamina !== null) {
            $record['stamina'] = $stamina;
        }

        if ($staminaPotential !== null) {
            $record['stamina_potential'] = $staminaPotential;
        }

        if ($perfCondition !== null) {
            $record['performance_condition'] = $perfCondition;
        }

        if ($strideLength !== null) {
            $record['stride_length'] = $strideLength;
        }

        if ($vertOsc !== null) {
            $record['vertical_oscillation'] = $vertOsc;
        }

        if ($vertRatio !== null) {
            $record['vertical_ratio'] = $vertRatio;
        }

        if ($gct !== null) {
            $record['gct'] = $gct;
        }

        if ($respiration !== null) {
            $record['respiration'] = $respiration;
        }

        if ($temperature !== null) {
            $record['temperature'] = $temperature;
        }

        if ($leftBalance !== null) {
            $record['left_balance'] = $leftBalance;
        }

        if ($leftTe !== null) {
            $record['left_te'] = $leftTe;
        }

        if ($rightTe !== null) {
            $record['right_te'] = $rightTe;
        }

        if ($leftPs !== null) {
            $record['left_ps'] = $leftPs;
        }

        if ($rightPs !== null) {
            $record['right_ps'] = $rightPs;
        }

        if ($grit !== null) {
            $record['grit'] = $grit;
        }

        if ($flow !== null) {
            $record['flow'] = $flow;
        }

        return $record;
    }

    private function decodeLeftBalance(?int $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $pct = $raw & 0x7F;

        if ($pct > 100) {
            return null;
        }

        $isRight = ($raw & 0x80) !== 0;

        return (float) ($isRight ? (100 - $pct) : $pct);
    }

    /**
     * @param  array<int, mixed>|null  $session
     */
    private function isDoubleCadenceSport(?array $session): bool
    {
        if ($session === null) {
            return false;
        }

        $sport = $this->fieldInt($session, 5);

        return $sport !== null && in_array($sport, self::DOUBLE_CADENCE_SPORTS, true);
    }

    /**
     * FIT running/walking/hiking cadence is recorded per-foot (0..127 strides/min + fractional_cadence/128).
     * Double single-foot values (<= 127) to steps per minute while leaving already-doubled values untouched.
     *
     * @param  list<FitRecord>  $records
     * @param  list<?float>  $fractionalCadences
     * @return list<FitRecord>
     */
    private function applyRunningCadenceNormalization(
        array $records,
        array $fractionalCadences,
        bool $isDoubleCadenceSport,
    ): array {
        if (! $isDoubleCadenceSport) {
            return $records;
        }

        foreach ($records as $index => $record) {
            $cadence = $record['cadence'];

            if ($cadence !== null && $cadence > 0 && $cadence <= 127) {
                $fractional = $fractionalCadences[$index] ?? 0.0;
                $records[$index]['cadence'] = (int) round(($cadence + $fractional) * 2.0);
            }
        }

        return $records;
    }

    /**
     * @param  list<FitRecord>  $records
     * @return list<FitRecord>
     */
    private function populateHaversineDistanceIfMissing(array $records): array
    {
        foreach ($records as $record) {
            if ($record['distance'] !== null) {
                return $records;
            }
        }

        $cumulativeMeters = 0.0;
        $prevLat = null;
        $prevLng = null;

        foreach ($records as $index => $record) {
            $lat = $record['lat'];
            $lng = $record['lng'];

            if ($lat === null || $lng === null) {
                continue;
            }

            if ($prevLat !== null && $prevLng !== null) {
                $cumulativeMeters += $this->haversineMeters($prevLat, $prevLng, $lat, $lng);
            }

            $records[$index]['distance'] = round($cumulativeMeters, 2);
            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $records;
    }

    /**
     * @param  list<FitRecord>  $records
     * @return list<FitSeriesPoint>
     */
    private function buildDownsampledSeries(array $records): array
    {
        $count = count($records);

        if ($count === 0) {
            return [];
        }

        $grades = $this->computeSmoothedGrades($records);

        if ($count <= self::MAX_SERIES_POINTS) {
            $series = [];

            foreach ($records as $index => $record) {
                $series[] = $this->buildSeriesPoint($record, $grades[$index]);
            }

            return $series;
        }

        $series = [];
        $lastIndex = $count - 1;
        $maxSteps = self::MAX_SERIES_POINTS - 1;

        for ($step = 0; $step < self::MAX_SERIES_POINTS; $step++) {
            $index = (int) round(($step * $lastIndex) / $maxSteps);
            $prevIndex = $step > 0 ? (int) round((($step - 1) * $lastIndex) / $maxSteps) : 0;
            $nextIndex = $step < $maxSteps ? (int) round((($step + 1) * $lastIndex) / $maxSteps) : $lastIndex;
            $binStart = (int) floor(($prevIndex + $index) / 2);
            $binEnd = (int) ceil(($index + $nextIndex) / 2);

            $point = $this->buildSeriesPoint($records[$index], $grades[$index]);

            // Bucket-average noisy cycling pedaling metrics across the downsampled window
            $avgBalance = $this->averageBinMetric($records, $binStart, $binEnd, 'left_balance', minVal: 25.0, maxVal: 75.0, requirePower: true);
            if ($avgBalance !== null) {
                $point['left_balance'] = round($avgBalance, 1);
            } else {
                unset($point['left_balance']);
            }

            foreach (['left_te', 'right_te', 'left_ps', 'right_ps'] as $metricKey) {
                $avgMetric = $this->averageBinMetric($records, $binStart, $binEnd, $metricKey, minVal: 0.1, maxVal: 100.0, requirePower: true);
                if ($avgMetric !== null) {
                    $point[$metricKey] = round($avgMetric, 1);
                } else {
                    unset($point[$metricKey]);
                }
            }

            $series[] = $point;
        }

        return $series;
    }

    /**
     * Average an optional numeric metric across a record index window [start..end].
     *
     * @param  list<FitRecord>  $records
     * @param  'left_balance'|'left_te'|'right_te'|'left_ps'|'right_ps'  $key
     */
    private function averageBinMetric(
        array $records,
        int $start,
        int $end,
        string $key,
        float $minVal,
        float $maxVal,
        bool $requirePower = false,
    ): ?float {
        $sum = 0.0;
        $count = 0;

        for ($i = $start; $i <= $end; $i++) {
            if (! isset($records[$i][$key])) {
                continue;
            }

            if ($requirePower && ($records[$i]['power'] === null || $records[$i]['power'] <= 0)) {
                continue;
            }

            $val = $records[$i][$key];

            if ($val < $minVal || $val > $maxVal) {
                continue;
            }

            $sum += $val;
            $count++;
        }

        return $count > 0 ? ($sum / $count) : null;
    }

    /**
     * @param  FitRecord  $record
     * @return FitSeriesPoint
     */
    private function buildSeriesPoint(array $record, ?float $grade): array
    {
        $point = [
            'time' => $record['time'],
            'distance' => $record['distance'],
            'altitude' => $record['altitude'],
            'speed' => $record['speed'],
            'hr' => $record['hr'],
            'cadence' => $record['cadence'],
            'power' => $record['power'],
            'grade' => $grade,
            'lat' => $record['lat'],
            'lng' => $record['lng'],
        ];

        if (isset($record['gap_speed'])) {
            $point['gap_speed'] = $record['gap_speed'];
        }

        if (isset($record['stamina'])) {
            $point['stamina'] = $record['stamina'];
        }

        if (isset($record['stamina_potential'])) {
            $point['stamina_potential'] = $record['stamina_potential'];
        }

        if (isset($record['performance_condition'])) {
            $point['performance_condition'] = $record['performance_condition'];
        }

        if (isset($record['stride_length'])) {
            $point['stride_length'] = $record['stride_length'];
        }

        if (isset($record['vertical_oscillation'])) {
            $point['vertical_oscillation'] = $record['vertical_oscillation'];
        }

        if (isset($record['vertical_ratio'])) {
            $point['vertical_ratio'] = $record['vertical_ratio'];
        }

        if (isset($record['gct'])) {
            $point['gct'] = $record['gct'];
        }

        if (isset($record['respiration'])) {
            $point['respiration'] = $record['respiration'];
        }

        if (isset($record['temperature'])) {
            $point['temperature'] = $record['temperature'];
        }

        if (isset($record['left_balance'])) {
            $point['left_balance'] = $record['left_balance'];
        }

        if (isset($record['left_te'])) {
            $point['left_te'] = $record['left_te'];
        }

        if (isset($record['right_te'])) {
            $point['right_te'] = $record['right_te'];
        }

        if (isset($record['left_ps'])) {
            $point['left_ps'] = $record['left_ps'];
        }

        if (isset($record['right_ps'])) {
            $point['right_ps'] = $record['right_ps'];
        }

        if (isset($record['grit'])) {
            $point['grit'] = $record['grit'];
        }

        if (isset($record['flow'])) {
            $point['flow'] = $record['flow'];
        }

        return $point;
    }

    /**
     * Compute smoothed percentage grade for each record using a centered ~50m sliding distance window.
     *
     * @param  list<FitRecord>  $records
     * @return list<?float>
     */
    private function computeSmoothedGrades(array $records): array
    {
        $count = count($records);
        $grades = [];
        $halfWindowMeters = 25.0;
        $minSpanMeters = 10.0;

        $left = 0;
        $right = 0;

        for ($i = 0; $i < $count; $i++) {
            $dist = $records[$i]['distance'];
            $alt = $records[$i]['altitude'];

            if ($dist === null || $alt === null) {
                $grades[] = null;

                continue;
            }

            while ($left < $i) {
                $leftDist = $records[$left]['distance'];

                if ($leftDist === null || ($dist - $leftDist) > $halfWindowMeters) {
                    $left++;
                } else {
                    break;
                }
            }

            if ($right < $i) {
                $right = $i;
            }

            while ($right + 1 < $count) {
                $nextDist = $records[$right + 1]['distance'];

                if ($nextDist === null || ($nextDist - $dist) <= $halfWindowMeters) {
                    $right++;
                } else {
                    break;
                }
            }

            $leftIndex = $left;
            while ($leftIndex < $i && ($records[$leftIndex]['distance'] === null || $records[$leftIndex]['altitude'] === null)) {
                $leftIndex++;
            }

            $rightIndex = $right;
            while ($rightIndex > $i && ($records[$rightIndex]['distance'] === null || $records[$rightIndex]['altitude'] === null)) {
                $rightIndex--;
            }

            $leftDist = $records[$leftIndex]['distance'];
            $rightDist = $records[$rightIndex]['distance'];
            $leftAlt = $records[$leftIndex]['altitude'];
            $rightAlt = $records[$rightIndex]['altitude'];

            if ($leftDist === null || $rightDist === null || $leftAlt === null || $rightAlt === null) {
                $grades[] = null;

                continue;
            }

            $span = $rightDist - $leftDist;

            if ($span < $minSpanMeters) {
                $grades[] = null;

                continue;
            }

            $gradePct = (($rightAlt - $leftAlt) / $span) * 100.0;
            $gradePct = max(-40.0, min(40.0, $gradePct));
            $grades[] = round($gradePct, 1);
        }

        return $grades;
    }

    /**
     * Build a Douglas-Peucker simplified GNSS polyline capped at <= 400 points.
     *
     * @param  list<FitRecord>  $records
     * @return list<array{0: float, 1: float}>
     */
    private function buildSimplifiedPolyline(array $records): array
    {
        /** @var list<array{0: float, 1: float}> $points */
        $points = [];
        $prevLat = null;
        $prevLng = null;

        foreach ($records as $record) {
            $lat = $record['lat'];
            $lng = $record['lng'];

            if ($lat === null || $lng === null) {
                continue;
            }

            $roundedLat = round($lat, 6);
            $roundedLng = round($lng, 6);

            if ($prevLat === $roundedLat && $prevLng === $roundedLng) {
                continue;
            }

            $points[] = [$roundedLat, $roundedLng];
            $prevLat = $roundedLat;
            $prevLng = $roundedLng;
        }

        $count = count($points);

        if ($count <= 2) {
            return $points;
        }

        $epsilon = 0.00003;
        $simplified = $this->douglasPeucker($points, $epsilon);
        $iterations = 0;

        while (count($simplified) > self::MAX_POLYLINE_POINTS && $iterations < 24) {
            $epsilon *= 1.5;
            $simplified = $this->douglasPeucker($points, $epsilon);
            $iterations++;
        }

        if (count($simplified) <= self::MAX_POLYLINE_POINTS) {
            return $simplified;
        }

        $capped = [];
        $lastIndex = count($simplified) - 1;
        $maxSteps = self::MAX_POLYLINE_POINTS - 1;

        for ($step = 0; $step < self::MAX_POLYLINE_POINTS; $step++) {
            $index = (int) round(($step * $lastIndex) / $maxSteps);
            $capped[] = $simplified[$index];
        }

        return $capped;
    }

    /**
     * Iterative Douglas-Peucker line simplification.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private function douglasPeucker(array $points, float $epsilon): array
    {
        $count = count($points);

        if ($count <= 2) {
            return $points;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;

        /** @var list<array{0: int, 1: int}> $stack */
        $stack = [[0, $count - 1]];

        while ($stack !== []) {
            $segment = array_pop($stack);
            $start = $segment[0];
            $end = $segment[1];

            $maxDistance = 0.0;
            $furthestIndex = $start;

            for ($i = $start + 1; $i < $end; $i++) {
                $distance = $this->perpendicularDistance($points[$i], $points[$start], $points[$end]);

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                    $furthestIndex = $i;
                }
            }

            if ($maxDistance > $epsilon) {
                $keep[$furthestIndex] = true;
                $stack[] = [$start, $furthestIndex];
                $stack[] = [$furthestIndex, $end];
            }
        }

        $result = [];

        for ($i = 0; $i < $count; $i++) {
            if ($keep[$i]) {
                $result[] = $points[$i];
            }
        }

        return $result;
    }

    /**
     * @param  array{0: float, 1: float}  $point
     * @param  array{0: float, 1: float}  $lineStart
     * @param  array{0: float, 1: float}  $lineEnd
     */
    private function perpendicularDistance(array $point, array $lineStart, array $lineEnd): float
    {
        $dx = $lineEnd[0] - $lineStart[0];
        $dy = $lineEnd[1] - $lineStart[1];
        $lineLengthSquared = ($dx * $dx) + ($dy * $dy);

        if ($lineLengthSquared === 0.0) {
            return hypot($point[0] - $lineStart[0], $point[1] - $lineStart[1]);
        }

        $t = (($point[0] - $lineStart[0]) * $dx + ($point[1] - $lineStart[1]) * $dy) / $lineLengthSquared;
        $t = max(0.0, min(1.0, $t));

        $projX = $lineStart[0] + ($t * $dx);
        $projY = $lineStart[1] + ($t * $dy);

        return hypot($point[0] - $projX, $point[1] - $projY);
    }

    /**
     * @param  list<array<int, mixed>>  $rawLaps
     * @return list<FitLap>
     */
    private function normalizeLaps(array $rawLaps, bool $isDoubleCadenceSport = false): array
    {
        $laps = [];

        foreach ($rawLaps as $index => $raw) {
            $rawElapsed = $this->fieldNumber($raw, 7);
            $rawTimer = $this->fieldNumber($raw, 8);
            $rawDistance = $this->fieldNumber($raw, 9);
            $rawSpeed = $this->fieldNumber($raw, 110) ?? $this->fieldNumber($raw, 13);
            $rawGapSpeed = $this->fieldNumber($raw, 161);
            $rawAscent = $this->fieldNumber($raw, 21);
            $rawDescent = $this->fieldNumber($raw, 22);
            $avgCadence = $this->fieldInt($raw, 17);

            if ($isDoubleCadenceSport && $avgCadence !== null && $avgCadence > 0 && $avgCadence <= 127) {
                $fractional = $this->fieldInt($raw, 80);
                $avgCadence = (int) round(($avgCadence + (($fractional ?? 0) / 128.0)) * 2.0);
            }

            $gapSpeedMps = $rawGapSpeed !== null && $rawGapSpeed > 0.0 ? ($rawGapSpeed / 1000.0) : null;
            $gapSecondsPerKm = $gapSpeedMps !== null ? round(1000.0 / $gapSpeedMps, 2) : null;

            $laps[] = [
                'lap' => $index + 1,
                'distance_meters' => $rawDistance !== null ? round($rawDistance / 100.0, 2) : null,
                'duration_seconds' => $rawTimer !== null ? round($rawTimer / 1000.0, 2) : null,
                'elapsed_seconds' => $rawElapsed !== null ? round($rawElapsed / 1000.0, 2) : null,
                'avg_speed' => $rawSpeed !== null ? round($rawSpeed / 1000.0, 3) : null,
                'gap_seconds_per_km' => $gapSecondsPerKm,
                'avg_hr' => $this->fieldInt($raw, 15),
                'max_hr' => $this->fieldInt($raw, 16),
                'avg_cadence' => $avgCadence,
                'avg_power' => $this->fieldInt($raw, 19),
                'elevation_gain' => $rawAscent !== null ? round($rawAscent, 1) : null,
                'elevation_loss' => $rawDescent !== null ? round($rawDescent, 1) : null,
            ];
        }

        return $laps;
    }

    /**
     * Compute 1 km splits with Grade Adjusted Pace (preferring native FIT GAP stream, falling back to Minetti polynomial).
     *
     * @param  list<FitRecord>  $records
     * @return list<FitKmSplit>
     */
    private function buildKmSplits(array $records): array
    {
        /** @var list<array{time: float, distance: float, altitude: ?float, gap_speed: ?float, hr: ?int, cadence: ?int, power: ?int}> $points */
        $points = [];

        foreach ($records as $record) {
            if ($record['distance'] === null) {
                continue;
            }

            $points[] = [
                'time' => $record['time'],
                'distance' => $record['distance'],
                'altitude' => $record['altitude'],
                'gap_speed' => $record['gap_speed'] ?? null,
                'hr' => $record['hr'],
                'cadence' => $record['cadence'],
                'power' => $record['power'],
            ];
        }

        $count = count($points);

        if ($count < 2) {
            return [];
        }

        $startDistance = $points[0]['distance'];
        $endDistance = $points[$count - 1]['distance'];

        if (($endDistance - $startDistance) < 50.0) {
            return [];
        }

        $splits = [];
        $kmNumber = 1;
        $splitStartDist = $startDistance;
        $splitStartTime = $points[0]['time'];
        $splitStartAlt = $points[0]['altitude'];
        $nextBoundary = $startDistance + 1000.0;

        $elevGain = 0.0;
        $elevLoss = 0.0;
        $hrSum = 0;
        $hrCount = 0;
        $cadSum = 0;
        $cadCount = 0;
        $powerSum = 0;
        $powerCount = 0;
        $gapSpeedSum = 0.0;
        $gapSpeedCount = 0;

        $prevDist = $points[0]['distance'];
        $prevTime = $points[0]['time'];
        $prevAlt = $points[0]['altitude'];

        for ($i = 1; $i < $count; $i++) {
            $curr = $points[$i];
            $currDist = $curr['distance'];
            $currTime = $curr['time'];
            $currAlt = $curr['altitude'];

            if ($currDist < $prevDist) {
                continue;
            }

            $segStartDist = $prevDist;
            $segStartTime = $prevTime;
            $segStartAlt = $prevAlt;

            while ($currDist >= $nextBoundary && $currDist > $segStartDist) {
                $fraction = ($nextBoundary - $segStartDist) / ($currDist - $segStartDist);
                $crossTime = $segStartTime + ($fraction * ($currTime - $segStartTime));
                $crossAlt = ($segStartAlt !== null && $currAlt !== null)
                    ? $segStartAlt + ($fraction * ($currAlt - $segStartAlt))
                    : ($currAlt ?? $segStartAlt);

                if ($segStartAlt !== null && $crossAlt !== null) {
                    $deltaAlt = $crossAlt - $segStartAlt;

                    if ($deltaAlt > 0.0) {
                        $elevGain += $deltaAlt;
                    } elseif ($deltaAlt < 0.0) {
                        $elevLoss += abs($deltaAlt);
                    }
                }

                if ($hrCount === 0 && $curr['hr'] !== null) {
                    $hrSum += $curr['hr'];
                    $hrCount++;
                }

                if ($cadCount === 0 && $curr['cadence'] !== null) {
                    $cadSum += $curr['cadence'];
                    $cadCount++;
                }

                if ($powerCount === 0 && $curr['power'] !== null) {
                    $powerSum += $curr['power'];
                    $powerCount++;
                }

                if ($gapSpeedCount === 0 && $curr['gap_speed'] !== null && $curr['gap_speed'] > 0.0) {
                    $gapSpeedSum += $curr['gap_speed'];
                    $gapSpeedCount++;
                }

                $splits[] = $this->finalizeKmSplit(
                    km: $kmNumber,
                    distanceMeters: $nextBoundary - $splitStartDist,
                    durationSeconds: $crossTime - $splitStartTime,
                    startAltitude: $splitStartAlt,
                    endAltitude: $crossAlt,
                    elevationGain: $elevGain,
                    elevationLoss: $elevLoss,
                    avgHr: $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
                    avgCadence: $cadCount > 0 ? (int) round($cadSum / $cadCount) : null,
                    avgPower: $powerCount > 0 ? (int) round($powerSum / $powerCount) : null,
                    nativeAvgGapSpeed: $gapSpeedCount > 0 ? ($gapSpeedSum / $gapSpeedCount) : null,
                );

                $kmNumber++;
                $splitStartDist = $nextBoundary;
                $splitStartTime = $crossTime;
                $splitStartAlt = $crossAlt;
                $nextBoundary += 1000.0;

                $segStartDist = $splitStartDist;
                $segStartTime = $crossTime;
                $segStartAlt = $crossAlt;

                $elevGain = 0.0;
                $elevLoss = 0.0;
                $hrSum = 0;
                $hrCount = 0;
                $cadSum = 0;
                $cadCount = 0;
                $powerSum = 0;
                $powerCount = 0;
                $gapSpeedSum = 0.0;
                $gapSpeedCount = 0;
            }

            if ($segStartAlt !== null && $currAlt !== null) {
                $deltaAlt = $currAlt - $segStartAlt;

                if ($deltaAlt > 0.0) {
                    $elevGain += $deltaAlt;
                } elseif ($deltaAlt < 0.0) {
                    $elevLoss += abs($deltaAlt);
                }
            }

            if ($splitStartAlt === null && $currAlt !== null) {
                $splitStartAlt = $currAlt;
            }

            if ($curr['hr'] !== null) {
                $hrSum += $curr['hr'];
                $hrCount++;
            }

            if ($curr['cadence'] !== null) {
                $cadSum += $curr['cadence'];
                $cadCount++;
            }

            if ($curr['power'] !== null) {
                $powerSum += $curr['power'];
                $powerCount++;
            }

            if ($curr['gap_speed'] !== null && $curr['gap_speed'] > 0.0) {
                $gapSpeedSum += $curr['gap_speed'];
                $gapSpeedCount++;
            }

            $prevDist = $currDist;
            $prevTime = $currTime;
            $prevAlt = $currAlt;
        }

        $remainingDistance = $prevDist - $splitStartDist;

        if ($remainingDistance >= 50.0) {
            $splits[] = $this->finalizeKmSplit(
                km: $kmNumber,
                distanceMeters: $remainingDistance,
                durationSeconds: $prevTime - $splitStartTime,
                startAltitude: $splitStartAlt,
                endAltitude: $prevAlt,
                elevationGain: $elevGain,
                elevationLoss: $elevLoss,
                avgHr: $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
                avgCadence: $cadCount > 0 ? (int) round($cadSum / $cadCount) : null,
                avgPower: $powerCount > 0 ? (int) round($powerSum / $powerCount) : null,
                nativeAvgGapSpeed: $gapSpeedCount > 0 ? ($gapSpeedSum / $gapSpeedCount) : null,
            );
        }

        return $splits;
    }

    /**
     * @return FitKmSplit
     */
    private function finalizeKmSplit(
        int $km,
        float $distanceMeters,
        float $durationSeconds,
        ?float $startAltitude,
        ?float $endAltitude,
        float $elevationGain,
        float $elevationLoss,
        ?int $avgHr,
        ?int $avgCadence,
        ?int $avgPower,
        ?float $nativeAvgGapSpeed = null,
    ): array {
        $safeDuration = max(0.0, $durationSeconds);
        $avgSpeed = $safeDuration > 0.0 ? ($distanceMeters / $safeDuration) : 0.0;
        $paceSecondsPerKm = $avgSpeed > 0.0 ? (1000.0 / $avgSpeed) : 0.0;

        $hasAltitude = $startAltitude !== null && $endAltitude !== null && $distanceMeters > 0.0;
        $gradeFraction = $hasAltitude ? (($endAltitude - $startAltitude) / $distanceMeters) : 0.0;
        $avgGradePct = $hasAltitude ? ($gradeFraction * 100.0) : 0.0;

        $gapSecondsPerKm = null;

        if ($nativeAvgGapSpeed !== null && $nativeAvgGapSpeed > 0.0) {
            $gapSecondsPerKm = round(1000.0 / $nativeAvgGapSpeed, 2);
        } elseif ($hasAltitude && $avgSpeed > 0.0) {
            $clampedGrade = max(-0.30, min(0.30, $gradeFraction));
            $energyCost = $this->minettiEnergyCost($clampedGrade);
            $gapSpeed = $avgSpeed * ($energyCost / self::MINETTI_FLAT_COST);

            if ($gapSpeed > 0.0) {
                $gapSecondsPerKm = round(1000.0 / $gapSpeed, 2);
            }
        }

        return [
            'km' => $km,
            'distance_meters' => round($distanceMeters, 2),
            'duration_seconds' => round($safeDuration, 2),
            'avg_speed' => round($avgSpeed, 3),
            'pace_seconds_per_km' => round($paceSecondsPerKm, 2),
            'gap_seconds_per_km' => $gapSecondsPerKm,
            'avg_hr' => $avgHr,
            'avg_cadence' => $avgCadence,
            'avg_power' => $avgPower,
            'elevation_gain' => round($elevationGain, 1),
            'elevation_loss' => round($elevationLoss, 1),
            'avg_grade' => round($avgGradePct, 2),
        ];
    }

    /**
     * Normalize Garmin Run/Walk/Idle split messages (MSG 312).
     *
     * @param  list<array<int, mixed>>  $rawSplits
     * @return list<FitRunWalkSegment>
     */
    private function normalizeRunWalkSegments(array $rawSplits, ?int $firstUnixTimestamp): array
    {
        if ($rawSplits === [] || $firstUnixTimestamp === null) {
            return [];
        }

        $typeMap = [
            17 => 'run',
            18 => 'walk',
            28 => 'idle',
        ];

        $segments = [];

        foreach ($rawSplits as $raw) {
            $splitType = $this->fieldInt($raw, 0);

            if ($splitType === null || ! isset($typeMap[$splitType])) {
                continue;
            }

            $rawStartTs = $this->fieldInt($raw, 9);
            $rawEndTs = $this->fieldInt($raw, 27);
            $rawTimer = $this->fieldNumber($raw, 2);
            $rawElapsed = $this->fieldNumber($raw, 1);
            $rawDistance = $this->fieldNumber($raw, 3);

            $startTime = $rawStartTs !== null
                ? (float) max(0, ($rawStartTs + self::FIT_EPOCH_OFFSET) - $firstUnixTimestamp)
                : 0.0;

            $duration = $rawTimer !== null
                ? round($rawTimer / 1000.0, 2)
                : ($rawElapsed !== null ? round($rawElapsed / 1000.0, 2) : 0.0);

            $endTime = $rawEndTs !== null
                ? (float) max($startTime, ($rawEndTs + self::FIT_EPOCH_OFFSET) - $firstUnixTimestamp)
                : round($startTime + $duration, 2);

            if ($duration <= 0.0 && $endTime <= $startTime) {
                continue;
            }

            $segments[] = [
                'type' => $typeMap[$splitType],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_seconds' => $duration > 0.0 ? $duration : round($endTime - $startTime, 2),
                'distance_meters' => $rawDistance !== null ? round($rawDistance / 100.0, 2) : 0.0,
            ];
        }

        usort(
            $segments,
            static fn (array $a, array $b): int => $a['start_time'] <=> $b['start_time'],
        );

        return $segments;
    }

    /**
     * Normalize MTB jump events (MSG 285).
     *
     * @param  list<array<int, mixed>>  $rawJumps
     * @return list<FitJumpEvent>
     */
    private function normalizeJumps(array $rawJumps, ?int $firstUnixTimestamp): array
    {
        if ($rawJumps === []) {
            return [];
        }

        $jumps = [];

        foreach ($rawJumps as $raw) {
            $fitTs = $this->fieldInt($raw, 253);
            $time = ($fitTs !== null && $firstUnixTimestamp !== null)
                ? (float) max(0, ($fitTs + self::FIT_EPOCH_OFFSET) - $firstUnixTimestamp)
                : 0.0;

            $distance = $this->fieldNumber($raw, 0);
            $hangTime = $this->fieldNumber($raw, 3);
            $score = $this->fieldNumber($raw, 4);

            if ($distance === null && $hangTime === null) {
                continue;
            }

            $rawLat = $this->fieldInt($raw, 5);
            $rawLng = $this->fieldInt($raw, 6);
            $lat = $rawLat !== null ? round($rawLat * self::SEMICIRCLE_TO_DEGREES, 7) : null;
            $lng = $rawLng !== null ? round($rawLng * self::SEMICIRCLE_TO_DEGREES, 7) : null;

            if ($lat !== null && ($lat < -90.0 || $lat > 90.0)) {
                $lat = null;
            }

            if ($lng !== null && ($lng < -180.0 || $lng > 180.0)) {
                $lng = null;
            }

            $rawSpeed = $this->fieldNumber($raw, 7);

            $jumps[] = [
                'time' => $time,
                'distance_meters' => round($distance ?? 0.0, 2),
                'hang_time_seconds' => round($hangTime ?? 0.0, 2),
                'score' => round($score ?? 0.0, 1),
                'speed' => $rawSpeed !== null && $rawSpeed > 0.0 ? round($rawSpeed / 1000.0, 2) : null,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        return $jumps;
    }

    /**
     * Extract extended session-level summary telemetry from FIT session, time-in-zone, workout, and split messages.
     *
     * @param  array<int, mixed>|null  $session
     * @param  list<FitRecord>  $records
     * @param  list<array<int, mixed>>  $timeInZones
     * @param  array<int, mixed>|null  $workout
     * @param  list<array<int, mixed>>  $workoutSteps
     * @param  list<array<int, mixed>>  $rawSplitSummaries
     * @param  list<FitRunWalkSegment>  $runWalkSegments
     * @return array<string, mixed>
     */
    private function buildSessionSummary(
        ?array $session,
        array $records,
        array $timeInZones,
        ?array $workout,
        array $workoutSteps,
        array $rawSplitSummaries,
        array $runWalkSegments,
    ): array {
        $summary = [];

        if ($session !== null) {
            $beginStamina = $this->fieldInt($session, 205);
            if ($beginStamina !== null && $beginStamina <= 100) {
                $summary['begin_stamina'] = $beginStamina;
            }

            $endStamina = $this->fieldInt($session, 206);
            if ($endStamina !== null && $endStamina <= 100) {
                $summary['end_stamina'] = $endStamina;
            }

            $minStamina = $this->fieldInt($session, 207);
            if ($minStamina !== null && $minStamina <= 100) {
                $summary['min_stamina'] = $minStamina;
            }

            $feel = $this->fieldInt($session, 192);
            if ($feel !== null && $feel <= 100) {
                $summary['workout_feel'] = $feel;
            }

            $rpe = $this->fieldInt($session, 193);
            if ($rpe !== null && $rpe <= 100) {
                $summary['workout_rpe'] = $rpe;
            }

            $execScore = $this->fieldInt($session, 185);
            if ($execScore !== null && $execScore <= 100) {
                $summary['execution_score'] = $execScore;
            }

            $beginBb = $this->fieldInt($session, 215);
            if ($beginBb !== null && $beginBb <= 100) {
                $summary['begin_body_battery'] = $beginBb;
            }

            $endBb = $this->fieldInt($session, 216);
            if ($endBb !== null && $endBb <= 100) {
                $summary['end_body_battery'] = $endBb;
            }

            $rawGap = $this->fieldNumber($session, 211);
            if ($rawGap !== null && $rawGap > 0.0) {
                $summary['avg_grade_adjusted_speed'] = round($rawGap / 1000.0, 3);
            }

            $rawVertOsc = $this->fieldNumber($session, 89);
            if ($rawVertOsc !== null && $rawVertOsc > 0.0) {
                $summary['avg_vertical_oscillation'] = round($rawVertOsc / 100.0, 2);
            }

            $rawVertRatio = $this->fieldNumber($session, 132);
            if ($rawVertRatio !== null && $rawVertRatio > 0.0) {
                $summary['avg_vertical_ratio'] = round($rawVertRatio / 100.0, 2);
            }

            $rawGct = $this->fieldNumber($session, 91);
            if ($rawGct !== null && $rawGct > 0.0) {
                $summary['avg_ground_contact_time'] = round($rawGct / 10.0, 1);
            }

            $bmr = $this->fieldInt($session, 196);
            if ($bmr !== null && $bmr >= 0) {
                $summary['bmr_calories'] = $bmr;
            }

            $sweat = $this->fieldInt($session, 178);
            if ($sweat !== null && $sweat > 0) {
                $summary['sweat_loss_ml'] = $sweat;
            }

            $avgResp = $this->fieldNumber($session, 169);
            if ($avgResp !== null && $avgResp > 0.0) {
                $summary['avg_respiration_rate'] = round($avgResp / 100.0, 2);
            }

            $maxResp = $this->fieldNumber($session, 170);
            if ($maxResp !== null && $maxResp > 0.0) {
                $summary['max_respiration_rate'] = round($maxResp / 100.0, 2);
            }

            $minResp = $this->fieldNumber($session, 180);
            if ($minResp !== null && $minResp > 0.0) {
                $summary['min_respiration_rate'] = round($minResp / 100.0, 2);
            }

            $avgTemp = $this->fieldNumber($session, 57);
            if ($avgTemp !== null && $avgTemp >= -50.0 && $avgTemp <= 80.0) {
                $summary['avg_temperature'] = round($avgTemp, 1);
            }

            $maxTemp = $this->fieldNumber($session, 58);
            if ($maxTemp !== null && $maxTemp >= -50.0 && $maxTemp <= 80.0) {
                $summary['max_temperature'] = round($maxTemp, 1);
            }

            $minTemp = $this->fieldNumber($session, 150);
            if ($minTemp !== null && $minTemp >= -50.0 && $minTemp <= 80.0) {
                $summary['min_temperature'] = round($minTemp, 1);
            }

            $wind = $this->fieldInt($session, 212);
            if ($wind !== null && ($wind === 0 || $wind === 1)) {
                $summary['wind_enabled'] = $wind === 1;
            }

            $totalWork = $this->fieldNumber($session, 48);
            if ($totalWork !== null && $totalWork > 0.0) {
                $summary['total_work'] = round($totalWork, 1);
            }

            $leftTe = $this->fieldNumber($session, 101);
            if ($leftTe !== null && $leftTe <= 200.0) {
                $summary['avg_left_torque_effectiveness'] = round($leftTe / 2.0, 1);
            }

            $rightTe = $this->fieldNumber($session, 102);
            if ($rightTe !== null && $rightTe <= 200.0) {
                $summary['avg_right_torque_effectiveness'] = round($rightTe / 2.0, 1);
            }

            $leftPs = $this->fieldNumber($session, 103);
            if ($leftPs !== null && $leftPs <= 200.0) {
                $summary['avg_left_pedal_smoothness'] = round($leftPs / 2.0, 1);
            }

            $rightPs = $this->fieldNumber($session, 104);
            if ($rightPs !== null && $rightPs <= 200.0) {
                $summary['avg_right_pedal_smoothness'] = round($rightPs / 2.0, 1);
            }
        }

        // Compute decimal avg temperature from records when available (session f57 is truncated int °C)
        $tempSum = 0.0;
        $tempCount = 0;

        foreach ($records as $record) {
            if (isset($record['temperature'])) {
                $tempSum += $record['temperature'];
                $tempCount++;
            }
        }

        if ($tempCount > 0) {
            $summary['avg_temperature'] = round($tempSum / $tempCount, 1);
        }

        // Time in Zone (MSG 216) for session (reference_mesg = 18): threshold power & power zone boundaries
        foreach ($timeInZones as $tiz) {
            if ($this->fieldInt($tiz, 0) !== self::MSG_SESSION) {
                continue;
            }

            $thresholdPower = $this->fieldNumber($tiz, 15);
            if ($thresholdPower !== null && $thresholdPower > 0.0 && $thresholdPower < 2000.0) {
                $summary['threshold_power'] = round($thresholdPower, 1);
            }

            if (isset($tiz[9]) && is_array($tiz[9])) {
                $bounds = [];

                foreach ($tiz[9] as $val) {
                    if (is_int($val) && $val > 0 && $val < 3500) {
                        $bounds[] = $val;
                    }
                }

                if ($bounds !== []) {
                    $summary['power_zone_bounds'] = $bounds;
                }
            }

            break;
        }

        // Structured workout metadata (MSG 26 & 27)
        if ($workout !== null) {
            $meta = [];

            if (isset($workout[8]) && is_string($workout[8]) && trim($workout[8]) !== '') {
                $meta['name'] = trim($workout[8]);
            }

            if (isset($workout[17]) && is_string($workout[17]) && trim($workout[17]) !== '') {
                $meta['target_label'] = trim($workout[17]);
            }

            $workoutDuration = $this->fieldNumber($workout, 10);
            if ($workoutDuration !== null && $workoutDuration > 0.0) {
                $meta['duration_seconds'] = round($workoutDuration / 1000.0, 1);
            }

            if ($workoutSteps !== []) {
                $firstStep = $workoutSteps[0];
                $low = $this->fieldInt($firstStep, 5);
                $high = $this->fieldInt($firstStep, 6);

                if ($low !== null && $high !== null && $low > 0 && $high > $low && $high < 260) {
                    $meta['target_hr_low'] = $low;
                    $meta['target_hr_high'] = $high;
                }
            }

            if ($meta !== []) {
                $summary['workout_meta'] = $meta;
            }
        }

        // Run/Walk/Idle durations from MSG 313 split summaries (or fallback sum of MSG 312 segments)
        foreach ($rawSplitSummaries as $splitSummary) {
            $splitType = $this->fieldInt($splitSummary, 0);
            $rawDuration = $this->fieldNumber($splitSummary, 4);

            if ($rawDuration === null || $rawDuration < 0.0) {
                continue;
            }

            $durationSec = round($rawDuration / 1000.0, 2);

            if ($splitType === 17) {
                $summary['run_duration_seconds'] = $durationSec;
            } elseif ($splitType === 18) {
                $summary['walk_duration_seconds'] = $durationSec;
            } elseif ($splitType === 28) {
                $summary['idle_duration_seconds'] = $durationSec;
            }
        }

        if (! isset($summary['run_duration_seconds']) && $runWalkSegments !== []) {
            $totals = ['run' => 0.0, 'walk' => 0.0, 'idle' => 0.0];

            foreach ($runWalkSegments as $segment) {
                if (isset($totals[$segment['type']])) {
                    $totals[$segment['type']] += $segment['duration_seconds'];
                }
            }

            if ($totals['run'] > 0.0) {
                $summary['run_duration_seconds'] = round($totals['run'], 2);
            }

            if ($totals['walk'] > 0.0) {
                $summary['walk_duration_seconds'] = round($totals['walk'], 2);
            }

            if ($totals['idle'] > 0.0) {
                $summary['idle_duration_seconds'] = round($totals['idle'], 2);
            }
        }

        return $summary;
    }

    /**
     * Extract standalone FIT session and file_id metadata in SI units.
     *
     * Kept separate from sessionSummary so stream sync never collides with provider summary columns.
     *
     * @param  array<int, mixed>|null  $session
     * @param  array<int, mixed>|null  $fileId
     * @return array<string, mixed>
     */
    private function buildSessionMetadata(?array $session, ?array $fileId): array
    {
        $metadata = [];

        if ($session !== null) {
            $isDoubleCadenceSport = $this->isDoubleCadenceSport($session);

            $sportId = $this->validUnsignedInt($session, 5, 0xFF);
            if ($sportId !== null) {
                $metadata['sport_id'] = $sportId;
            }

            $subSportId = $this->validUnsignedInt($session, 6, 0xFF);
            if ($subSportId !== null) {
                $metadata['sub_sport_id'] = $subSportId;
            }

            $startTime = $this->validUnsignedInt($session, 2, 0xFFFFFFFF);
            if ($startTime !== null) {
                $metadata['start_timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z', $startTime + self::FIT_EPOCH_OFFSET);
            }

            $rawElapsed = $this->validUnsignedNumber($session, 7, 0xFFFFFFFF);
            if ($rawElapsed !== null) {
                $metadata['total_elapsed_time'] = round($rawElapsed / 1000.0, 3);
            }

            $rawTimer = $this->validUnsignedNumber($session, 8, 0xFFFFFFFF);
            if ($rawTimer !== null) {
                $metadata['total_timer_time'] = round($rawTimer / 1000.0, 3);
            }

            $rawDistance = $this->validUnsignedNumber($session, 9, 0xFFFFFFFF);
            if ($rawDistance !== null) {
                $metadata['total_distance'] = round($rawDistance / 100.0, 2);
            }

            $totalCalories = $this->validUnsignedInt($session, 11, 0xFFFF);
            if ($totalCalories !== null) {
                $metadata['total_calories'] = $totalCalories;
            }

            $avgHr = $this->validUnsignedInt($session, 16, 0xFF);
            if ($avgHr !== null) {
                $metadata['avg_hr'] = $avgHr;
            }

            $maxHr = $this->validUnsignedInt($session, 17, 0xFF);
            if ($maxHr !== null) {
                $metadata['max_hr'] = $maxHr;
            }

            $rawAvgSpeed = $this->validUnsignedNumber($session, 124, 0xFFFFFFFF)
                ?? $this->validUnsignedNumber($session, 14, 0xFFFF);
            if ($rawAvgSpeed !== null) {
                $metadata['avg_speed'] = round($rawAvgSpeed / 1000.0, 3);
            }

            $rawMaxSpeed = $this->validUnsignedNumber($session, 125, 0xFFFFFFFF)
                ?? $this->validUnsignedNumber($session, 15, 0xFFFF);
            if ($rawMaxSpeed !== null) {
                $metadata['max_speed'] = round($rawMaxSpeed / 1000.0, 3);
            }

            $rawAscent = $this->validUnsignedNumber($session, 22, 0xFFFF);
            if ($rawAscent !== null) {
                $metadata['total_ascent'] = round($rawAscent, 1);
            }

            $rawDescent = $this->validUnsignedNumber($session, 23, 0xFFFF);
            if ($rawDescent !== null) {
                $metadata['total_descent'] = round($rawDescent, 1);
            }

            $rawAvgPower = $this->validUnsignedNumber($session, 20, 0xFFFF);
            if ($rawAvgPower !== null) {
                $metadata['avg_power'] = round($rawAvgPower, 1);
            }

            $rawMaxPower = $this->validUnsignedNumber($session, 21, 0xFFFF);
            if ($rawMaxPower !== null) {
                $metadata['max_power'] = round($rawMaxPower, 1);
            }

            $rawAvgCadence = $this->validUnsignedInt($session, 18, 0xFF);
            if ($rawAvgCadence !== null) {
                $fractional = $this->validUnsignedInt($session, 92, 0xFF);
                $cadence = $rawAvgCadence + (($fractional ?? 0) / 128.0);

                if ($isDoubleCadenceSport && $rawAvgCadence <= 127) {
                    $cadence *= 2.0;
                }

                $metadata['avg_cadence'] = round($cadence, 2);
            }

            $rawMaxCadence = $this->validUnsignedInt($session, 19, 0xFF);
            if ($rawMaxCadence !== null) {
                $fractional = $this->validUnsignedInt($session, 93, 0xFF);
                $cadence = $rawMaxCadence + (($fractional ?? 0) / 128.0);

                if ($isDoubleCadenceSport && $rawMaxCadence <= 127) {
                    $cadence *= 2.0;
                }

                $metadata['max_cadence'] = round($cadence, 2);
            }
        }

        if ($fileId !== null) {
            $manufacturerId = $this->validUnsignedInt($fileId, 1, 0xFFFF);
            if ($manufacturerId !== null) {
                $metadata['manufacturer_id'] = $manufacturerId;
            }

            $productId = $this->validUnsignedInt($fileId, 2, 0xFFFF);
            if ($productId !== null) {
                $metadata['product_id'] = $productId;
            }
        }

        return $metadata;
    }

    /**
     * Minetti's energy cost of running polynomial C(i) in J/(kg*m).
     */
    private function minettiEnergyCost(float $grade): float
    {
        $g2 = $grade * $grade;
        $g3 = $g2 * $grade;
        $g4 = $g3 * $grade;
        $g5 = $g4 * $grade;

        return (155.4 * $g5)
            - (30.4 * $g4)
            - (43.3 * $g3)
            + (46.3 * $g2)
            + (19.5 * $grade)
            + self::MINETTI_FLAT_COST;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = (sin($deltaPhi / 2.0) ** 2)
            + (cos($phi1) * cos($phi2) * (sin($deltaLambda / 2.0) ** 2));

        $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function validUnsignedInt(array $fields, int $fieldNum, int $invalidSentinel): ?int
    {
        $value = $this->fieldInt($fields, $fieldNum);

        if ($value === null || $value < 0 || $value >= $invalidSentinel) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function validUnsignedNumber(array $fields, int $fieldNum, int $invalidSentinel): ?float
    {
        $value = $this->fieldNumber($fields, $fieldNum);

        if ($value === null || $value < 0.0 || $value >= (float) $invalidSentinel) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function fieldInt(array $fields, int $fieldNum): ?int
    {
        if (! isset($fields[$fieldNum])) {
            return null;
        }

        $value = $fields[$fieldNum];

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function fieldNumber(array $fields, int $fieldNum): ?float
    {
        if (! isset($fields[$fieldNum])) {
            return null;
        }

        $value = $fields[$fieldNum];

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return null;
    }
}

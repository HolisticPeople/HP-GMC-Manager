<?php
namespace HP_GMC\Abilities;

use HP_GMC\Services\GoogleAdsAudienceUpload;
use HP_GMC\Services\SavedSegmentsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP abilities for Audiences (saved segments): list, get, run, save, duplicate, export-csv, upload.
 */
class AudiencesAbilities
{
    /**
     * List saved segments.
     *
     * @param array $params Unused
     * @return array{segments: array, count: int}
     */
    public static function segmentsList(array $params): array
    {
        $repo = new SavedSegmentsRepository();
        $segments = $repo->list_all();
        return [
            'segments' => $segments,
            'count' => count($segments),
        ];
    }

    /**
     * Get one segment by id.
     *
     * @param array $params segment_id (required)
     * @return array
     */
    public static function segmentGet(array $params): array
    {
        $id = (int) ($params['segment_id'] ?? 0);
        if (!$id) {
            return ['success' => false, 'error' => 'segment_id is required'];
        }
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return ['success' => false, 'error' => 'Segment not found'];
        }
        return array_merge(['success' => true], $seg);
    }

    /**
     * Run segment by id; returns count and updates last_run.
     *
     * @param array $params segment_id (required)
     * @return array{success: bool, count?: int, error?: string}
     */
    public static function segmentRun(array $params): array
    {
        $id = (int) ($params['segment_id'] ?? 0);
        if (!$id) {
            return ['success' => false, 'error' => 'segment_id is required'];
        }
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return ['success' => false, 'error' => 'Segment not found'];
        }
        $def = json_decode($seg['filter_definition'], true);
        if (!is_array($def)) {
            return ['success' => false, 'error' => 'Invalid filter definition'];
        }
        $result = self::runDefinition($def);
        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }
        $repo->set_last_run($id, $result['count']);
        return [
            'success' => true,
            'count' => $result['count'],
            'segment_id' => $id,
        ];
    }

    /**
     * Create or update saved segment.
     *
     * @param array $params name (required), filter_definition (required for create), segment_id (for update)
     * @return array
     */
    public static function segmentSave(array $params): array
    {
        $name = isset($params['name']) ? trim((string) $params['name']) : '';
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }
        $def = $params['filter_definition'] ?? null;
        if ($def === null) {
            return ['success' => false, 'error' => 'filter_definition is required'];
        }
        $decoded = is_string($def) ? json_decode($def, true) : $def;
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'filter_definition must be valid JSON'];
        }
        $repo = new SavedSegmentsRepository();
        $segment_id = (int) ($params['segment_id'] ?? 0);
        if ($segment_id) {
            $seg = $repo->get($segment_id);
            if (!$seg) {
                return ['success' => false, 'error' => 'Segment not found'];
            }
            $existing = $repo->get_by_name($name);
            if ($existing && (int) $existing['id'] !== $segment_id) {
                return ['success' => false, 'error' => 'Another segment already has this name'];
            }
            $repo->update($segment_id, ['name' => $name, 'filter_definition' => wp_json_encode($decoded)]);
            return ['success' => true, 'segment_id' => $segment_id, 'message' => 'Segment updated'];
        }
        if ($repo->get_by_name($name) !== null) {
            return ['success' => false, 'error' => 'A segment with this name already exists'];
        }
        $id = $repo->create($name, wp_json_encode($decoded));
        if ($id === false) {
            return ['success' => false, 'error' => 'Failed to create segment'];
        }
        return ['success' => true, 'segment_id' => $id, 'message' => 'Segment created'];
    }

    /**
     * Duplicate a segment.
     *
     * @param array $params segment_id (required), name (optional)
     * @return array
     */
    public static function segmentDuplicate(array $params): array
    {
        $id = (int) ($params['segment_id'] ?? 0);
        if (!$id) {
            return ['success' => false, 'error' => 'segment_id is required'];
        }
        $new_name = isset($params['name']) ? trim((string) $params['name']) : null;
        $repo = new SavedSegmentsRepository();
        $new_id = $repo->duplicate($id, $new_name);
        if ($new_id === false) {
            return ['success' => false, 'error' => 'Segment not found or duplicate failed'];
        }
        return ['success' => true, 'segment_id' => $new_id, 'message' => 'Segment duplicated'];
    }

    /**
     * Run segment and return CSV content (Google Customer Match format) and count.
     *
     * @param array $params segment_id (required)
     * @return array{success: bool, csv?: string, count?: int, filename?: string, error?: string}
     */
    public static function segmentExportCsv(array $params): array
    {
        $id = (int) ($params['segment_id'] ?? 0);
        if (!$id) {
            return ['success' => false, 'error' => 'segment_id is required'];
        }
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return ['success' => false, 'error' => 'Segment not found'];
        }
        $def = json_decode($seg['filter_definition'], true);
        if (!is_array($def)) {
            return ['success' => false, 'error' => 'Invalid filter definition'];
        }
        $result = self::runDefinition($def);
        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }
        $rows = $result['rows'] ?? [];
        $csv = self::buildGoogleCsv($rows);
        $repo->set_last_run($id, count($rows));
        return [
            'success' => true,
            'csv' => $csv,
            'count' => count($rows),
            'filename' => 'audience-segment-' . $id . '-' . gmdate('Y-m-d') . '.csv',
        ];
    }

    /**
     * Upload segment to Google Ads Customer Match. Run segment and push to Google Ads (append or replace).
     *
     * @param array $params segment_id (required), append (optional, default false = replace)
     * @return array{success: bool, job_resource_name?: string, count?: int, error?: string}
     */
    public static function segmentUpload(array $params): array
    {
        if ((bool) get_option('hp_gmc_audience_upload_disabled', false)) {
            return ['success' => false, 'error' => 'Upload to Google Ads is disabled in Settings.'];
        }
        $id = (int) ($params['segment_id'] ?? 0);
        if (!$id) {
            return ['success' => false, 'error' => 'segment_id is required'];
        }
        $append = !empty($params['append']);
        $result = GoogleAdsAudienceUpload::upload($id, $append, null);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Upload failed'];
        }
        return [
            'success' => true,
            'job_resource_name' => $result['job_resource_name'] ?? null,
            'user_list_resource_name' => $result['user_list_resource_name'] ?? null,
            'count' => $result['count'] ?? 0,
        ];
    }

    private static function runDefinition(array $def): array
    {
        if (!class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)) {
            return ['error' => 'Segment engine not available (HP Abilities plugin required).', 'count' => 0, 'rows' => []];
        }
        $engine = new \HP_Abilities\Services\SegmentFilterEngine();
        $out = $engine->run($def, null);
        return ['count' => $out['count'], 'rows' => $out['rows']];
    }

    private static function buildGoogleCsv(array $rows): string
    {
        $header = ['Email', 'Phone', 'First name', 'Last name', 'Country', 'Zip'];
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($c) {
                $c = (string) $c;
                if (strpos($c, ',') !== false || strpos($c, '"') !== false || strpos($c, "\n") !== false) {
                    return '"' . str_replace('"', '""', $c) . '"';
                }
                return $c;
            }, [
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['first_name'] ?? '',
                $row['last_name'] ?? '',
                $row['country'] ?? '',
                $row['zip'] ?? '',
            ]));
        }
        return implode("\n", $lines);
    }
}

<?php
namespace HP_GMC\Rest;

use HP_GMC\Services\GoogleAdsAudienceUpload;
use HP_GMC\Services\SavedSegmentsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API for Audiences (saved segments): list, get, create, update, delete, duplicate, run, export CSV.
 * Permission: manage_woocommerce.
 */
class AudiencesEndpoint
{
    public static function permission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function register(): void
    {
        $namespace = 'hp-gmc/v1';

        register_rest_route($namespace, '/audiences/segments', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_segments'],
            'permission_callback' => [self::class, 'permission'],
        ]);

        register_rest_route($namespace, '/audiences/segments', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'filter_definition' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'filter_definition' => ['type' => 'string'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'delete_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)/duplicate', [
            'methods' => 'POST',
            'callback' => [self::class, 'duplicate_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)/run', [
            'methods' => 'POST',
            'callback' => [self::class, 'run_segment'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'progress_key' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/run-definition', [
            'methods' => 'POST',
            'callback' => [self::class, 'run_definition'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'filter_definition' => ['required' => true],
                'progress_key' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/run-progress', [
            'methods' => 'GET',
            'callback' => [self::class, 'run_progress'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'progress_key' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)/export-csv', [
            'methods' => 'GET',
            'callback' => [self::class, 'export_csv'],
            'permission_callback' => [self::class, 'permission'],
            'args' => ['id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)/upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'upload'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'append' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/(?P<id>\d+)/upload-status', [
            'methods' => 'GET',
            'callback' => [self::class, 'upload_status'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'job_resource_name' => ['type' => 'string', 'description' => 'Optional job resource name to check; defaults to segment’s last upload job'],
            ],
        ]);
    }

    public static function list_segments(WP_REST_Request $request): WP_REST_Response
    {
        $repo = new SavedSegmentsRepository();
        $list = $repo->list_all();
        return new WP_REST_Response(['segments' => $list], 200);
    }

    public static function create_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = trim($request->get_param('name'));
        $filter_definition = $request->get_param('filter_definition');
        if ($name === '') {
            return new WP_Error('invalid_name', 'Segment name is required.', ['status' => 400]);
        }
        $def = is_string($filter_definition) ? json_decode($filter_definition, true) : $filter_definition;
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', 'filter_definition must be valid JSON.', ['status' => 400]);
        }
        $repo = new SavedSegmentsRepository();
        if ($repo->get_by_name($name) !== null) {
            return new WP_Error('duplicate_name', 'A segment with this name already exists.', ['status' => 409]);
        }
        $id = $repo->create($name, wp_json_encode($def));
        if ($id === false) {
            return new WP_Error('create_failed', 'Failed to create segment.', ['status' => 500]);
        }
        $seg = $repo->get($id);
        return new WP_REST_Response($seg, 201);
    }

    public static function get_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        return new WP_REST_Response($seg, 200);
    }

    public static function update_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        $data = [];
        if ($request->has_param('name')) {
            $name = trim($request->get_param('name'));
            if ($name === '') {
                return new WP_Error('invalid_name', 'Segment name cannot be empty.', ['status' => 400]);
            }
            $existing = $repo->get_by_name($name);
            if ($existing && (int) $existing['id'] !== $id) {
                return new WP_Error('duplicate_name', 'Another segment already has this name.', ['status' => 409]);
            }
            $data['name'] = $name;
        }
        if ($request->has_param('filter_definition')) {
            $def = $request->get_param('filter_definition');
            $decoded = is_string($def) ? json_decode($def, true) : $def;
            if (!is_array($decoded)) {
                return new WP_Error('invalid_definition', 'filter_definition must be valid JSON.', ['status' => 400]);
            }
            $data['filter_definition'] = wp_json_encode($decoded);
        }
        if (!empty($data) && !$repo->update($id, $data)) {
            return new WP_Error('update_failed', 'Failed to update segment.', ['status' => 500]);
        }
        return new WP_REST_Response($repo->get($id), 200);
    }

    public static function delete_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repo = new SavedSegmentsRepository();
        if (!$repo->get($id)) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        if (!$repo->delete($id)) {
            return new WP_Error('delete_failed', 'Failed to delete segment.', ['status' => 500]);
        }
        return new WP_REST_Response(['deleted' => true], 200);
    }

    public static function duplicate_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $new_name = $request->get_param('name');
        $repo = new SavedSegmentsRepository();
        $new_id = $repo->duplicate($id, $new_name !== null && $new_name !== '' ? $new_name : null);
        if ($new_id === false) {
            return new WP_Error('duplicate_failed', 'Segment not found or duplicate failed.', ['status' => 400]);
        }
        return new WP_REST_Response($repo->get($new_id), 201);
    }

    /**
     * Run segment by id; returns count and optionally updates last_run_*.
     */
    public static function run_segment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        $def = json_decode($seg['filter_definition'], true);
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', 'Stored filter definition is invalid.', ['status' => 500]);
        }
        $progress_key = $request->get_param('progress_key');
        $result = self::run_definition_internal($def, false, is_string($progress_key) && $progress_key !== '' ? $progress_key : null);
        if (isset($result['error'])) {
            return new WP_Error('run_failed', $result['error'], ['status' => 500]);
        }
        $repo->set_last_run($id, $result['count']);
        return new WP_REST_Response([
            'count' => $result['count'],
            'segment_id' => $id,
            'last_run_at' => current_time('mysql'),
        ], 200);
    }

    /**
     * Run an inline filter definition (preview); returns count only.
     */
    public static function run_definition(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $filter_definition = $request->get_param('filter_definition');
        $def = is_string($filter_definition) ? json_decode($filter_definition, true) : $filter_definition;
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', 'filter_definition must be valid JSON.', ['status' => 400]);
        }
        // #region agent log
        $log_path = defined('HP_GMC_DEBUG_LOG') ? HP_GMC_DEBUG_LOG : (file_exists('c:\\DEV\\.cursor\\debug.log') ? 'c:\\DEV\\.cursor\\debug.log' : null);
        $log_line = json_encode(['location' => 'AudiencesEndpoint::run_definition', 'message' => 'entry', 'data' => ['def_keys' => array_keys($def), 'combine' => $def['combine'] ?? null], 'timestamp' => round(microtime(true) * 1000), 'hypothesisId' => 'php-run-def']);
        if ($log_path) {
            @file_put_contents($log_path, $log_line . "\n", FILE_APPEND | LOCK_EX);
        } else {
            error_log('hp_gmc_audiences_debug: ' . $log_line);
        }
        // #endregion
        $progress_key = $request->get_param('progress_key');
        $result = self::run_definition_internal($def, true, is_string($progress_key) && $progress_key !== '' ? $progress_key : null);
        if (isset($result['error'])) {
            // #region agent log
            $err_line = json_encode(['location' => 'AudiencesEndpoint::run_definition', 'message' => 'error_result', 'data' => ['error' => $result['error']], 'timestamp' => round(microtime(true) * 1000), 'hypothesisId' => 'php-run-err']);
            if ($log_path) {
                @file_put_contents($log_path, $err_line . "\n", FILE_APPEND | LOCK_EX);
            } else {
                error_log('hp_gmc_audiences_debug: ' . $err_line);
            }
            // #endregion
            return new WP_Error('run_failed', $result['error'], ['status' => 500]);
        }
        return new WP_REST_Response(['count' => $result['count']], 200);
    }

    private const PROGRESS_TRANSIENT_PREFIX = 'hp_gmc_audience_progress_';
    private const ORDER_ID_FETCH_BATCH = 500;

    private static function run_definition_internal(array $def, bool $preview = false, ?string $progress_key = null): array
    {
        $log_path = defined('HP_GMC_DEBUG_LOG') ? HP_GMC_DEBUG_LOG : (file_exists('c:\\DEV\\.cursor\\debug.log') ? 'c:\\DEV\\.cursor\\debug.log' : null);
        $write_log = static function (string $message, array $data, string $hypothesisId) use ($log_path): void {
            $line = json_encode(['location' => 'AudiencesEndpoint::run_definition_internal', 'message' => $message, 'data' => $data, 'timestamp' => round(microtime(true) * 1000), 'hypothesisId' => $hypothesisId]);
            if ($log_path) {
                @file_put_contents($log_path, $line . "\n", FILE_APPEND | LOCK_EX);
            } else {
                error_log('hp_gmc_audiences_debug: ' . $line);
            }
        };
        if (!class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)) {
            $write_log('engine_missing', ['class' => 'HP_Abilities\\Services\\SegmentFilterEngine'], 'php-engine-missing');
            return ['error' => 'Segment engine not available (HP Abilities plugin required).', 'count' => 0];
        }
        $max_orders = $preview ? \HP_Abilities\Services\SegmentFilterEngine::PREVIEW_ORDER_LIMIT : 10000;
        $estimated_total = (int) ceil($max_orders / self::ORDER_ID_FETCH_BATCH);
        $on_progress = null;
        if ($progress_key !== null && $progress_key !== '') {
            $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
            set_transient($key, ['current' => 0, 'total' => $estimated_total], 300);
            $on_progress = static function (int $current, int $total) use ($key): void {
                set_transient($key, ['current' => $current, 'total' => $total], 300);
            };
        }
        try {
            $engine = new \HP_Abilities\Services\SegmentFilterEngine();
            $out = $engine->run($def, null, $max_orders, $on_progress);
            $write_log('success', ['count' => $out['count'] ?? 0], 'php-run-ok');
            return ['count' => $out['count'], 'rows' => $out['rows']];
        } catch (\Throwable $e) {
            $write_log('exception', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'php-run-exception');
            return ['error' => $e->getMessage(), 'count' => 0];
        }
    }

    public static function run_progress(WP_REST_Request $request): WP_REST_Response
    {
        $progress_key = $request->get_param('progress_key');
        $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
        $data = get_transient($key);
        if (!is_array($data)) {
            return new WP_REST_Response(['current' => 0, 'total' => 0], 200);
        }
        return new WP_REST_Response([
            'current' => (int) ($data['current'] ?? 0),
            'total' => (int) ($data['total'] ?? 0),
        ], 200);
    }

    /**
     * Export segment as CSV (Google Customer Match format). Returns CSV string in response or triggers download.
     */
    public static function export_csv(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        $def = json_decode($seg['filter_definition'], true);
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', 'Stored filter definition is invalid.', ['status' => 500]);
        }
        $result = self::run_definition_internal($def);
        if (isset($result['error'])) {
            return new WP_Error('run_failed', $result['error'], ['status' => 500]);
        }
        $rows = $result['rows'] ?? [];
        $csv = self::build_google_customer_match_csv($rows);
        $repo->set_last_run($id, count($rows));

        return new WP_REST_Response([
            'csv' => $csv,
            'count' => count($rows),
            'filename' => 'audience-segment-' . (int) $id . '-' . gmdate('Y-m-d') . '.csv',
        ], 200);
    }

    /**
     * Build CSV in Google Customer Match format: Email, Phone, First name, Last name, Country, Zip
     *
     * @param list<array{email: string, phone?: string, first_name?: string, last_name?: string, country?: string, zip?: string}> $rows
     */
    private static function build_google_customer_match_csv(array $rows): string
    {
        $header = ['Email', 'Phone', 'First name', 'Last name', 'Country', 'Zip'];
        $lines = [self::csv_line($header)];
        foreach ($rows as $row) {
            $lines[] = self::csv_line([
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['first_name'] ?? '',
                $row['last_name'] ?? '',
                $row['country'] ?? '',
                $row['zip'] ?? '',
            ]);
        }
        return implode("\n", $lines);
    }

    private static function csv_line(array $cells): string
    {
        return implode(',', array_map(function ($cell) {
            $cell = (string) $cell;
            if (strpos($cell, ',') !== false || strpos($cell, '"') !== false || strpos($cell, "\n") !== false) {
                return '"' . str_replace('"', '""', $cell) . '"';
            }
            return $cell;
        }, $cells));
    }

    /**
     * Upload segment to Google Ads Customer Match. Requires upload not disabled in settings.
     */
    public static function upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ((bool) get_option('hp_gmc_audience_upload_disabled', false)) {
            return new WP_Error('upload_disabled', 'Upload to Google Ads is disabled in Settings.', ['status' => 403]);
        }
        $id = (int) $request->get_param('id');
        $append = (bool) $request->get_param('append');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        try {
            $result = GoogleAdsAudienceUpload::upload($id, $append, null);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[HP-GMC] Audience upload exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return new WP_Error(
                'upload_error',
                'Server error during upload: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
        if (!$result['success']) {
            $status = (isset($result['error']) && strpos($result['error'], 'not found') !== false) ? 404 : 500;
            return new WP_Error('upload_failed', $result['error'] ?? 'Upload failed.', ['status' => $status]);
        }
        return new WP_REST_Response([
            'success' => true,
            'job_resource_name' => $result['job_resource_name'] ?? null,
            'user_list_resource_name' => $result['user_list_resource_name'] ?? null,
            'count' => $result['count'] ?? 0,
        ], 200);
    }

    /**
     * Get upload status for a segment (uses last_upload_job_id) or by job resource name.
     */
    public static function upload_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $job_resource_name = $request->get_param('job_resource_name');
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        $jobId = $job_resource_name ?: ($seg['last_upload_job_id'] ?? null);
        if (!$jobId) {
            return new WP_REST_Response([
                'status' => null,
                'message' => 'No upload job for this segment.',
                'last_upload_at' => $seg['last_upload_at'] ?? null,
                'last_upload_status' => $seg['last_upload_status'] ?? null,
            ], 200);
        }
        $statusResult = GoogleAdsAudienceUpload::getJobStatus($jobId);
        if (!$statusResult['success']) {
            return new WP_REST_Response([
                'status' => null,
                'error' => $statusResult['error'] ?? 'Could not retrieve job status.',
                'last_upload_job_id' => $jobId,
            ], 200);
        }
        $status = $statusResult['status'] ?? null;
        if ($status && in_array($status, ['SUCCESS', 'FAILED'], true)) {
            $repo->set_last_upload($id, $jobId, strtolower($status), $seg['gmc_user_list_id'] ?? null);
        }
        return new WP_REST_Response([
            'status' => $status,
            'last_upload_job_id' => $jobId,
            'last_upload_at' => $seg['last_upload_at'] ?? null,
        ], 200);
    }
}

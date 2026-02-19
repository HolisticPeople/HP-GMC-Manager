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

        register_rest_route($namespace, '/audiences/segments/run-start', [
            'methods' => 'POST',
            'callback' => [self::class, 'run_start'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'progress_key' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'preview' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/run-progress', [
            'methods' => ['GET', 'POST'],
            'callback' => [self::class, 'run_progress'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'progress_key' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/run-abort', [
            'methods' => 'POST',
            'callback' => [self::class, 'run_abort'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'progress_key' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($namespace, '/audiences/segments/run-continue', [
            'methods' => 'POST',
            'callback' => [self::class, 'run_continue'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'segment_id'  => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'progress_key' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'chunk_index'  => ['required' => true, 'type' => 'integer', 'minimum' => 0],
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
     * Run segment by id. With progress_key: returns 202 immediately and runs in background (avoids timeout).
     * Without progress_key: runs synchronously and returns 200 with count.
     */
    public static function run_segment(WP_REST_Request $request): WP_REST_Response|WP_Error|null
    {
        $id = (int) $request->get_param('id');
        self::server_log('run_segment_start', ['segment_id' => $id]);
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
        $use_async = is_string($progress_key) && $progress_key !== '' && function_exists('fastcgi_finish_request');

        if ($use_async) {
            $total = (int) ceil(self::get_max_orders() / self::get_order_batch_size());
            $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
            set_transient($key, ['current' => 0, 'total' => $total], 300);
            self::set_progress_file($progress_key, 0, $total);
            self::server_log('run_segment_async_202', ['segment_id' => $id, 'total' => $total]);
            status_header(202);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            echo wp_json_encode(['status' => 'accepted', 'progress_key' => $progress_key, 'total' => $total]);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::server_log('run_segment_background_start', ['segment_id' => $id, 'chunk' => 0]);
            $result = self::run_chunk_internal($def, $id, $progress_key, 0, $repo);
            if (isset($result['error'])) {
                self::server_log('run_segment_background_error', ['segment_id' => $id, 'error' => $result['error']]);
            } elseif (!empty($result['done'])) {
                self::server_log('run_segment_background_done', ['segment_id' => $id, 'count' => $result['count'] ?? 0]);
            }
            exit(0);
        }

        self::server_log('run_segment_calling_internal', ['segment_id' => $id]);
        $result = self::run_definition_internal($def, false, is_string($progress_key) && $progress_key !== '' ? $progress_key : null);
        if (isset($result['error'])) {
            return new WP_Error('run_failed', $result['error'], ['status' => 500]);
        }
        $repo->set_last_run($id, $result['count']);
        self::save_last_run_rows($id, $result['rows'] ?? []);
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
        try {
            $filter_definition = $request->get_param('filter_definition');
            if ($filter_definition === null || $filter_definition === '') {
                $json = $request->get_json_params();
                $filter_definition = isset($json['filter_definition']) ? $json['filter_definition'] : null;
            }
            $def = is_string($filter_definition) ? json_decode($filter_definition, true) : $filter_definition;
            if (!is_array($def)) {
                return new WP_Error('invalid_definition', 'filter_definition must be valid JSON.', ['status' => 400]);
            }
            if (!isset($def['conditions']) || !is_array($def['conditions'])) {
                $def['conditions'] = [];
            }
            $logic = $def['logic'] ?? 'and';
            if (!in_array($logic, ['and', 'or'], true)) {
                $def['logic'] = 'and';
            }
            $progress_key = $request->get_param('progress_key');
            if (($progress_key === null || $progress_key === '') && is_array($json = $request->get_json_params())) {
                $progress_key = isset($json['progress_key']) ? $json['progress_key'] : null;
            }
            $result = self::run_definition_internal($def, true, is_string($progress_key) && $progress_key !== '' ? $progress_key : null);
            if (isset($result['error'])) {
                return new WP_Error('run_failed', $result['error'], ['status' => 500]);
            }
            return new WP_REST_Response(['count' => $result['count']], 200);
        } catch (\Throwable $e) {
            return new WP_Error(
                'run_failed',
                'Preview failed: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    private const PROGRESS_TRANSIENT_PREFIX = 'hp_gmc_audience_progress_';
    private const ABORT_TRANSIENT_PREFIX = 'hp_gmc_audience_abort_';
    private const PROGRESS_FILE_DIR = 'hp-gmc-progress';

    /** Batches per chunk (configurable 25–250, default 100). */
    private static function get_batches_per_chunk(): int
    {
        $v = (int) get_option('hp_gmc_audience_batches_per_chunk', 100);
        return $v >= 25 && $v <= 250 ? $v : 100;
    }

    /** Order IDs per batch (configurable 10–200, default 50). */
    private static function get_order_batch_size(): int
    {
        $v = (int) get_option('hp_gmc_audience_order_batch_size', 50);
        return $v >= 10 && $v <= 200 ? $v : 50;
    }

    /** Max orders to scan per run (configurable 1000–100000, default 25000). */
    private static function get_max_orders(): int
    {
        $v = (int) get_option('hp_gmc_audience_max_orders', 25000);
        return $v >= 1000 && $v <= 100000 ? $v : 25000;
    }

    /** Progress file path (avoids DB/replica lag so poll always sees latest). */
    private static function progress_file_path(string $progress_key): string
    {
        $upload = wp_upload_dir();
        $dir = isset($upload['basedir']) ? $upload['basedir'] . '/' . self::PROGRESS_FILE_DIR : '';
        if ($dir && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $safe = preg_replace('/[^a-z0-9_\-]/', '', strtolower($progress_key)) ?: 'default';
        return $dir . '/' . $safe . '.json';
    }

    private static function set_progress_file(string $progress_key, int $current, int $total): void
    {
        $path = self::progress_file_path($progress_key);
        if ($path === '' || strpos($path, '..') !== false) {
            return;
        }
        $data = json_encode(['current' => $current, 'total' => $total, 'ts' => time()]);
        @file_put_contents($path, $data, LOCK_EX);
    }

    private static function get_progress_file(string $progress_key): ?array
    {
        $path = self::progress_file_path($progress_key);
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['current'], $data['total'])) {
            return null;
        }
        if (isset($data['ts']) && (time() - (int) $data['ts']) > 600) {
            @unlink($path);
            return null;
        }
        return ['current' => (int) $data['current'], 'total' => (int) $data['total']];
    }

    /** Path to last-run cache file for a segment (no DB; used by Export CSV and Upload). */
    private static function last_run_file_path(int $segment_id): string
    {
        if ($segment_id <= 0) {
            return '';
        }
        $upload = wp_upload_dir();
        $dir = isset($upload['basedir']) ? $upload['basedir'] . '/' . self::PROGRESS_FILE_DIR : '';
        if ($dir && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/last_run_' . $segment_id . '.json';
    }

    /**
     * Save last run rows to file so Export CSV and Upload can reuse without re-running.
     *
     * @param int   $segment_id Segment ID
     * @param array $rows       Rows (email, phone, first_name, last_name, country, zip)
     */
    private static function save_last_run_rows(int $segment_id, array $rows): void
    {
        $path = self::last_run_file_path($segment_id);
        if ($path === '' || strpos($path, '..') !== false) {
            return;
        }
        $data = [
            'ts'    => time(),
            'count' => count($rows),
            'rows'  => $rows,
        ];
        @file_put_contents($path, wp_json_encode($data), LOCK_EX);
    }

    /**
     * Load last run rows from file; null if missing or invalid.
     *
     * @return array|null Rows array or null
     */
    private static function load_last_run_rows(int $segment_id): ?array
    {
        $path = self::last_run_file_path($segment_id);
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            return null;
        }
        return $data['rows'];
    }

    /** Path to chunk file for chunked run. */
    private static function chunk_file_path(string $progress_key, int $chunk_index): string
    {
        $upload = wp_upload_dir();
        $dir = isset($upload['basedir']) ? $upload['basedir'] . '/' . self::PROGRESS_FILE_DIR : '';
        if ($dir && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $safe = preg_replace('/[^a-z0-9_\-]/', '', strtolower($progress_key)) ?: 'default';
        return $dir . '/chunk_' . $safe . '_' . $chunk_index . '.json';
    }

    private static function save_chunk_file(string $progress_key, int $chunk_index, array $candidates): void
    {
        $path = self::chunk_file_path($progress_key, $chunk_index);
        if ($path === '' || strpos($path, '..') !== false) {
            return;
        }
        @file_put_contents($path, wp_json_encode($candidates), LOCK_EX);
    }

    private static function load_chunk_file(string $progress_key, int $chunk_index): ?array
    {
        $path = self::chunk_file_path($progress_key, $chunk_index);
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Delete all chunk files for a progress_key (on done or abort). */
    private static function delete_chunk_files(string $progress_key, int $num_chunks): void
    {
        for ($i = 0; $i < $num_chunks; $i++) {
            $path = self::chunk_file_path($progress_key, $i);
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Log to server (error_log) and optionally to file. Include version for deploy verification. */
    private static function server_log(string $message, array $data = []): void
    {
        $version = defined('HP_GMC_VERSION') ? HP_GMC_VERSION : '0';
        $payload = array_merge(['version' => $version, 'message' => $message, 'ts' => round(microtime(true) * 1000)], $data);
        if (function_exists('memory_get_usage')) {
            $payload['memory_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
            $payload['peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        }
        $line = 'hp_gmc_audience ' . json_encode($payload);
        error_log($line);
        if (defined('HP_GMC_DEBUG_LOG') && HP_GMC_DEBUG_LOG !== '') {
            @file_put_contents(HP_GMC_DEBUG_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private static function run_definition_internal(array $def, bool $preview = false, ?string $progress_key = null): array
    {
        $def = [
            'logic'     => isset($def['logic']) && in_array($def['logic'], ['and', 'or'], true) ? $def['logic'] : 'and',
            'conditions' => isset($def['conditions']) && is_array($def['conditions']) ? $def['conditions'] : [],
        ];
        self::server_log('run_definition_internal_start', ['preview' => $preview, 'progress_key' => $progress_key ? 'set' : 'none']);
        if (!class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)) {
            return ['error' => 'Segment engine not available (HP Abilities plugin required).', 'count' => 0];
        }
        if (!function_exists('wc_get_order_statuses')) {
            return ['error' => 'WooCommerce is not active or not loaded.', 'count' => 0];
        }
        $max_orders = $preview ? \HP_Abilities\Services\SegmentFilterEngine::PREVIEW_ORDER_LIMIT : self::get_max_orders();
        $estimated_total = (int) ceil($max_orders / self::get_order_batch_size());
        $on_progress = null;
        if ($progress_key !== null && $progress_key !== '') {
            $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
            set_transient($key, ['current' => 0, 'total' => $estimated_total], 300);
            self::set_progress_file($progress_key, 0, $estimated_total);
            self::server_log('progress_transient_set', ['total' => $estimated_total]);
            $abort_key = self::ABORT_TRANSIENT_PREFIX . sanitize_key($progress_key);
            $on_progress = static function (int $current, int $total) use ($key, $progress_key, $abort_key): void {
                if (get_transient($abort_key)) {
                    delete_transient($abort_key);
                    throw new \RuntimeException('Run aborted by user');
                }
                set_transient($key, ['current' => $current, 'total' => $total], 300);
                self::set_progress_file($progress_key, $current, $total);
                if ($current <= 3 || $current % 10 === 0) {
                    self::server_log('batch_progress', ['current' => $current, 'total' => $total]);
                }
            };
        }
        try {
            self::server_log('engine_run_before', ['max_orders' => $max_orders]);
            $engine = new \HP_Abilities\Services\SegmentFilterEngine();
            $out = $engine->run($def, null, $max_orders, $on_progress);
            $count = (int) ($out['count'] ?? 0);
            $rows = $out['rows'] ?? [];
            self::server_log('engine_run_success', ['count' => $count]);
            unset($out, $engine);
            if ($preview) {
                unset($rows);
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            if ($preview) {
                return ['count' => $count];
            }
            return ['count' => $count, 'rows' => $rows];
        } catch (\Throwable $e) {
            self::server_log('engine_run_exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['error' => $e->getMessage(), 'count' => 0];
        }
    }

    /**
     * Run one chunk of segment (for chunked run). Saves partial to chunk file; on last chunk merges, builds rows, saves last_run.
     *
     * @return array{done?: bool, count?: int, current: int, total: int}|array{error: string}
     */
    private static function run_chunk_internal(array $def, int $segment_id, string $progress_key, int $chunk_index, SavedSegmentsRepository $repo): array
    {
        if (!class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)) {
            return ['error' => 'Segment engine not available (HP Abilities plugin required).', 'current' => 0, 'total' => 0];
        }
        $total_batches = (int) ceil(self::get_max_orders() / self::get_order_batch_size());
        $num_chunks = (int) ceil($total_batches / self::get_batches_per_chunk());
        if ($chunk_index < 0 || $chunk_index >= $num_chunks) {
            return ['error' => 'Invalid chunk_index.', 'current' => 0, 'total' => $total_batches];
        }
        $order_offset = $chunk_index * self::get_batches_per_chunk() * self::get_order_batch_size();
        $max_orders_in_chunk = (int) min(self::get_batches_per_chunk() * self::get_order_batch_size(), self::get_max_orders() - $order_offset);
        $abort_key = self::ABORT_TRANSIENT_PREFIX . sanitize_key($progress_key);
        $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
        $batches_per_chunk = self::get_batches_per_chunk();
        $on_progress = static function (int $batch_in_chunk, int $total_in_chunk) use ($progress_key, $chunk_index, $total_batches, $key, $abort_key, $batches_per_chunk): void {
            if (get_transient($abort_key)) {
                delete_transient($abort_key);
                self::delete_chunk_files($progress_key, (int) ceil($total_batches / self::get_batches_per_chunk()));
                throw new \RuntimeException('Run aborted by user');
            }
            $current_global = $chunk_index * $batches_per_chunk + $batch_in_chunk;
            set_transient($key, ['current' => $current_global, 'total' => $total_batches], 300);
            self::set_progress_file($progress_key, $current_global, $total_batches);
        };
        try {
            $engine = new \HP_Abilities\Services\SegmentFilterEngine();
            $candidates = $engine->build_candidate_identifiers_range($def, $order_offset, $max_orders_in_chunk, $on_progress, self::get_order_batch_size());
            self::save_chunk_file($progress_key, $chunk_index, $candidates);
            $is_last = $chunk_index === $num_chunks - 1;
            if ($is_last) {
                $chunks = [];
                for ($i = 0; $i < $num_chunks; $i++) {
                    $c = self::load_chunk_file($progress_key, $i);
                    if ($c !== null) {
                        $chunks[] = $c;
                    }
                }
                $merged = \HP_Abilities\Services\SegmentFilterEngine::merge_candidate_chunks($chunks);
                $out = $engine->build_rows_from_candidates($merged, $def);
                $rows = $out['rows'] ?? [];
                $repo->set_last_run($segment_id, count($rows));
                self::save_last_run_rows($segment_id, $rows);
                self::delete_chunk_files($progress_key, $num_chunks);
                return [
                    'done'    => true,
                    'count'   => count($rows),
                    'current' => $total_batches,
                    'total'   => $total_batches,
                ];
            }
            $next_current = ($chunk_index + 1) * self::get_batches_per_chunk();
            set_transient($key, ['current' => $next_current, 'total' => $total_batches], 300);
            self::set_progress_file($progress_key, $next_current, $total_batches);
            return [
                'done'    => false,
                'current' => $next_current,
                'total'   => $total_batches,
            ];
        } catch (\Throwable $e) {
            self::delete_chunk_files($progress_key, $num_chunks);
            return ['error' => $e->getMessage(), 'current' => 0, 'total' => $total_batches];
        }
    }

    /**
     * Continue chunked run: run one chunk by index. On last chunk, merge and save last_run.
     */
    public static function run_continue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $segment_id = (int) $request->get_param('segment_id');
        $progress_key = $request->get_param('progress_key');
        $json = $request->get_json_params();
        if ($segment_id <= 0 && isset($json['segment_id'])) {
            $segment_id = (int) $json['segment_id'];
        }
        if ((!is_string($progress_key) || $progress_key === '') && isset($json['progress_key'])) {
            $progress_key = (string) $json['progress_key'];
        }
        $chunk_index = (int) $request->get_param('chunk_index');
        if (isset($json['chunk_index'])) {
            $chunk_index = (int) $json['chunk_index'];
        }
        if ($segment_id <= 0) {
            return new WP_Error('invalid_segment', 'segment_id required.', ['status' => 400]);
        }
        if (!is_string($progress_key) || $progress_key === '') {
            return new WP_Error('invalid_progress_key', 'progress_key required.', ['status' => 400]);
        }
        $repo = new SavedSegmentsRepository();
        $seg = $repo->get($segment_id);
        if (!$seg) {
            return new WP_Error('not_found', 'Segment not found.', ['status' => 404]);
        }
        $def = json_decode($seg['filter_definition'], true);
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', 'Stored filter definition is invalid.', ['status' => 500]);
        }
        $result = self::run_chunk_internal($def, $segment_id, $progress_key, $chunk_index, $repo);
        if (isset($result['error'])) {
            return new WP_REST_Response(['error' => $result['error'], 'done' => false, 'current' => $result['current'] ?? 0, 'total' => $result['total'] ?? 0], 200);
        }
        return new WP_REST_Response([
            'done'    => $result['done'] ?? false,
            'count'   => $result['count'] ?? null,
            'current' => $result['current'],
            'total'   => $result['total'],
        ], 200);
    }

    /**
     * Lightweight start: set progress transient to (0, total) so the client can show "Processing 0 of N" immediately
     * while the heavy run request may be queued. Called by the client before POST /run or /run-definition.
     */
    public static function run_start(WP_REST_Request $request): WP_REST_Response
    {
        $progress_key = $request->get_param('progress_key');
        $preview = (bool) $request->get_param('preview');
        $max_orders = $preview && class_exists(\HP_Abilities\Services\SegmentFilterEngine::class)
            ? \HP_Abilities\Services\SegmentFilterEngine::PREVIEW_ORDER_LIMIT
            : self::get_max_orders();
        $total = (int) ceil($max_orders / self::get_order_batch_size());
        $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
        set_transient($key, ['current' => 0, 'total' => $total], 300);
        self::set_progress_file($progress_key, 0, $total);
        return new WP_REST_Response([
            'total' => $total,
            'batches_per_chunk' => self::get_batches_per_chunk(),
        ], 200);
    }

    public static function run_progress(WP_REST_Request $request): WP_REST_Response
    {
        $progress_key = $request->get_param('progress_key');
        if (!is_string($progress_key) || $progress_key === '') {
            $json = $request->get_json_params();
            $progress_key = isset($json['progress_key']) ? (string) $json['progress_key'] : '';
        }
        $data = null;
        if ($progress_key !== '') {
            $data = self::get_progress_file($progress_key);
        }
        if ($data === null) {
            $key = self::PROGRESS_TRANSIENT_PREFIX . sanitize_key($progress_key);
            $data = get_transient($key);
            $data = is_array($data) ? ['current' => (int) ($data['current'] ?? 0), 'total' => (int) ($data['total'] ?? 0)] : null;
        }
        if ($data === null) {
            $response = new WP_REST_Response(['current' => 0, 'total' => 0], 200);
        } else {
            $response = new WP_REST_Response([
                'current' => $data['current'],
                'total' => $data['total'],
            ], 200);
        }
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /**
     * Signal the running segment job to stop. Background process checks this between batches.
     */
    public static function run_abort(WP_REST_Request $request): WP_REST_Response
    {
        $progress_key = $request->get_param('progress_key');
        if (!is_string($progress_key) || $progress_key === '') {
            $json = $request->get_json_params();
            $progress_key = isset($json['progress_key']) ? (string) $json['progress_key'] : '';
        }
        if ($progress_key !== '') {
            $key = self::ABORT_TRANSIENT_PREFIX . sanitize_key($progress_key);
            set_transient($key, 1, 60);
            $total_batches = (int) ceil(self::get_max_orders() / self::get_order_batch_size());
            $num_chunks = (int) ceil($total_batches / self::get_batches_per_chunk());
            self::delete_chunk_files($progress_key, $num_chunks);
        }
        return new WP_REST_Response(['aborted' => true], 200);
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
        $rows = self::load_last_run_rows($id);
        if ($rows === null) {
            $def = json_decode($seg['filter_definition'], true);
            if (!is_array($def)) {
                return new WP_Error('invalid_definition', 'Stored filter definition is invalid.', ['status' => 500]);
            }
            $result = self::run_definition_internal($def);
            if (isset($result['error'])) {
                return new WP_Error('run_failed', $result['error'], ['status' => 500]);
            }
            $rows = $result['rows'] ?? [];
            self::save_last_run_rows($id, $rows);
            $repo->set_last_run($id, count($rows));
        }
        $csv = self::build_google_customer_match_csv($rows);
        return new WP_REST_Response([
            'csv'      => $csv,
            'count'    => count($rows),
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
        $cached_rows = self::load_last_run_rows($id);
        try {
            $result = GoogleAdsAudienceUpload::upload($id, $append, null, $cached_rows);
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

<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for saved audience segments (Audiences feature).
 * Storage only; segment execution is in hp-abilities.
 */
class SavedSegmentsRepository
{
    /** @var string */
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'hp_gmc_saved_segments';
    }

    /**
     * List saved segments (id, name, last_run_at, last_upload_at, last_run_count, etc.).
     *
     * @return list<array{id: int, name: string, filter_definition: string, created_at: string|null, updated_at: string|null, last_run_at: string|null, last_run_count: int|null, last_upload_job_id: string|null, last_upload_at: string|null, last_upload_status: string|null, gmc_user_list_id: string|null}>
     */
    public function list_all(): array
    {
        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT id, name, filter_definition, created_at, updated_at, last_run_at, last_run_count, last_upload_job_id, last_upload_at, last_upload_status, gmc_user_list_id FROM {$this->table} ORDER BY updated_at DESC",
            ARRAY_A
        );
        return is_array($results) ? $results : [];
    }

    /**
     * Get one segment by id.
     *
     * @return array{id: int, name: string, filter_definition: string, created_at: string|null, updated_at: string|null, last_run_at: string|null, last_run_count: int|null, last_upload_job_id: string|null, last_upload_at: string|null, last_upload_status: string|null, gmc_user_list_id: string|null}|null
     */
    public function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, filter_definition, created_at, updated_at, last_run_at, last_run_count, last_upload_job_id, last_upload_at, last_upload_status, gmc_user_list_id FROM {$this->table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Get one segment by name.
     *
     * @return array{id: int, name: string, filter_definition: string, ...}|null
     */
    public function get_by_name(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, filter_definition, created_at, updated_at, last_run_at, last_run_count, last_upload_job_id, last_upload_at, last_upload_status, gmc_user_list_id FROM {$this->table} WHERE name = %s",
                $name
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Create a saved segment. Name must be unique.
     *
     * @param string $name Unique name
     * @param string $filter_definition JSON filter definition (AND/OR + dimensions)
     * @return int|false Insert id or false on failure
     */
    public function create(string $name, string $filter_definition)
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $r = $wpdb->insert(
            $this->table,
            [
                'name' => $name,
                'filter_definition' => $filter_definition,
            ],
            ['%s', '%s']
        );
        return $r ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update segment name and/or filter definition.
     *
     * @param int $id Segment id
     * @param array{name?: string, filter_definition?: string} $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $allowed = [];
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                return false;
            }
            $allowed['name'] = '%s';
        }
        if (isset($data['filter_definition'])) {
            $allowed['filter_definition'] = '%s';
        }
        if (empty($allowed)) {
            return true;
        }
        return $wpdb->update($this->table, $data, ['id' => $id], $allowed, ['%d']) !== false;
    }

    /**
     * Update last-run metadata for a segment.
     *
     * @param int $id Segment id
     * @param int|null $count Row count from run
     * @return bool
     */
    public function set_last_run(int $id, ?int $count = null): bool
    {
        global $wpdb;
        $data = ['last_run_at' => current_time('mysql')];
        $formats = ['%s'];
        if ($count !== null) {
            $data['last_run_count'] = $count;
            $formats[] = '%d';
        }
        return $wpdb->update($this->table, $data, ['id' => $id], $formats, ['%d']) !== false;
    }

    /**
     * Update last-upload metadata for a segment.
     *
     * @param int $id Segment id
     * @param string|null $job_id OfflineUserDataJob id
     * @param string|null $status pending|success|failure
     * @param string|null $gmc_user_list_id Google Ads user list id
     * @return bool
     */
    public function set_last_upload(int $id, ?string $job_id = null, ?string $status = null, ?string $gmc_user_list_id = null): bool
    {
        global $wpdb;
        $data = ['last_upload_at' => current_time('mysql')];
        if ($job_id !== null) {
            $data['last_upload_job_id'] = $job_id;
        }
        if ($status !== null) {
            $data['last_upload_status'] = $status;
        }
        if ($gmc_user_list_id !== null) {
            $data['gmc_user_list_id'] = $gmc_user_list_id;
        }
        $formats = ['%s'];
        if (isset($data['last_upload_job_id'])) {
            $formats[] = '%s';
        }
        if (isset($data['last_upload_status'])) {
            $formats[] = '%s';
        }
        if (isset($data['gmc_user_list_id'])) {
            $formats[] = '%s';
        }
        return $wpdb->update($this->table, $data, ['id' => $id], $formats, ['%d']) !== false;
    }

    /**
     * Delete a segment by id.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id], ['%d']) !== false;
    }

    /**
     * Duplicate a segment (same filter logic, new name). Suggests "Copy of {name}".
     *
     * @param int $id Source segment id
     * @param string|null $new_name If null, uses "Copy of {original name}"
     * @return int|false New segment id or false
     */
    public function duplicate(int $id, ?string $new_name = null)
    {
        $seg = $this->get($id);
        if (!$seg) {
            return false;
        }
        $name = $new_name !== null && trim($new_name) !== '' ? trim($new_name) : 'Copy of ' . $seg['name'];
        return $this->create($name, $seg['filter_definition']);
    }
}

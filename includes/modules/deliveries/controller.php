<?php
/**
 * Deliveries REST Controller
 *
 * Thin router for the Deliveries module. Routing + input validation +
 * response formatting only — all CRUD goes through PCM_DB, all business
 * logic through PCM_Deliveries_Service.
 *
 * Endpoints:
 *   GET    /deliveries          → list (current user)
 *   GET    /deliveries/<id>     → getById
 *   POST   /deliveries          → create
 *   PATCH  /deliveries/<id>     → update
 *   DELETE /deliveries/<id>     → delete
 *
 * @package PowerCreatives
 * @since   1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Deliveries extends PCM_REST_Base
{

    /**
     * Service instance — formatting + status validation.
     *
     * @var PCM_Deliveries_Service
     */
    private PCM_Deliveries_Service $service;

    public function __construct()
    {
        $this->service = new PCM_Deliveries_Service();
    }

    /**
     * Define all delivery routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            array('GET',    '/deliveries',                    'list_items'),
            array('GET',    '/deliveries/(?P<id>\\d+)',       'get_by_id'),
            array('POST',   '/deliveries',                    'create_item'),
            array('PATCH',  '/deliveries/(?P<id>\\d+)',       'update_item'),
            array('DELETE', '/deliveries/(?P<id>\\d+)',       'delete_item'),
        );
    }

    // =========================================================================
    // READ
    // =========================================================================

    /** GET /deliveries — List all deliveries for the current user. */
    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $rows = PCM_DB::get_user_deliveries($user->id);

        return $this->success(array_map(array($this->service, 'format_delivery'), $rows));
    }

    /** GET /deliveries/<id> — Get a single delivery by ID. */
    public function get_by_id(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $row  = PCM_DB::get_delivery_by_id(absint($request->get_param('id')), $user->id);

        if (!$row) {
            return $this->not_found('Delivery');
        }

        return $this->success($this->service->format_delivery($row));
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /** POST /deliveries — Create a new delivery. */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $name = sanitize_text_field($request->get_param('name') ?? '');
        if ('' === $name) {
            return $this->error('Delivery name is required.');
        }

        // Optional status — defaults to 'active'. Reject unknown values
        // up-front so the DB only ever sees a value from STATUSES.
        $status_input = $request->get_param('status');
        if (null === $status_input || '' === $status_input) {
            $status = PCM_Deliveries_Service::DEFAULT_STATUS;
        } else {
            $status = $this->service->validate_status($status_input);
            if (null === $status) {
                return $this->error(
                    'Invalid status. Allowed: ' . implode(', ', PCM_Deliveries_Service::STATUSES) . '.',
                    400,
                    'pcm_invalid_status'
                );
            }
        }

        $data = array(
            'userId'     => $user->id,
            'name'       => $name,
            'clientName' => sanitize_text_field($request->get_param('clientName') ?? ''),
            'status'     => $status,
        );

        $id = PCM_DB::create_delivery($data);
        if (!$id) {
            return $this->error('Failed to create delivery.', 500);
        }

        $row = PCM_DB::get_delivery_by_id($id, $user->id);
        if (!$row) {
            // Insert succeeded but read-back failed — degenerate case,
            // surface as a server error rather than fabricate a payload.
            return $this->error('Created delivery could not be read back.', 500);
        }

        return $this->success($this->service->format_delivery($row), 201);
    }

    /** PATCH /deliveries/<id> — Update a delivery's name / clientName / status. */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id   = absint($request->get_param('id'));

        $existing = PCM_DB::get_delivery_by_id($id, $user->id);
        if (!$existing) {
            return $this->not_found('Delivery');
        }

        $update = array();

        $name = $request->get_param('name');
        if (null !== $name) {
            $clean = sanitize_text_field($name);
            if ('' === $clean) {
                return $this->error('Delivery name cannot be empty.');
            }
            $update['name'] = $clean;
        }

        $client = $request->get_param('clientName');
        if (null !== $client) {
            $update['clientName'] = sanitize_text_field($client);
        }

        $status_input = $request->get_param('status');
        if (null !== $status_input) {
            $status = $this->service->validate_status($status_input);
            if (null === $status) {
                return $this->error(
                    'Invalid status. Allowed: ' . implode(', ', PCM_Deliveries_Service::STATUSES) . '.',
                    400,
                    'pcm_invalid_status'
                );
            }
            $update['status'] = $status;
        }

        if (!empty($update)) {
            PCM_DB::update_delivery($id, $user->id, $update);
        }

        $row = PCM_DB::get_delivery_by_id($id, $user->id);
        return $this->success($this->service->format_delivery($row));
    }

    /** DELETE /deliveries/<id> — Delete a delivery. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $deleted = PCM_DB::delete_delivery(absint($request->get_param('id')), $user->id);

        if (!$deleted) {
            return $this->not_found('Delivery');
        }

        return $this->success(array('success' => true));
    }
}

<?php
/**
 * Kie.ai Marketplace Client
 *
 * Generic client for ALL Kie.ai marketplace models.
 * Ported from: SOURCE/server/kieMarketplace.ts
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCM_Kie_Marketplace {

    const API_BASE         = 'https://api.kie.ai/api/v1';
    const CREATE_ENDPOINT  = '/jobs/createTask';
    const STATUS_ENDPOINT  = '/jobs/recordInfo';
    const REQUEST_TIMEOUT  = 30;

    const ERROR_CODES = [
        200 => 'Success',
        401 => 'Unauthorized - Invalid API key',
        402 => 'Insufficient Credits',
        404 => 'Not Found',
        422 => 'Validation Error',
        429 => 'Rate Limited',
        455 => 'Service Unavailable',
        500 => 'Server Error',
        501 => 'Generation Failed',
        505 => 'Feature Disabled',
    ];

    public static function create_task( string $api_key, array $model_def, array $params, string $callback_url = '' ): array {
        $input = PCM_Kie_Input_Mapper::build_marketplace_input( $model_def, $params );

        $body = [
            'model' => $model_def['modelName'],
            'input' => $input,
        ];

        if ( ! empty( $callback_url ) ) {
            $body['callBackUrl'] = $callback_url;
        }

        error_log( sprintf( '[Kie marketplace] POST %s%s model=%s', self::API_BASE, self::CREATE_ENDPOINT, $model_def['modelName'] ) );
        error_log( sprintf( '[Kie marketplace DEBUG] Full request body: %s', wp_json_encode( $body ) ) );

        $response = wp_remote_post( self::API_BASE . self::CREATE_ENDPOINT, [
            'headers' => self::build_headers( $api_key ),
            'body'    => wp_json_encode( $body ),
            'timeout' => self::REQUEST_TIMEOUT,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Kie.ai API request failed: ' . $response->get_error_message() );
        }

        $raw_body = wp_remote_retrieve_body( $response );
        error_log( sprintf( '[Kie marketplace DEBUG] HTTP status: %d', wp_remote_retrieve_response_code( $response ) ) );
        error_log( sprintf( '[Kie marketplace DEBUG] Response body: %s', substr( $raw_body, 0, 2000 ) ) );

        $data = json_decode( $raw_body, true );
        $code = $data['code'] ?? wp_remote_retrieve_response_code( $response );

        if ( (int) $code !== 200 ) {
            $msg = $data['msg'] ?? self::ERROR_CODES[ (int) $code ] ?? "Unknown error (code {$code})";
            error_log( sprintf( '[Kie marketplace DEBUG] API ERROR code=%s msg=%s', $code, $msg ) );
            throw new Exception( "Kie.ai marketplace error ({$code}): {$msg}" );
        }

        $task_id = $data['data']['taskId'] ?? null;
        if ( empty( $task_id ) ) {
            error_log( '[Kie marketplace DEBUG] No taskId in response data: ' . wp_json_encode( $data ) );
            throw new Exception( 'Kie.ai returned success but no taskId' );
        }

        error_log( sprintf( '[Kie marketplace DEBUG] Task created successfully: taskId=%s', $task_id ) );
        return [ 'taskId' => $task_id ];
    }

    public static function get_task_status( string $api_key, string $task_id ): array {
        $url = self::API_BASE . self::STATUS_ENDPOINT . '?' . http_build_query( [ 'taskId' => $task_id ] );

        $response = wp_remote_get( $url, [
            'headers' => self::build_headers( $api_key ),
            'timeout' => self::REQUEST_TIMEOUT,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Kie.ai status request failed: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = $data['code'] ?? wp_remote_retrieve_response_code( $response );

        if ( (int) $code !== 200 ) {
            throw new Exception( $data['msg'] ?? "Failed to get task status (code {$code})" );
        }

        $task_data = $data['data'] ?? [];
        $status    = self::normalize_state( $task_data['state'] ?? $task_data['status'] ?? '' );
        $result    = self::extract_result_urls( $task_data );

        if ( 'completed' === $status || 'failed' === $status ) {
            error_log( '[Kie marketplace] Task status=' . $status . ' raw=' . wp_json_encode( $task_data ) );
        }

        return [
            'status'   => $status,
            'progress' => self::parse_progress( $task_data['progress'] ?? null ),
            'url'      => $result['url'],
            'urls'     => $result['urls'],
            'error'    => $task_data['failMsg'] ?? $task_data['error'] ?? null,
        ];
    }

    public static function wait_for_task( string $api_key, string $task_id, int $max_wait_sec = 300, int $poll_interval = 5 ): array {
        $start_time = time();

        while ( ( time() - $start_time ) < $max_wait_sec ) {
            $status = self::get_task_status( $api_key, $task_id );

            if ( $status['status'] === 'completed' ) {
                $result_url = $status['url'] ?? ( $status['urls'][0] ?? null );
                if ( empty( $result_url ) ) {
                    throw new Exception( 'Kie.ai task completed but no result URL.' );
                }
                return [ 'url' => $result_url, 'urls' => $status['urls'] ];
            }

            if ( $status['status'] === 'failed' ) {
                throw new Exception( $status['error'] ?? 'Kie.ai task failed' );
            }

            sleep( $poll_interval );
        }

        throw new Exception( "Kie.ai task timed out after {$max_wait_sec}s" );
    }

    public static function generate_image( string $api_key, array $model_def, array $params ): array {
        $result = self::create_task( $api_key, $model_def, $params );
        return self::wait_for_task( $api_key, $result['taskId'], 120, 3 );
    }

    public static function generate_video( string $api_key, array $model_def, array $params ): array {
        $result = self::create_task( $api_key, $model_def, $params );
        return self::wait_for_task( $api_key, $result['taskId'], 600, 5 );
    }

    private static function build_headers( string $api_key ): array {
        return [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];
    }

    private static function normalize_state( string $state ): string {
        switch ( strtolower( $state ) ) {
            case 'success':
            case 'completed':
                return 'completed';
            case 'fail':
            case 'failed':
                return 'failed';
            case 'generating':
            case 'processing':
                return 'processing';
            default:
                return 'pending';
        }
    }

    private static function extract_result_urls( array $data ): array {
        $urls = null;
        $url  = null;

        if ( ! empty( $data['resultJson'] ) ) {
            $result_data = $data['resultJson'];
            if ( is_string( $result_data ) ) {
                $result_data = json_decode( $result_data, true );
            }
            if ( is_array( $result_data ) ) {
                $urls = $result_data['resultUrls'] ?? $result_data['urls'] ?? null;
                $url  = $urls[0] ?? $result_data['url'] ?? null;
            }
        }

        if ( empty( $url ) && ! empty( $data['result']['url'] ) ) {
            $url = $data['result']['url'];
        }
        if ( empty( $urls ) && ! empty( $data['result']['urls'] ) ) {
            $urls = $data['result']['urls'];
        }
        if ( empty( $urls ) && ! empty( $data['response']['result_urls'] ) ) {
            $urls = $data['response']['result_urls'];
            if ( empty( $url ) ) {
                $url = $urls[0] ?? null;
            }
        }

        return [ 'url' => $url, 'urls' => $urls ];
    }

    private static function parse_progress( $progress ): ?float {
        if ( $progress === null ) {
            return null;
        }
        $value = (float) $progress;
        return $value <= 1 ? $value * 100 : $value;
    }
}

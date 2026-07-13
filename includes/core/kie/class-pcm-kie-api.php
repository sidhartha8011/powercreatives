<?php
/**
 * Kie.ai API Service - Unified Entry Point
 *
 * Routes to marketplace or dedicated API models.
 * Ported from: SOURCE/server/kieai.ts
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCM_Kie_Api {

    const API_BASE = 'https://api.kie.ai/api/v1';

    private const DEDICATED_MODELS = [
        'kie-gpt-4o-image' => [
            'endpoint'       => '/gpt4o-image/generate',
            'statusEndpoint' => '/gpt4o-image/record-info',
            'type'           => 'image',
            'format'         => 'gpt4o',
        ],
        'kie-flux-kontext' => [
            'endpoint'       => '/flux/kontext/generate',
            'statusEndpoint' => '/flux/kontext/record-info',
            'type'           => 'image',
            'format'         => 'flux-kontext',
        ],
        'kie-runway-gen3' => [
            'endpoint'       => '/runway/generate',
            'statusEndpoint' => '/runway/record-detail',
            'type'           => 'video',
            'format'         => 'runway',
        ],
        'kie-veo-3.1' => [
            'endpoint'       => '/veo/generate',
            'statusEndpoint' => '/veo/record-info',
            'type'           => 'video',
            'format'         => 'veo',
        ],
        'kie-veo-3.1-quality' => [
            'endpoint'       => '/veo/generate',
            'statusEndpoint' => '/veo/record-info',
            'type'           => 'video',
            'format'         => 'veo',
        ],
    ];

    public static function is_kie_model( string $model_id ): bool {
        return str_starts_with( $model_id, 'kie-' );
    }

    public static function is_dedicated_model( string $model_id ): bool {
        return isset( self::DEDICATED_MODELS[ $model_id ] );
    }

    public static function create_task( string $api_key, string $model_id, array $params ): array {
        $model_def = self::get_marketplace_model( $model_id );
        if ( $model_def ) {
            return PCM_Kie_Marketplace::create_task( $api_key, $model_def, $params );
        }

        if ( isset( self::DEDICATED_MODELS[ $model_id ] ) ) {
            $params['model_id'] = $model_id;
            return self::create_dedicated_task( $api_key, self::DEDICATED_MODELS[ $model_id ], $params );
        }

        throw new Exception( "Unknown Kie.ai model: {$model_id}" );
    }

    public static function get_task_status( string $api_key, string $task_id, ?string $model_id = null ): array {
        if ( $model_id && isset( self::DEDICATED_MODELS[ $model_id ] ) ) {
            return self::get_dedicated_task_status( $api_key, $task_id, self::DEDICATED_MODELS[ $model_id ] );
        }
        return PCM_Kie_Marketplace::get_task_status( $api_key, $task_id );
    }

    public static function wait_for_task( string $api_key, string $task_id, ?string $model_id = null, int $max_wait_sec = 300, int $poll_interval = 5 ): array {
        if ( ! $model_id || ! isset( self::DEDICATED_MODELS[ $model_id ] ) ) {
            return PCM_Kie_Marketplace::wait_for_task( $api_key, $task_id, $max_wait_sec, $poll_interval );
        }

        $config     = self::DEDICATED_MODELS[ $model_id ];
        $start_time = time();

        while ( ( time() - $start_time ) < $max_wait_sec ) {
            $status = self::get_dedicated_task_status( $api_key, $task_id, $config );

            if ( $status['status'] === 'completed' ) {
                return [ 'url' => $status['url'] ?? ( $status['urls'][0] ?? null ), 'urls' => $status['urls'] ];
            }
            if ( $status['status'] === 'failed' ) {
                throw new Exception( $status['error'] ?? 'Task failed' );
            }

            sleep( $poll_interval );
        }

        throw new Exception( "Task timed out after {$max_wait_sec}s" );
    }

    public static function generate_image( string $api_key, string $model_id, array $params ): array {
        // --- DEBUG: Log entry point for image generation ---
        error_log( sprintf( '[Kie API DEBUG] generate_image called with model_id=%s', $model_id ) );
        error_log( sprintf( '[Kie API DEBUG] params keys: %s', implode( ', ', array_keys( $params ) ) ) );

        $model_def = self::get_marketplace_model( $model_id );

        // --- DEBUG: Log whether model was found in marketplace registry ---
        if ( $model_def ) {
            error_log( sprintf( '[Kie API DEBUG] Marketplace model FOUND: id=%s modelName=%s capability=%s', $model_def['id'] ?? 'n/a', $model_def['modelName'] ?? 'n/a', $model_def['capability'] ?? 'n/a' ) );
            $result = PCM_Kie_Marketplace::generate_image( $api_key, $model_def, $params );
            error_log( sprintf( '[Kie API DEBUG] generate_image result: %s', wp_json_encode( $result ) ) );
            return [ 'url' => $result['url'] ];
        }

        // --- DEBUG: Model NOT found in marketplace, trying dedicated ---
        error_log( sprintf( '[Kie API DEBUG] Model NOT found in marketplace registry. is_dedicated=%s', self::is_dedicated_model( $model_id ) ? 'yes' : 'no' ) );

        $task   = self::create_task( $api_key, $model_id, $params );
        $result = self::wait_for_task( $api_key, $task['taskId'], $model_id, 120, 3 );
        if ( empty( $result['url'] ) ) {
            throw new Exception( 'No image URL in Kie.ai result' );
        }
        return [ 'url' => $result['url'] ];
    }

    public static function generate_video( string $api_key, string $model_id, array $params ): array {
        $model_def = self::get_marketplace_model( $model_id );
        if ( $model_def ) {
            $result = PCM_Kie_Marketplace::generate_video( $api_key, $model_def, $params );
            return [ 'url' => $result['url'] ];
        }
        $task   = self::create_task( $api_key, $model_id, $params );
        $result = self::wait_for_task( $api_key, $task['taskId'], $model_id, 600, 5 );
        if ( empty( $result['url'] ) ) {
            throw new Exception( 'No video URL in Kie.ai result' );
        }
        return [ 'url' => $result['url'] ];
    }

    // --- Dedicated API Internals ---

    private static function build_dedicated_body( string $format, array $params ): array {
        $prompt       = $params['prompt'] ?? '';
        $aspect_ratio = $params['aspectRatio'] ?? null;
        $duration     = $params['duration'] ?? null;
        $input_urls   = $params['inputUrls'] ?? [];
        $model_id     = $params['model_id'] ?? '';

        switch ( $format ) {
            case 'gpt4o':
                $body = [ 'prompt' => $prompt, 'size' => $aspect_ratio ?: '1:1', 'nVariants' => 1, 'isEnhance' => false ];
                if ( ! empty( $input_urls ) ) { $body['filesUrl'] = $input_urls; }
                return $body;

            case 'flux-kontext':
                $body = [ 'prompt' => $prompt, 'aspectRatio' => $aspect_ratio ?: '16:9', 'model' => 'flux-kontext-pro', 'enableTranslation' => true, 'outputFormat' => 'jpeg' ];
                if ( ! empty( $input_urls ) ) { $body['inputImage'] = $input_urls[0]; }
                return $body;

            case 'runway':
                $body = [ 'prompt' => $prompt, 'model' => 'runway-duration-5-generate', 'duration' => $duration ?: '10', 'quality' => '720p', 'aspectRatio' => $aspect_ratio ?: '16:9', 'waterMark' => '' ];
                if ( ! empty( $input_urls ) ) { $body['imageUrl'] = $input_urls[0]; }
                return $body;

            case 'veo':
                // Select veo3 (Quality/1080p) or veo3_fast (Fast/720p) based on model ID
                $veo_model = str_contains( $model_id, 'quality' ) ? 'veo3' : 'veo3_fast';
                $body = [ 'prompt' => $prompt, 'model' => $veo_model, 'generationType' => ! empty( $input_urls ) ? 'FIRST_AND_LAST_FRAMES_2_VIDEO' : 'TEXT_2_VIDEO', 'aspect_ratio' => $aspect_ratio ?: '16:9' ];
                if ( ! empty( $input_urls ) ) { $body['imageUrls'] = $input_urls; }
                return $body;

            default:
                return [ 'prompt' => $prompt ];
        }
    }

    private static function create_dedicated_task( string $api_key, array $config, array $params ): array {
        $body = self::build_dedicated_body( $config['format'], $params );
        $url  = self::API_BASE . $config['endpoint'];

        error_log( sprintf( '[Kie dedicated] POST %s', $url ) );

        $response = wp_remote_post( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Kie.ai API request failed: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = $data['code'] ?? wp_remote_retrieve_response_code( $response );

        if ( (int) $code !== 200 ) {
            $msg = $data['msg'] ?? PCM_Kie_Marketplace::ERROR_CODES[ (int) $code ] ?? 'Unknown error';
            throw new Exception( "Kie.ai dedicated API error ({$code}): {$msg}" );
        }

        $task_id = $data['data']['taskId'] ?? null;
        if ( empty( $task_id ) ) {
            throw new Exception( 'Kie.ai returned success but no taskId' );
        }

        return [ 'taskId' => $task_id ];
    }

    private static function get_dedicated_task_status( string $api_key, string $task_id, array $config ): array {
        $url = self::API_BASE . $config['statusEndpoint'] . '?' . http_build_query( [ 'taskId' => $task_id ] );

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Kie.ai status request failed: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = $data['code'] ?? wp_remote_retrieve_response_code( $response );

        if ( (int) $code !== 200 ) {
            throw new Exception( $data['msg'] ?? 'Failed to get task status' );
        }

        $task_data = $data['data'] ?? [];
        $format    = $config['format'];

        if ( in_array( $format, [ 'gpt4o', 'flux-kontext' ], true ) ) {
            return self::parse_flag_based_status( $task_data );
        }
        if ( $format === 'veo' ) {
            return self::parse_veo_status( $task_data );
        }
        if ( $format === 'runway' ) {
            return self::parse_runway_status( $task_data );
        }
        return self::parse_generic_status( $task_data );
    }

    // --- Status Parsers ---

    private static function parse_flag_based_status( array $data ): array {
        $flag   = $data['successFlag'] ?? null;
        $status = match ( $flag ) { 1 => 'completed', 2, 3 => 'failed', 0 => 'processing', default => 'pending' };

        $result_urls  = $data['response']['result_urls'] ?? null;
        $result_image = $data['response']['resultImageUrl'] ?? null;

        return [
            'status'   => $status,
            'progress' => isset( $data['progress'] ) ? (float) $data['progress'] * 100 : null,
            'url'      => $result_urls[0] ?? $result_image ?? null,
            'urls'     => $result_urls ?? ( $result_image ? [ $result_image ] : null ),
            'error'    => $data['errorMessage'] ?? null,
        ];
    }

    private static function parse_veo_status( array $data ): array {
        $flag   = $data['successFlag'] ?? null;
        $status = match ( $flag ) { 1 => 'completed', 2, 3 => 'failed', 0 => 'processing', default => 'pending' };

        $result_urls = $data['response']['resultUrls'] ?? $data['response']['result_urls'] ?? null;

        return [
            'status'   => $status,
            'progress' => null,
            'url'      => $result_urls[0] ?? null,
            'urls'     => $result_urls,
            'error'    => $data['errorMessage'] ?? $data['failMsg'] ?? null,
        ];
    }

    private static function parse_runway_status( array $data ): array {
        $state  = strtolower( $data['state'] ?? $data['status'] ?? '' );
        $status = match ( $state ) { 'success' => 'completed', 'fail', 'failed' => 'failed', 'generating' => 'processing', default => 'pending' };

        $video_url = $data['videoInfo']['videoUrl'] ?? null;

        return [
            'status'   => $status,
            'progress' => null,
            'url'      => $video_url,
            'urls'     => $video_url ? [ $video_url ] : null,
            'error'    => $data['failMsg'] ?? $data['error'] ?? null,
        ];
    }

    private static function parse_generic_status( array $data ): array {
        $state  = strtolower( $data['state'] ?? $data['status'] ?? '' );
        $status = match ( $state ) { 'success', 'completed' => 'completed', 'fail', 'failed' => 'failed', 'generating', 'processing' => 'processing', default => 'pending' };

        $url = null; $urls = null;

        if ( ! empty( $data['resultJson'] ) ) {
            $rd = is_string( $data['resultJson'] ) ? json_decode( $data['resultJson'], true ) : $data['resultJson'];
            if ( is_array( $rd ) ) {
                $urls = $rd['resultUrls'] ?? $rd['urls'] ?? null;
                $url  = $urls[0] ?? $rd['url'] ?? null;
            }
        }
        if ( empty( $url ) && ! empty( $data['result']['url'] ) )            { $url  = $data['result']['url']; }
        if ( empty( $urls ) && ! empty( $data['result']['urls'] ) )          { $urls = $data['result']['urls']; }
        if ( empty( $urls ) && ! empty( $data['response']['result_urls'] ) ) { $urls = $data['response']['result_urls']; if ( empty( $url ) ) { $url = $urls[0] ?? null; } }

        $progress = $data['progress'] ?? null;
        if ( $progress !== null ) { $progress = (float) $progress; $progress = $progress <= 1 ? $progress * 100 : $progress; }

        return [
            'status'   => $status,
            'progress' => $progress,
            'url'      => $url,
            'urls'     => $urls,
            'error'    => $data['failMsg'] ?? $data['error'] ?? null,
        ];
    }

    // --- Model Registry Lookup ---

    private static function get_marketplace_model( string $model_id ): ?array {
        static $models = null;
        if ( $models === null ) {
            $models = self::load_marketplace_registry();
        }
        return $models[ $model_id ] ?? null;
    }

    public static function load_marketplace_registry(): array {
        $json_path = PCM_PLUGIN_DIR . 'app' . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'kieMarketplaceModels.json';
        if ( file_exists( $json_path ) ) {
            $raw = file_get_contents( $json_path );
            $data = json_decode( $raw, true );
            if ( is_array( $data ) ) {
                $indexed = [];
                foreach ( $data as $model ) {
                    if ( isset( $model['id'] ) ) {
                        $indexed[ $model['id'] ] = $model;
                    }
                }
                return $indexed;
            }
        }
        error_log( '[Kie API] Warning: Marketplace models registry not found at ' . $json_path );
        return [];
    }
}

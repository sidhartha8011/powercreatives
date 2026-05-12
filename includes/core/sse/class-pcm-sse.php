<?php
/**
 * PCM SSE (Server-Sent Events) Helper
 *
 * Provides infrastructure for streaming real-time responses to the frontend.
 * Used by Copy generation and Image generation modules to deliver
 * "typewriter" progressive output during LLM calls.
 *
 * Usage in a REST controller:
 *   PCM_SSE::start();
 *   PCM_SSE::send('token', ['text' => 'Hello']);
 *   PCM_SSE::send('progress', ['percent' => 50]);
 *   PCM_SSE::send('done', ['result' => $data]);
 *   PCM_SSE::stop();
 *
 * Frontend consumption:
 *   const source = new EventSource('/wp-json/pcm/v1/stream/...');
 *   source.addEventListener('token', (e) => { ... });
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SSE
{

    /**
     * Whether SSE headers have been sent for this request.
     *
     * @var bool
     */
    private static bool $started = false;

    /**
     * Initialize the SSE response.
     *
     * Sends required headers, disables output buffering, and sets
     * appropriate execution limits for long-running LLM calls.
     *
     * IMPORTANT: Must be called BEFORE any output is sent.
     * In WP REST context, this should be called from the REST callback
     * and should prevent the normal WP REST response from being sent.
     *
     * @param int $time_limit Max execution time in seconds. Default 120.
     *
     * @return void
     */
    public static function start(int $time_limit = 120): void
    {
        if (self::$started) {
            return;
        }

        self::$started = true;

        // Remove execution time limit for long-running LLM operations
        set_time_limit($time_limit);

        // Disable PHP output buffering (may be nested)
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Required SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering

        // Enable implicit output flushing
        ob_implicit_flush(true);

        // Send initial comment to establish the connection
        echo ": PCM SSE stream\n\n";
        self::flush();
    }

    /**
     * Send an SSE event to the client.
     *
     * @param string     $event Event name (e.g. 'token', 'progress', 'error', 'done').
     * @param mixed      $data  Data payload (will be JSON-encoded if not a string).
     * @param string|null $id   Optional event ID for client reconnection.
     *
     * @return void
     */
    public static function send(string $event, $data = null, ?string $id = null): void
    {
        if (!self::$started) {
            self::start();
        }

        // Event ID (optional — allows EventSource reconnect from this point)
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        // Event type
        echo "event: {$event}\n";

        // Data — encode complex types as JSON
        $encoded = is_string($data) ? $data : wp_json_encode($data);

        // SSE data lines — each line must be prefixed with "data: "
        // Split on newlines to handle multi-line data correctly
        $lines = explode("\n", $encoded);
        foreach ($lines as $line) {
            echo "data: {$line}\n";
        }

        // Empty line terminates the event
        echo "\n";

        self::flush();
    }

    /**
     * Send a token (incremental text) event.
     * Convenience method for streaming LLM output.
     *
     * @param string $text The token/text fragment.
     *
     * @return void
     */
    public static function send_token(string $text): void
    {
        self::send('token', array('text' => $text));
    }

    /**
     * Send a progress update event.
     *
     * @param int    $current Current item index.
     * @param int    $total   Total items.
     * @param string $label   Optional human-readable label.
     *
     * @return void
     */
    public static function send_progress(int $current, int $total, string $label = ''): void
    {
        self::send('progress', array(
            'current' => $current,
            'total' => $total,
            'percent' => $total > 0 ? round(($current / $total) * 100) : 0,
            'label' => $label,
        ));
    }

    /**
     * Send an error event and stop the stream.
     *
     * @param string $message Error message.
     * @param string $code    Optional error code.
     *
     * @return void
     */
    public static function send_error(string $message, string $code = 'error'): void
    {
        self::send('error', array(
            'message' => $message,
            'code' => $code,
        ));
        self::stop();
    }

    /**
     * Send the final "done" event and close the stream.
     *
     * @param mixed $result Final result data (optional).
     *
     * @return void
     */
    public static function send_done($result = null): void
    {
        self::send('done', array('result' => $result));
        self::stop();
    }

    /**
     * Check whether an SSE stream is currently active.
     *
     * Used by controllers to decide whether to send an SSE error event
     * or fall back to a standard JSON error response.
     *
     * @return bool
     */
    public static function is_started(): bool
    {
        return self::$started;
    }

    /**
     * Stop the SSE stream and exit.
     *
     * @return void
     */
    public static function stop(): void
    {
        self::$started = false;

        // Kill the request — prevents WP from sending additional output
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        exit;
    }

    /**
     * Flush all output buffers to send data immediately.
     *
     * @return void
     */
    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Create an LLM streaming callback that forwards tokens to SSE.
     *
     * Usage with PCM_LLM:
     *   $result = PCM_LLM::invoke($messages, [
     *       'on_chunk' => PCM_SSE::llm_callback(),
     *   ]);
     *
     * @return callable The callback function for on_chunk.
     */
    public static function llm_callback(): callable
    {
        return function (string $token) {
            self::send_token($token);
        };
    }
}

<?php
/**
 * Image Processing Utilities
 *
 * PHP replacement for Node.js Sharp library.
 * Uses PHP GD extension for image manipulation and color extraction.
 *
 * Provides:
 * - Dominant color extraction from images
 * - Color merging into brand palettes
 * - Image format detection and basic processing
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Image_Utils
{

    /**
     * Extract dominant colors from an image file.
     * Uses GD library to sample pixels and find most common colors.
     *
     * @param string $file_path Absolute path to the image file.
     * @param int    $count     Number of dominant colors to extract.
     * @return array Array of hex color strings (e.g. ['#ff0000', '#00ff00']).
     */
    public static function extract_dominant_colors(string $file_path, int $count = 5): array
    {
        if (!file_exists($file_path) || !function_exists('imagecreatefromstring')) {
            return array();
        }

        // Load image using GD
        $image_data = file_get_contents($file_path);
        if (!$image_data) {
            return array();
        }

        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            return array();
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Resize to small thumbnail for faster processing
        $sample_size = 50;
        $thumb = imagecreatetruecolor($sample_size, $sample_size);

        // Preserve transparency
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $sample_size, $sample_size, $width, $height);
        imagedestroy($image);

        // Sample pixels and count colors (quantised to reduce noise)
        $color_counts = array();
        $quantize = 16; // Quantize to reduce 16M colors to manageable set

        for ($y = 0; $y < $sample_size; $y++) {
            for ($x = 0; $x < $sample_size; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Skip near-white and near-black pixels (backgrounds)
                if (($r > 240 && $g > 240 && $b > 240) || ($r < 15 && $g < 15 && $b < 15)) {
                    continue;
                }

                // Quantize to reduce noise
                $r = (int)(round($r / $quantize) * $quantize);
                $g = (int)(round($g / $quantize) * $quantize);
                $b = (int)(round($b / $quantize) * $quantize);

                $hex = sprintf('#%02x%02x%02x', $r, $g, $b);

                if (!isset($color_counts[$hex])) {
                    $color_counts[$hex] = 0;
                }
                $color_counts[$hex]++;
            }
        }

        imagedestroy($thumb);

        if (empty($color_counts)) {
            return array();
        }

        // Sort by frequency (most common first)
        arsort($color_counts);

        // Return top N colors
        return array_slice(array_keys($color_counts), 0, $count);
    }

    /**
     * Extract colors from an image file and merge into a brand's existing palette.
     * Avoids duplicates and similar colors.
     *
     * @param int    $brand_id    Brand ID.
     * @param int    $user_id     User ID for ownership check.
     * @param string $file_path   Absolute path to image.
     * @param array  $existing    Existing brand colors.
     * @return void
     */
    public static function merge_extracted_colors_from_file(
        int $brand_id,
        int $user_id,
        string $file_path,
        array $existing
        ): void
    {
        $extracted = self::extract_dominant_colors($file_path, 3);

        if (empty($extracted)) {
            return;
        }

        // Merge, avoiding duplicates and similar colors
        $merged = $existing;
        foreach ($extracted as $color) {
            $is_similar = false;
            foreach ($merged as $existing_color) {
                if (self::colors_are_similar($color, $existing_color, 30)) {
                    $is_similar = true;
                    break;
                }
            }

            if (!$is_similar && count($merged) < 20) {
                $merged[] = $color;
            }
        }

        // Only update if new colors were added
        if (count($merged) > count($existing)) {
            PCM_DB::update_brand($brand_id, $user_id, array(
                'colors' => wp_json_encode($merged),
            ));
        }
    }

    /**
     * Check if two hex colors are visually similar.
     * Uses Euclidean distance in RGB space.
     *
     * @param string $color1    Hex color (e.g. '#ff0000').
     * @param string $color2    Hex color.
     * @param int    $threshold Maximum RGB distance to consider similar.
     * @return bool
     */
    public static function colors_are_similar(string $color1, string $color2, int $threshold = 30): bool
    {
        $rgb1 = self::hex_to_rgb($color1);
        $rgb2 = self::hex_to_rgb($color2);

        if (!$rgb1 || !$rgb2) {
            return false;
        }

        $distance = sqrt(
            pow($rgb1[0] - $rgb2[0], 2) +
            pow($rgb1[1] - $rgb2[1], 2) +
            pow($rgb1[2] - $rgb2[2], 2)
        );

        return $distance <= $threshold;
    }

    /**
     * Convert a hex color to RGB array.
     *
     * @param string $hex Hex color string (6 chars with or without #).
     * @return array|null [r, g, b] or null on invalid input.
     */
    public static function hex_to_rgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return null;
        }

        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    // =========================================================================
    // CSS COLOR PARSING — Used by PCM_Website_Scraper
    // =========================================================================

    /**
     * Convert a CSS color value (hex, rgb, rgba) to normalized #RRGGBB hex.
     *
     * @param string $value CSS color value.
     * @return string|null Hex string or null on failure.
     */
    public static function css_color_to_hex(string $value): ?string
    {
        $value = trim($value);

        // Already hex: #RGB, #RRGGBB, #RRGGBBAA
        if (str_starts_with($value, '#')) {
            $hex = ltrim($value, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) >= 6) {
                return '#' . strtolower(substr($hex, 0, 6));
            }
            return null;
        }

        // rgb(R, G, B) or rgba(R, G, B, A)
        if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $value, $m)) {
            $r = max(0, min(255, (int) $m[1]));
            $g = max(0, min(255, (int) $m[2]));
            $b = max(0, min(255, (int) $m[3]));
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        return null;
    }

    /**
     * Extract hex colors from a CSS inline style string.
     *
     * @param string $style CSS inline style string.
     * @return array Array of hex color strings.
     */
    public static function extract_colors_from_inline_style(string $style): array
    {
        $colors = array();

        // Match hex colors
        if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $style, $matches)) {
            foreach ($matches[0] as $hex) {
                $converted = self::css_color_to_hex($hex);
                if ($converted) {
                    $colors[] = $converted;
                }
            }
        }

        // Match rgb/rgba colors
        if (preg_match_all('/rgba?\([^)]+\)/', $style, $matches)) {
            foreach ($matches[0] as $rgb) {
                $converted = self::css_color_to_hex($rgb);
                if ($converted) {
                    $colors[] = $converted;
                }
            }
        }

        return $colors;
    }

    /**
     * Check if a color is "noise" — near-white, near-black, or pure gray.
     * These are common background/text colors that rarely represent brand identity.
     *
     * @param string $hex Hex color string.
     * @return bool True if the color should be filtered out.
     */
    public static function is_noise_color(string $hex): bool
    {
        $rgb = self::hex_to_rgb($hex);
        if (!$rgb) return true;

        list($r, $g, $b) = $rgb;

        // Near-white (all channels > 240)
        if ($r > 240 && $g > 240 && $b > 240) return true;

        // Near-black (all channels < 20)
        if ($r < 20 && $g < 20 && $b < 20) return true;

        // Pure gray (all channels within 10 of each other, and mid-range)
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if (($max - $min) < 10 && $r > 50 && $r < 200) return true;

        return false;
    }

    /**
     * Deduplicate colors that are perceptually similar.
     *
     * @param array $colors Array of hex color strings.
     * @param int   $max    Maximum number of colors to return.
     * @return array Deduplicated colors.
     */
    public static function deduplicate_similar_colors(array $colors, int $max = 10): array
    {
        $result = array();
        foreach ($colors as $color) {
            $is_similar = false;
            foreach ($result as $existing) {
                if (self::colors_are_similar($color, $existing, 30)) {
                    $is_similar = true;
                    break;
                }
            }
            if (!$is_similar) {
                $result[] = $color;
            }
            if (count($result) >= $max) {
                break;
            }
        }
        return $result;
    }

    // =========================================================================
    // COLOR FILTERING (new vs existing palette)
    // =========================================================================

    /**
     * Filter candidate colors against an existing palette.
     *
     * Returns only candidates that are NOT perceptually similar to any
     * color already present in $existing. Uses colors_are_similar()
     * for consistent distance comparison.
     *
     * @param array $candidates Array of hex color strings to evaluate.
     * @param array $existing   Array of hex color strings already in the palette.
     * @param int   $threshold  Euclidean RGB distance threshold (default 30).
     * @return array Filtered colors — only truly new ones.
     */
    public static function filter_new_colors(array $candidates, array $existing, int $threshold = 30): array
    {
        $new = array();
        foreach ($candidates as $color) {
            $dominated = false;
            foreach ($existing as $ex) {
                if (self::colors_are_similar($color, $ex, $threshold)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $new[] = $color;
            }
        }
        return $new;
    }

    // =========================================================================
    // REMOTE IMAGE PROBING
    // =========================================================================

    /**
     * Get dimensions of a remote image by downloading only the first 2KB.
     *
     * Uses HTTP Range header to avoid downloading the full file.
     * Falls back gracefully: returns [0, 0] if dimensions cannot be determined
     * (e.g. SVG, unsupported format, server doesn't support Range).
     *
     * @param string $url Absolute URL to the image.
     * @return array{ width: int, height: int }
     */
    public static function get_remote_image_dimensions(string $url): array
    {
        $default = array('width' => 0, 'height' => 0);

        if (empty($url)) {
            return $default;
        }

        // Download only the first 2KB (enough for JPEG/PNG/GIF/WebP headers)
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'Range' => 'bytes=0-2047',
            ),
        ));

        if (is_wp_error($response)) {
            return $default;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return $default;
        }

        // Parse image dimensions from the partial data
        $info = @getimagesizefromstring($body);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            return array(
                'width'  => (int) $info[0],
                'height' => (int) $info[1],
            );
        }

        return $default;
    }
}



<?php
/**
 * Standalone contract for the Writer/Approvals original-only image metadata path.
 *
 * Run with bare PHP:
 *   php tests/standalone/writer_inline_image_metadata_test.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['pcm_test_image_size'] = array(344, 479, 'mime' => 'image/png');

function wp_getimagesize(string $file): array|false
{
    return $GLOBALS['pcm_test_image_size'];
}

function _wp_relative_upload_path(string $file): string
{
    return '2026/08/' . basename($file);
}

function wp_filesize(string $file): int
{
    return 361804;
}

require_once dirname(__DIR__, 2) . '/includes/core/storage/class-pcm-storage.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, mixed $actual = null): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  ok  {$name}\n";
        return;
    }

    ++$failed;
    echo "FAIL  {$name}\n      got: " . var_export($actual, true) . "\n";
}

$upload = new ReflectionMethod(PCM_Storage::class, 'upload');
$subsizeParameter = $upload->getParameters()[3];
check('shared upload keeps full metadata by default', true === $subsizeParameter->getDefaultValue());

$builder = new ReflectionMethod(PCM_Storage::class, 'build_original_image_metadata');
$builder->setAccessible(true);
$metadata = $builder->invoke(null, 'C:/uploads/annotated.png');

check('original width is retained', 344 === $metadata['width'], $metadata);
check('original height is retained', 479 === $metadata['height'], $metadata);
check('relative attachment path is retained', '2026/08/annotated.png' === $metadata['file'], $metadata);
check('file size is retained', 361804 === $metadata['filesize'], $metadata);
check('no derivatives are advertised', array() === $metadata['sizes'], $metadata);

$GLOBALS['pcm_test_image_size'] = false;
$invalidRejected = false;
try {
    $builder->invoke(null, 'C:/uploads/not-an-image.png');
}
catch (ReflectionException $error) {
    throw $error;
}
catch (Throwable $error) {
    $invalidRejected = $error instanceof RuntimeException;
}
check('unreadable image bytes are rejected', $invalidRejected);

if ($failed > 0) {
    echo "\n{$failed} failed, {$passed} passed\n";
    exit(1);
}

echo "\nALL GREEN — {$passed} passed, 0 failed\n";

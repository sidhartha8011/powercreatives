<?php
/**
 * Unit Tests — Writer article revision capture decision.
 *
 * Covers the unit-testable surface of the revision-history feature: the pure
 * capture-decision helper PCM_REST_Writer::should_snapshot(). That is the only
 * piece of the feature reachable in this harness — the three PCM_DB helpers
 * (add/get/get-one) are thin $wpdb wrappers (real prepared SQL, prune via
 * SELECT-then-DELETE) with no pure logic to isolate, and the REST handlers
 * (list/get/restore) require the full WP REST stack + get_current_pcm_user(),
 * which the sibling Writer suite documents as out of reach here. Their
 * behaviour (table creation, prune-to-10, ownership joins, snapshot-before-
 * write, restore round-trip) is exercised by the real-WP smoke pass — the
 * driver's job, noted in the task's acceptance.
 *
 * should_snapshot is the decision the whole feature hinges on (do we write a
 * revision on this edit?) and is shared verbatim by both capture sites
 * (editor update + ai-review apply), so pinning it here is the meaningful
 * unit-level guard.
 *
 * Same process-isolation contract as the sibling suites: the fake shares the
 * PCM_REST_Base class NAME with the real base, so every test method runs in
 * its own PHP process and the class_exists(..., false) guard passes false to
 * disable autoloading. The fake is declared via a top-level function called
 * from setUp() (execution time, inside the isolated child) — never at file
 * top level.
 *
 * @package PowerCreatives\Tests\Unit
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

/**
 * Declare a minimal fake PCM_REST_Base (the controller's parent) exactly once
 * per isolated process, then load the real controller. Loading the controller
 * only needs its parent class + ABSPATH — its other collaborators (service,
 * article-review, WP REST classes) are referenced lazily inside method bodies
 * / signatures and are not touched by should_snapshot().
 */
function pcm_test_load_writer_controller(): void
{
    if (!class_exists('PCM_REST_Base', false)) {
        class PCM_REST_Base
        {
        }
    }
    require_once dirname(__DIR__, 2) . '/includes/modules/writer/controller.php';
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WriterRevisionsTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_load_writer_controller();
    }

    /** Build a stand-in article row with the given stored content. */
    private function article($content): object
    {
        return (object) array('id' => 1, 'title' => 'T', 'content' => $content);
    }

    public function test_snapshots_when_content_present_and_changed(): void
    {
        $article = $this->article('<p>old</p>');
        $params  = array('content' => '<p>new</p>', 'title' => 'unchanged');

        $this->assertTrue(PCM_REST_Writer::should_snapshot($params, $article));
    }

    public function test_no_snapshot_when_content_present_but_identical(): void
    {
        $article = $this->article('<p>same</p>');
        $params  = array('content' => '<p>same</p>');

        $this->assertFalse(PCM_REST_Writer::should_snapshot($params, $article));
    }

    public function test_no_snapshot_when_content_key_absent(): void
    {
        // A metadata-only update (title/status/etc.) must never snapshot, even
        // though the article obviously has content stored.
        $article = $this->article('<p>body</p>');
        $params  = array('title' => 'New Title', 'status' => 'ready');

        $this->assertFalse(PCM_REST_Writer::should_snapshot($params, $article));
    }

    public function test_no_snapshot_when_article_missing_or_not_owned(): void
    {
        // get_article() returns null for a missing / cross-user article; a null
        // article can never be snapshotted regardless of payload.
        $this->assertFalse(PCM_REST_Writer::should_snapshot(array('content' => 'x'), null));
    }

    public function test_snapshots_when_stored_content_is_null_and_new_is_nonempty(): void
    {
        // Fresh articles are created with content NULL; the first real body edit
        // must still snapshot (the previous — empty — state is worth recording).
        $article = $this->article(null);
        $params  = array('content' => '<p>first body</p>');

        $this->assertTrue(PCM_REST_Writer::should_snapshot($params, $article));
    }

    public function test_no_snapshot_when_stored_content_null_and_new_is_empty(): void
    {
        // NULL stored content coerces to '' — an empty incoming content is not a
        // change, so no spurious revision is written.
        $article = $this->article(null);
        $params  = array('content' => '');

        $this->assertFalse(PCM_REST_Writer::should_snapshot($params, $article));
    }
}

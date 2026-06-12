<?php
/**
 * Unit Tests — appending assets to an existing approval set.
 *
 * Pure tests over PCM_Approvals_Service::merge_snapshot(): bucket merging is
 * deduped by item id, existing items win, order is preserved, and unrelated
 * snapshot keys (brandName etc.) survive untouched.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class ApprovalsAppendTest extends TestCase
{
    public function test_merge_appends_new_items_and_dedupes_by_id(): void
    {
        $base = array(
            'brandName' => 'ACME',
            'copy'      => array(
                array('id' => 'c1', 'headline' => 'One'),
                array('id' => 'c2', 'headline' => 'Two'),
            ),
        );
        $add = array(
            'copy' => array(
                array('id' => 'c2', 'headline' => 'Two CHANGED'), // dup — ignored
                array('id' => 'c3', 'headline' => 'Three'),
            ),
            'media' => array(
                array('id' => 'm1', 'type' => 'image', 'url' => 'https://x/1.png'),
            ),
        );

        $merged = PCM_Approvals_Service::merge_snapshot($base, $add);

        $this->assertSame(array('c1', 'c2', 'c3'), array_column($merged['copy'], 'id'));
        // Existing item wins on id collision.
        $this->assertSame('Two', $merged['copy'][1]['headline']);
        $this->assertSame(array('m1'), array_column($merged['media'], 'id'));
        $this->assertSame('ACME', $merged['brandName']);
    }

    public function test_merge_skips_malformed_items_and_empty_buckets(): void
    {
        $base = array('media' => array(array('id' => 'm1', 'url' => 'a')));
        $add  = array(
            'media'    => array('not-an-array', array('noId' => true), array('id' => 'm2', 'url' => 'b')),
            'articles' => array(),
            'junk'     => array(array('id' => 'x')), // unknown bucket — ignored
        );

        $merged = PCM_Approvals_Service::merge_snapshot($base, $add);

        $this->assertSame(array('m1', 'm2'), array_column($merged['media'], 'id'));
        $this->assertArrayNotHasKey('articles', $merged);
        $this->assertArrayNotHasKey('junk', $merged);
    }

    public function test_merge_dedupes_within_the_incoming_batch(): void
    {
        $merged = PCM_Approvals_Service::merge_snapshot(
            array(),
            array('copy' => array(
                array('id' => 'c1', 'headline' => 'first'),
                array('id' => 'c1', 'headline' => 'second'),
            ))
        );
        $this->assertCount(1, $merged['copy']);
        $this->assertSame('first', $merged['copy'][0]['headline']);
    }
}

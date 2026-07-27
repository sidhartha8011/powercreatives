<?php
/**
 * CONTRACT: every variable the Automations UI advertises for an approvals
 * trigger must actually be SENT in that trigger's context.
 *
 * The UI lists `contextKeys` verbatim ("Available: {{setName}}, …") and users
 * map them into webhook payloads. A key that is advertised but never populated
 * resolves to an empty value in the customer's webhook — silently. That is
 * exactly what happened to `approvals.set_fully_approved`, which passed only
 * five legacy keys while advertising twenty, so {{setName}}/{{setLink}}/
 * {{brandName}}/{{deliveryName}}/{{projectName}}/{{dashboardUrl}} (15 tokens)
 * all arrived empty.
 *
 * This test pins the fire_trigger CONTEXT KEYS against the declared
 * contextKeys, by parsing the source — no DB, no HTTP. It fails the moment a
 * new trigger advertises a key its fire site does not supply.
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php'; // shared WP fakes

class AutomationContextKeysTest extends \PHPUnit\Framework\TestCase
{
    /** Keys enrich_context() returns (parsed from the service source). */
    private function enrichKeys(): array
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/approvals/service.php');
        $this->assertIsString($src);
        $start = strpos($src, 'private static function enrich_context');
        $this->assertNotFalse($start, 'enrich_context() must exist');
        // The single return array(...) that closes the method.
        $ret = strpos($src, 'return array(', $start);
        $end = strpos($src, "\n        );", $ret);
        $block = substr($src, $ret, $end - $ret);
        preg_match_all("/'([A-Za-z]+)'\s*=>/", $block, $m);
        return array_values(array_unique($m[1]));
    }

    /** Keys a fire_trigger() call site supplies for $trigger (literal array keys). */
    private function fireKeys(string $trigger): array
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/approvals/service.php');
        $pos = strpos($src, "'" . $trigger . "'");
        $this->assertNotFalse($pos, "fire_trigger call for {$trigger} must exist");
        // Scan the call's argument region (generously sized) for literal keys +
        // whether it merges enrich_context().
        $region = substr($src, $pos, 1400);
        // Cut at the end of this fire_trigger call.
        $stop = strpos($region, '(int) $set->userId');
        if ($stop === false) { $stop = strpos($region, '$user_id'); }
        if ($stop !== false) { $region = substr($region, 0, $stop); }

        preg_match_all("/'([A-Za-z]+)'\s*=>/", $region, $m);
        $keys = $m[1];
        // enrich_context() only contributes when its result is actually MERGED
        // into the context passed to fire_trigger(). Requiring array_merge here
        // is what makes this test fail if a call site drops the merge (the
        // original set_fully_approved bug) — a bare mention is not enough.
        if (preg_match('/array_merge\s*\(/', $region) === 1
            && strpos($region, 'enrich_context(') !== false
        ) {
            $keys = array_merge($keys, $this->enrichKeys());
        }
        return array_values(array_unique($keys));
    }

    /** Declared contextKeys for a trigger (parsed from automations.php). */
    private function declaredKeys(string $trigger): array
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/approvals/automations.php');
        $pos = strpos($src, "'" . $trigger . "'");
        $this->assertNotFalse($pos, "trigger {$trigger} must be registered");
        $ck  = strpos($src, "'contextKeys'", $pos);
        $open = strpos($src, 'array(', $ck);
        $close = strpos($src, ')', $open);
        $block = substr($src, $open, $close - $open);
        preg_match_all("/'([A-Za-z]+)'/", $block, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * @dataProvider approvalsTriggers
     */
    public function test_every_advertised_variable_is_actually_sent(string $trigger): void
    {
        $declared = $this->declaredKeys($trigger);
        $sent     = $this->fireKeys($trigger);
        $missing  = array_values(array_diff($declared, $sent));

        $this->assertSame(
            array(),
            $missing,
            "{$trigger} advertises variables its fire_trigger() never sends — they would resolve EMPTY in a customer webhook: "
                . implode(', ', array_map(static fn($k) => '{{' . $k . '}}', $missing))
        );
    }

    public static function approvalsTriggers(): array
    {
        return array(
            'lane change'    => array('approvals.set_status_changed'),
            'sent to client' => array('approvals.set_shared'),
            'fully approved' => array('approvals.set_fully_approved'),
            'comment added'  => array('approvals.comment_added'),
        );
    }

    /** setComment is the comment text — it must be advertised where it carries a value. */
    public function test_comment_trigger_advertises_setComment(): void
    {
        $this->assertContains(
            'setComment',
            $this->declaredKeys('approvals.comment_added'),
            'the comment trigger must offer {{setComment}} — it carries the comment body'
        );
    }
}

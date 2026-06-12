<?php
/**
 * Unit Tests — delivery type → module presets.
 *
 * Pure tests over PCM_Deliveries_Service::type_presets()/sanitize_type():
 * every preset module must stay inside GRANTABLE_MODULES, and type input is
 * whitelist-validated.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class DeliveryTypePresetsTest extends TestCase
{
    private function stub_wp(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        WP_Mock::userFunction('sanitize_key')->andReturnUsing(
            fn($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key))
        );
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(
            fn($value) => trim(strip_tags((string) $value))
        );
        // type_presets() consults PCM_Settings (delivery_type_presets) before
        // falling back to the built-ins — empty option = default path.
        WP_Mock::userFunction('get_option')->andReturn(array());
    }

    public function test_presets_only_reference_grantable_modules(): void
    {
        $this->stub_wp();
        foreach (PCM_Deliveries_Service::type_presets() as $id => $preset) {
            $this->assertNotEmpty($preset['label'], "preset {$id} needs a label");
            foreach ($preset['modules'] as $module) {
                $this->assertContains(
                    $module,
                    PCM_Deliveries_Service::GRANTABLE_MODULES,
                    "preset {$id} references non-grantable module {$module}"
                );
            }
        }
    }

    public function test_sanitize_type_whitelists(): void
    {
        $this->stub_wp();
        $this->assertSame('seo', PCM_Deliveries_Service::sanitize_type('seo'));
        $this->assertSame('google_ads', PCM_Deliveries_Service::sanitize_type('google_ads'));
        $this->assertNull(PCM_Deliveries_Service::sanitize_type('bogus'));
        $this->assertNull(PCM_Deliveries_Service::sanitize_type(''));
        $this->assertNull(PCM_Deliveries_Service::sanitize_type(array('seo')));
        $this->assertNull(PCM_Deliveries_Service::sanitize_type(null));
    }

    public function test_normalize_presets_whitelists_and_drops_invalid(): void
    {
        $this->stub_wp();
        $raw = array(
            'linkedin_ads' => array(
                'label'   => '  LinkedIn Ads <script>x</script>',
                'modules' => array('ads', 'copy', 'bogus_module', 'ads'),
            ),
            'BAD KEY!!'    => array('label' => 'Has Key Junk', 'modules' => array('copy')),
            'no_label'     => array('label' => '', 'modules' => array('copy')),
            'not_array'    => 'junk',
        );

        $clean = PCM_Deliveries_Service::normalize_presets($raw);

        $this->assertSame(array('ads', 'copy'), $clean['linkedin_ads']['modules']);
        $this->assertSame('LinkedIn Ads x', $clean['linkedin_ads']['label']);
        // sanitize_key strips the junk but keeps the letters → still stored.
        $this->assertArrayHasKey('badkey', $clean);
        $this->assertArrayNotHasKey('no_label', $clean);
        $this->assertArrayNotHasKey('not_array', $clean);
    }

    public function test_normalize_presets_rejects_non_arrays(): void
    {
        $this->stub_wp();
        $this->assertSame(array(), PCM_Deliveries_Service::normalize_presets(null));
        $this->assertSame(array(), PCM_Deliveries_Service::normalize_presets('junk'));
        $this->assertSame(array(), PCM_Deliveries_Service::normalize_presets(42));
    }
}

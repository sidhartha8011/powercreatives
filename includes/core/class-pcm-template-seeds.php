<?php
/**
 * Template Seeds — Pre-programmed video templates
 *
 * Seeds default Scene and Recipe templates into the database.
 * Idempotent: only inserts if no template with the same name+module already exists.
 *
 * Called from PCM_Activator::activate() and PCM_Activator::maybe_upgrade().
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Template_Seeds
{

    /**
     * Seed all default templates.
     *
     * Safe to call multiple times — skips templates that already exist.
     *
     * @return void
     */
    public static function seed(): void
    {
        $templates = self::get_seed_templates();

        foreach ($templates as $template) {
            self::insert_if_missing($template);
        }
    }

    /**
     * Insert a template only if no template with the same name+module already exists.
     *
     * @param array $template Template data.
     * @return void
     */
    private static function insert_if_missing(array $template): void
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');

        // Check if a template with this name+module already exists (for any user)
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE name = %s AND module = %s",
            $template['name'],
            $template['module']
        ));

        if ((int)$exists > 0) {
            return; // Already seeded or user-created with same name
        }

        $now = current_time('mysql');
        $wpdb->insert($table, array(
            'userId' => 0, // System-level template (shared across all users)
            'name' => $template['name'],
            'module' => $template['module'],
            'formData' => wp_json_encode($template['formData']),
            'description' => $template['description'] ?? '',
            'isDefault' => $template['isDefault'] ?? 0,
            'createdAt' => $now,
            'updatedAt' => $now,
        ));
    }

    /**
     * All pre-programmed templates.
     *
     * Each template follows the existing DB structure:
     * - name, module, description
     * - formData.type: 'scene' or 'recipe'
     * - formData.entries[]: single entry with { key, category, label, value }
     *   where value contains the full template text as one string.
     *
     * @return array[]
     */
    private static function get_seed_templates(): array
    {
        return array(

            // ─────────────────────────────────────────────
            // SCENE: Multi-Scene (4-scene UGC ad structure)
            // ─────────────────────────────────────────────
                array(
                'name' => 'Multi-Scene',
                'module' => 'video',
                'description' => 'Multi-scene UGC ad framework with 4 scenes: Hook, Problem, Proof, CTA. Swedish-language placeholders.',
                'isDefault' => 1,
                'formData' => array(
                    'type' => 'scene',
                    'entries' => array(
                            array(
                            'key' => 'multi_scene_framework',
                            'category' => 'prompt',
                            'label' => 'Multi-Scene',
                            'value' => 'PROJECT:
- goal: [Awareness / Consideration / Conversion]
- target: [Vem tittar?]
- platform/aspect/duration: [9:16, 12s]
- tone: [UGC, casual, authentic]
- must_show: [Produkt i bild, app UI, före/efter, osv]

SCENE 1 (HOOK 0–2s)
dialogue: [Mönster: "Okej, jag måste visa…" / "Jag trodde inte…"]
action: [Snabb visuell handling som fångar uppmärksamhet]
camera: [Tight selfie / close-up / quick reveal]
emotion: [surprised / excited]
voice_type: [casual creator]

SCENE 2 (PROBLEM/PAIN 2–5s)
dialogue: [Vad var frustrationen? 1 mening]
action: [Visa problemet eller situationen]
camera: [steady, readable framing]
emotion: [relatable frustration]
voice_type: [friendly]

SCENE 3 (PROOF/DEMO 5–9s)
dialogue: ["Det som hände var…" / "Kolla här…"]
action: [Demo i 1 tydlig handling (apply, swipe, open, pour, spray)]
camera: [close-up på resultat/produkt. Slow push-in eller fixed.]
emotion: [confident]
voice_type: [calm excitement]

SCENE 4 (PAYOFF + CTA 9–12s)
dialogue: ["Jag använder den varje…" + enkel CTA: "Finns via länken…"]
action: [Produkt tydligt i bild, leende, nick]
camera: [locked, clean ending]
emotion: [happy, satisfied]
voice_type: [warm, friendly]

GLOBAL CONSTRAINTS:
- natural room tone, no music (eller väldigt subtil ambience)
- no on-screen text/captions
- keep product/logo visible in scenes 1,3,4
- avoid fast camera swings; keep motion minimal
NEGATIVE:
- no subtitles, no watermarks, no extra fingers/hands, no face warping',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // SCENE: Single-Scene (one continuous shot)
            // ─────────────────────────────────────────────
                array(
                'name' => 'Single-Scene',
                'module' => 'video',
                'description' => 'Single continuous shot framework. Swedish-language UGC style with all key fields.',
                'formData' => array(
                    'type' => 'scene',
                    'entries' => array(
                            array(
                            'key' => 'single_scene_framework',
                            'category' => 'prompt',
                            'label' => 'Single-Scene',
                            'value' => 'dialogue: [Säg 1–3 korta meningar. Låter som en människa. Inga långa stycken.]
all voice language: swedish
action: [Vad gör personen/objektet hela tiden? 1 tydlig handling. Säg vad som ska synas i bild.]
camera: [Typ av kamera + komposition + rörelse. Ex: fixed camera, iPhone selfie, eye-level, natural light.]
emotion: [Ex: genuine excitement, calm confidence, playful, relieved, curious]
voice_type: [Ex: casual, friendly, young adult female / warm male narrator / energetic creator]

setting: [Var? Bil, badrum, kök, butik, kontor…]
wardrobe/props: [Kläder, produkt, rekvisita]
lighting: [natural window light / soft bathroom light / golden hour]
constraints: [Ex: product must stay visible; maintain eye contact; no captions; no background people]
negative: [Ex: no subtitles, no logos except product, no extra hands, no weird facial distortions]',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // RECIPE: UGC Testimonial (skincare/beauty)
            // ─────────────────────────────────────────────
                array(
                'name' => 'UGC Testimonial (skincare/beauty)',
                'module' => 'video',
                'description' => 'Content recipe for UGC testimonial ads in the skincare/beauty niche. Hook-Proof-CTA structure.',
                'formData' => array(
                    'type' => 'recipe',
                    'entries' => array(
                            array(
                            'key' => 'ugc_testimonial_recipe',
                            'category' => 'prompt',
                            'label' => 'UGC Testimonial (skincare/beauty)',
                            'value' => '* Hook: "Jag var skeptisk, men…"
* Proof: 1 konkret observation (känsla/glow/texture)
* CTA: "Jag länkar den här…"',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // SCENE: Cinematic Ad (AIStudio replication)
            // ─────────────────────────────────────────────
                array(
                'name' => 'Cinematic Ad',
                'module' => 'video',
                'description' => 'Replicates AIStudio video prompt style. Plain text output — no labels, no structure. Produces identical results to the original AIStudio app.',
                'formData' => array(
                    'type' => 'scene',
                    'entries' => array(
                            array(
                            'key' => 'cinematic_ad_framework',
                            'category' => 'prompt',
                            'label' => 'Cinematic Ad',
                            'value' => '[Describe the ad scene — action, setting, characters, camera movement.]. Cinematic movement, professional advertising color grade. Determine an optimal cinematic duration for the sequence dynamically. Include elegant, legible on-screen ad copy and text overlays related to the product. Generate high-quality audio and background music. Perform the following voiceover script: "[voiceover script]".',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // ENHANCE: UGC / TikTok Style
            // ─────────────────────────────────────────────
                array(
                'name' => 'UGC / TikTok Style',
                'module' => 'video',
                'description' => 'Enhances prompts into authentic UGC/TikTok ad format with dialogue, camera, emotion, and constraints.',
                'formData' => array(
                    'type' => 'enhance',
                    'entries' => array(
                            array(
                            'key' => 'ugc_enhance',
                            'category' => 'prompt',
                            'label' => 'UGC / TikTok Style',
                            'value' => 'You are a senior creative director at a performance marketing agency.
The user will give you a short brief. Rewrite it into a structured, detailed prompt
optimized for AI video generation models (Kling, Sora, Hailuo, Runway).

Your output MUST use this exact format:

dialogue: [1-3 short sentences. Sounds like a real person talking to camera. No corporate speak.]
action: [What the person/object does throughout the video. 1 clear, visible action.]
camera: [iPhone selfie / handheld / fixed camera + framing + movement. Always specify.]
emotion: [genuine excitement / calm confidence / playful / relieved / curious]
setting: [Real location: bathroom, kitchen, car, office, gym, bedroom...]
wardrobe/props: [Clothes, product, props — realistic everyday items, not styled]
lighting: [natural window light / soft bathroom light / golden hour / ring light]
constraints: [product must stay visible; maintain eye contact; UGC/TikTok aesthetic]
negative: [no subtitles, no logos except product, no extra hands, no weird facial distortions, no CGI, no 3D rendering, no polished studio look]

Make it feel like a real UGC/TikTok/Instagram ad — authentic, not corporate.
Return ONLY the structured prompt, nothing else.',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // ENHANCE: Cinematic / Brand Film
            // ─────────────────────────────────────────────
                array(
                'name' => 'Cinematic / Brand Film',
                'module' => 'video',
                'description' => 'Enhances prompts into cinematic brand film style with professional production values.',
                'formData' => array(
                    'type' => 'enhance',
                    'entries' => array(
                            array(
                            'key' => 'cinematic_enhance',
                            'category' => 'prompt',
                            'label' => 'Cinematic / Brand Film',
                            'value' => 'You are an award-winning commercial director known for visually stunning brand films.
The user will give you a short brief. Rewrite it into a cinematic, production-ready prompt
optimized for AI video generation models.

Your output MUST use this exact format:

scene: [Describe the scene as a single continuous shot — setting, characters, key action. Write it as a visual storyboard frame.]
camera: [Professional camera work: crane / gimbal / dolly / steadicam + lens choice (35mm/50mm/85mm) + movement direction]
lighting: [Cinematic lighting: golden hour / dramatic chiaroscuro / soft diffused / neon accent / backlit silhouette]
color_grade: [Film look: warm amber / cool teal-orange / desaturated matte / high-contrast editorial]
pacing: [Slow and deliberate / dynamic cuts / single long take / building momentum]
audio: [Score mood: orchestral swell / minimal piano / ambient soundscape / energetic electronic]
text_overlays: [Elegant typography if needed — serif / sans-serif / animated text reveal]
constraints: [4K cinematic quality; professional color grade; smooth camera movement; no shaky cam]
negative: [no low quality, no blurry, no distorted faces, no amateur lighting, no vertical format unless specified]

Think premium brand film — Apple, Nike, Volvo level.
Return ONLY the structured prompt, nothing else.',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // WRITER: SEO Pillar Article
            // ─────────────────────────────────────────────
            array(
                'name' => 'SEO Pillar Article',
                'module' => 'writer',
                'description' => 'A comprehensive, structured SEO article template emphasizing readability, semantics, and depth.',
                'isDefault' => 1,
                'formData' => array(
                    'type' => 'generation',
                    'entries' => array(
                            array(
                            'key' => 'seo_pillar_prompt',
                            'category' => 'prompt',
                            'label' => 'Generation Prompt',
                            'value' => 'You are an expert SEO content writer. Create a comprehensive, pillar-style article for the provided keyword.

STRUCTURE REQUIREMENTS:
- Use an engaging H1 title.
- Use logical H2 and H3 subheadings to break up the text.
- Include an introduction that hooks the reader and states the value proposition.
- Include actionable advice or a "how-to" section if applicable.
- Conclude with a strong summary and a call-to-action (CTA).

CONTENT RULES:
- Write in a natural, authoritative yet accessible tone.
- Avoid fluff; get straight to the point.
- Ensure paragraphs are short (2-3 sentences) for screen readability.
- Use bullet points or numbered lists where appropriate to make data scannable.

OUTPUT FORMAT:
- Output clean, semantic HTML. Only use tags like <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>.
- Do not wrap the output in ```html blocks or include a <body> / <html> tag.',
                        ),
                    ),
                ),
            ),

        );
    }
}

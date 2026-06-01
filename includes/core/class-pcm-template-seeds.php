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

            // ─────────────────────────────────────────────
            // COPY: Epiphany Bridge (Full) — Russell Brunson
            // Long-form framework from Expert Secrets / Perfect Webinar.
            // Populates creativeBrief with the framework structure.
            // ─────────────────────────────────────────────
            array(
                'name' => 'Epiphany Bridge (Full)',
                'module' => 'copy',
                'description' => 'Russell Brunson\'s long-form persuasion framework from Expert Secrets. Includes rapport-building, 3 Secrets (false belief breaking), and Stack close. Best for sales pages, webinars, and long video scripts.',
                'isDefault' => 1,
                'formData' => array(
                    'type' => 'social_ads',
                    'niche' => 'General',
                    'entries' => array(
                        array(
                            'key' => 'epiphany_bridge_framework',
                            'category' => 'brief',
                            'label' => 'Copy Framework',
                            'value' => 'Follow the Epiphany Bridge framework (Russell Brunson) exactly in this order. Do NOT use a personal founder story — use customer perspective and industry-level narratives instead.

PHASE 1 — INTRODUCTION (Rapport):

1. THE BIG PROMISE
State what they will achieve and what pain they will avoid. Format: "How to [result] without [pain point]."

2. "IT\'S NOT YOUR FAULT"
Relieve them of blame for past failures. They failed because of bad information, wrong tools, or industry practices — not their own inability.

3. ALLAY THEIR FEARS
Address their specific fears about trying again. Reassure them they CAN succeed with the right guide.

4. CONFIRM THEIR SUSPICIONS
Validate what they already suspect about the industry/problem. "If you\'ve ever thought [X was broken], you\'re right."

5. THROW ROCKS AT THE ENEMY
Identify and criticize the common enemy — the old way, bad industry practices, or myths. Create "us vs them" bonding.

6. ENCOURAGE THEIR DREAMS
Validate their ultimate desire. The dream IS possible.

7. EPIPHANY BRIDGE STORY (customer perspective — no founder story needed):
- Backstory: Relatable customer situation
- Desires: What they wanted (external goal + emotional need)
- The Wall: What they tried that did not work
- The Epiphany: The realization that there is a different way
- The Plan: What makes this brand/approach different
- The Conflict: Challenges even with the right approach
- Achievement: The result / proof
- Transformation: How the situation changed on a deeper level

PHASE 2 — THE 3 SECRETS (Belief-Shifting):

For each secret, first map out:
a) The false belief they currently hold
b) Why they hold it (the experience behind it)
c) The story they tell themselves because of it
Then based on a-b-c, craft the appropriate Epiphany Bridge to break that belief.

SECRET #1 — VEHICLE BELIEF:
"This method/product won\'t work" → Bridge that proves it does.

SECRET #2 — INTERNAL BELIEF:
"I\'m not capable / I can\'t evaluate this" → Bridge that shows they can.

SECRET #3 — EXTERNAL BELIEF:
"I don\'t have time/money/support" → Bridge that removes the excuse.

PHASE 3 — THE STACK & CLOSE:

8. THE STACK — Present each component of the offer one by one with its value.
9. "IF ALL" QUESTION — "If all [product] did was [one benefit], would it be worth [price]?"
10. PRICE REVEAL — Show price against total stacked value.
11. OBJECTION CLOSE — Knock out remaining objections one by one: acknowledge → reframe with evidence → show how the solution eliminates that barrier.
12. SCARCITY/URGENCY — Time-limited bonus, limited availability, deadline.',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // COPY: Hook-Story-Offer (HSO) — Russell Brunson
            // Short-form framework from DotCom Secrets.
            // Populates creativeBrief with the framework structure.
            // ─────────────────────────────────────────────
            array(
                'name' => 'Hook-Story-Offer',
                'module' => 'copy',
                'description' => 'Russell Brunson\'s short-form ad framework from DotCom Secrets. Three blocks: Hook (stop the scroll), Story (build trust with rapport elements), Offer (drive action). Best for social media ads, emails, and short copy.',
                'isDefault' => 1,
                'formData' => array(
                    'type' => 'social_ads',
                    'niche' => 'General',
                    'entries' => array(
                        array(
                            'key' => 'hso_framework',
                            'category' => 'brief',
                            'label' => 'Copy Framework',
                            'value' => 'Follow the Hook-Story-Offer (HSO) framework (Russell Brunson) exactly. Do NOT use a personal founder story — use customer perspective and brand differentiation instead.

1. HOOK (Stop the scroll)
Its ONLY job is to make the reader stop and pay attention. It does NOT sell the product yet.
Hook types to choose from:
- Curiosity: Tease an outcome without revealing everything
- Empathy: Call out a specific pain point or fear
- Promise: State a clear benefit
- Pattern interrupt: Say something unexpected

2. STORY (Build trust & connection)
Share a relatable narrative. Position the customer as the hero, the product as the guide.
Weave in these rapport elements naturally where they fit:
- "It\'s not your fault" — Relieve blame. They failed because of bad info/tools.
- Confirm suspicions — Validate what they suspect about the industry.
- Throw rocks — Criticize the old way / the common enemy.
The story should lead naturally to: "There IS a better way."

3. OFFER (Make it irresistible)
Present the product/service as the logical solution to the story\'s problem.
- Value clearly stated (why it is a no-brainer)
- Clear call-to-action
- Urgency, scarcity, or guarantee to reduce risk',
                        ),
                    ),
                ),
            ),

            // ─────────────────────────────────────────────
            // COPY: Emotion Speed Fire — Direct Response Ad Framework
            // Story-driven, open-loop copywriting with identity-level
            // persuasion. Produces 3 versions (long/medium/short).
            // Brand name revealed late (step 10). Populates creativeBrief.
            // ─────────────────────────────────────────────
            array(
                'name' => 'Emotion Speed Fire',
                'module' => 'copy',
                'description' => 'Direct response, story-driven ad framework. Open-loop copywriting with identity-level persuasion. Every sentence sells the next. Brand name revealed late. Produces 3 versions: long, medium, and short — all different angles.',
                'isDefault' => 1,
                'formData' => array(
                    'type' => 'social_ads',
                    'niche' => 'General',
                    'entries' => array(
                        array(
                            'key' => 'emotion_speed_fire_framework',
                            'category' => 'brief',
                            'label' => 'Copy Framework',
                            'value' => 'Follow the Emotion Speed Fire framework exactly. Every sentence sells the next sentence. Every emoji intensifies emotion without feeling cheap.

GENERAL FORMATTING RULES:
- Short sentences that end in cliffhangers. The reader MUST continue to get the payoff.
- Never give away the answer before tension is built. Make the reader ask "HOW?" or "WHERE?" first.
- Break lines ONLY at strategic curiosity gaps — not after every sentence.
- Emojis go at emotional peaks only. Never decorative. Max one per section.
- One blank line between every section (breathing room for mobile).
- Use DIFFERENT wordings, DIFFERENT core problems, and DIFFERENT visualizations in each ad version.
- Speak directly to the reader — "you" in singular.
- Do NOT mention brand name or product name BEFORE step 10.
- Produce at least 1 LONG, 1 MEDIUM, and 1 SHORT version — all different angles.

EMOJI GUIDELINES (by emotion):
- Confusion & Chaos: 🤷 🤯 😵‍💫 ❓
- Exhaustion & Stress: 😩 😮‍💨 🥵 😴
- Frustration & Irritation: 🤦 😤 🙄 🤬
- Money Loss: 💸 📉 🔥 🥶
- Skepticism & Doubt: 🤨 🤔 😒 🧐
- Physical Pain: ⚡️ 🤕 😣 😖
- Time Wasting: ⏳ 🐌 ⏰ 📅

STRUCTURE (follow in this exact order):

1. CALLOUT / HOOK / PROBLEM STATEMENT
Both header and first question end with an emotional emoji. Stop the scroll with a bold, relatable pain point.

2. EXPLAIN AWAY THEIR PROBLEMS
"It\\\'s not your fault" or "that\\\'s what most of our clients also experienced." Make them feel they are not alone.

3. PAINT THE DREAM OUTCOME
Show what life looks like when the problem is solved. Vivid and specific.

4. PROBLEM STACK (2-3 HIDDEN IDENTITY-LEVEL PROBLEMS)
Not surface problems — problems tied to who they SEE themselves as. Fears and thoughts holding them back that they have not articulated.

5. SIMPLE TURNAROUND
"What if I told you that\\\'s not a problem?" or "We solved that." Short pivot that makes them wonder what comes next.

6. SOLUTION STACK
Explain the solution framework or strategy WITHOUT revealing the service name. Keep it high-level and intriguing.

7. SOLIDIFY — PAINT THE GOAL WITHOUT PROBLEMS
Tie all solutions together into ONE sentence that makes the whole package feel inevitable and effortless.

8. KISSOFF — STATUS & DEEP DESIRES
Paint how the result increases their status or what they deeply value. Extremely audience-specific: revenue, happy kids, peace of mind, respect, freedom, etc.

9. SOCIAL PROOF
One line of broad social proof (years of experience, number of customers). If a REAL testimonial exists, include it in italics. NEVER fabricate.

10. PRESENT THE VEHICLE (SUPERMAN POSE)
HERE you introduce brand name and service name as the vehicle to their dreams. Summarize promise in one punchy line. Position them as the hero — they enjoy, you handle the rest.

11. URGENCY + SCARCITY
Must flow organically from step 10. Make it feel like there is still a window but it is closing. Be specific.

12. CTA
Simple. Clear. Point down. 👇 + action text.',
                        ),
                    ),
                ),
            ),

        );
    }
}

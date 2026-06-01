/**
 * COPY FRAMEWORKS — Built-in library
 *
 * A "framework" is a named copywriting structure the AI follows when writing copy.
 * The dropdown in the Copy sidebar shows `name`; the selected `text` is injected
 * into the prompt as {{copyFramework}} (kept separate from the Creative Brief).
 *
 * These built-ins are always available. Templates can add more frameworks via the
 * `framework` entry category — those are merged in and can pre-select one.
 *
 * The `text` blocks are the *structure only* (business-agnostic), distilled from the
 * canonical docs in docs/modules/copy/ — keep them in sync with those files:
 *   - Hook-Story-Offer    → hook-story-offer.md
 *   - Epiphany Bridge     → epiphany-bridge-full.md
 *   - Emotion-Speed-Fire  → emotion-speed-fire.md
 */

export interface FrameworkOption {
  /** Name shown in the dropdown. */
  name: string;
  /** Instruction text injected into the prompt as {{copyFramework}}. */
  text: string;
}

export const BUILT_IN_FRAMEWORKS: FrameworkOption[] = [
  {
    name: "Hook-Story-Offer",
    text: `Write the copy using the Hook-Story-Offer (HSO) framework:
1. HOOK — Stop the scroll. Grab attention without selling yet (curiosity, empathy for a specific pain, a clear promise, or a pattern interrupt).
2. STORY — Build trust with a relatable narrative. Position the reader as the hero and the brand as the guide. Where natural, relieve blame ("it's not your fault"), confirm what they already suspect, and criticize the old way. Lead to "there is a better way."
3. OFFER — Present the product/service as the logical solution. State the value clearly, give one clear CTA, and reduce risk with urgency, scarcity, or a guarantee.`,
  },
  {
    name: "Epiphany Bridge",
    text: `Write the copy using the Epiphany Bridge framework (long-form):
PHASE 1 — RAPPORT: Big promise ("How to [result] without [pain]"); "it's not your fault"; allay their fears; confirm their suspicions; throw rocks at the common enemy/old way; encourage their dream; then an epiphany-bridge story from the customer's perspective (backstory → desire → the wall → the epiphany → the new plan → conflict → achievement → transformation).
PHASE 2 — BELIEF SHIFTS: Break the three blocking beliefs with short proof stories — Vehicle ("this won't work for me"), Internal ("I'm not capable"), External ("I don't have time/money").
PHASE 3 — CLOSE: Stack the offer components with their value; "if all it did was X, worth it?"; reveal price against stacked value; knock out objections one by one; finish with scarcity/urgency and a clear CTA.`,
  },
  {
    name: "Emotion-Speed-Fire",
    text: `Write the copy using the Emotion-Speed-Fire framework. Short sentences that end on cliffhangers — every line sells the next. Build tension before revealing answers. Emojis only at emotional peaks (max one per section). One blank line between sections. Speak to the reader as "you". Do NOT name the brand/product until step 10.
1. Callout/Hook — bold, relatable pain point (end on an emotional emoji).
2. Explain away their problem — "it's not your fault / you're not alone".
3. Paint the dream outcome — vivid and specific.
4. Problem stack — 2-3 hidden, identity-level fears holding them back.
5. Simple turnaround — short surprising pivot ("what if that's not a problem?").
6. Solution stack — the strategy at a high level, WITHOUT revealing the service.
7. Solidify — tie it together into one effortless-sounding sentence.
8. Kissoff — paint status/deep desires, audience-specific.
9. Social proof — one broad line (years, customers). Use a real testimonial only if provided; never fabricate.
10. Present the vehicle — NOW introduce the brand/service as the way to their dream; one punchy promise.
11. Urgency + scarcity — flows from step 10; a closing window.
12. CTA — simple, clear, 👇 + action.`,
  },
  {
    name: "AIDA",
    text: `Write the copy using the AIDA framework:
1. ATTENTION — a strong hook/headline that stops the reader.
2. INTEREST — relevant, specific details that deepen engagement and speak to their situation.
3. DESIRE — make them want the outcome: benefits, proof, emotional payoff.
4. ACTION — one clear, low-friction call-to-action.`,
  },
  {
    name: "PAS",
    text: `Write the copy using the PAS framework:
1. PROBLEM — name the reader's specific pain clearly.
2. AGITATE — make the pain vivid: consequences, frustrations, what it costs them to stay stuck.
3. SOLUTION — present the product/service as the relief, with a clear CTA.`,
  },
];

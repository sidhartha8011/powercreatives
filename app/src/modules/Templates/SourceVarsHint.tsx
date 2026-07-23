/**
 * SourceVarsHint — the {{ ... }} template-variable discoverability line.
 *
 * Templates are edited in TWO places: the Add/Edit dialog (TemplateDialog) and
 * the inline Value cell in the list (TemplateRow). The hint lived only in the
 * dialog, so an author editing a prompt inline — the faster and more common
 * path — never learned the variables existed. Extracted here so both editors
 * show the SAME text and it can only ever drift in one place.
 *
 * Renders nothing unless the entry is a Writer-side `prompt`: only those run
 * through PCM_Strategy_Service::build_prompt(), which resolves these tokens.
 * Video and SEO templates are force-set to the "prompt" category by
 * TemplateDialog's module effect but are never resolved, so advertising the
 * variables there would be a lie.
 */

const SOURCE_VARS = ["{{ post_title }}", "{{ post_content }}", "{{ post_link }}"];

// The prompt-building fragments build_prompt() otherwise injects invisibly.
// Placing one hands the author control of where it lands; any piece left
// unreferenced is still auto-appended, so a prompt using none of them is
// unchanged (byte-identical to before these variables existed).
const PROMPT_VARS = [
  "{{ keyword }}",
  "{{ brand_context }}",
  "{{ research }}",
  "{{ output_format }}",
  "{{ media_instructions }}",
];

interface SourceVarsHintProps {
  /** The template's module — "writer", "video", "seo", … */
  module?: string;
  /** The entry's category/subtype — the hint only applies to "prompt". */
  category?: string;
  className?: string;
}

export function SourceVarsHint({ module, category, className }: SourceVarsHintProps) {
  if (category !== "prompt" || module === "video" || module === "seo") return null;

  const code = (v: string) => (
    <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.7rem]">{v}</code>
  );

  return (
    <div className={`text-[0.7rem] leading-relaxed text-muted-foreground space-y-1 ${className ?? ""}`}>
      <p>
        For RSS / Social strategies you can place the source post inside the prompt with{" "}
        {SOURCE_VARS.map((v, i) => (
          <span key={v}>
            {i > 0 && ", "}
            {code(v)}
          </span>
        ))}
        . Using any of them replaces the built-in “write about this post” instruction, so the prompt is
        fully yours. They resolve to nothing for keyword strategies.
      </p>
      <p>
        You can also surface the pieces the generator otherwise injects for you —{" "}
        {PROMPT_VARS.map((v, i) => (
          <span key={v}>
            {i > 0 && ", "}
            {code(v)}
          </span>
        ))}
        . Place one to control where it appears; any piece you don’t place is still added automatically, so
        a prompt that references none of them is unchanged.
      </p>
    </div>
  );
}

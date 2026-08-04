/**
 * Brand dialog — submit-source guard.
 *
 * Guards the bugfix for "click Upload, the dialog closes and a toast says Brand
 * updated". BrandDialog's <form> hosts an entire asset manager (logo popover,
 * colour swatches, reference-image grid) whose buttons live in other files. A
 * native <button> defaults to type="submit", so any one of them that forgets
 * type="button" silently becomes "save the brand and close".
 *
 * Adding type="button" to each button fixes today's instance; this guard fixes
 * the class, so the next button someone adds cannot reintroduce it.
 *
 * The predicate below is transcribed VERBATIM from BrandDialog.handleSubmit:
 *   const submitter = (e.nativeEvent as SubmitEvent).submitter as HTMLElement | null | undefined;
 *   if (submitter && submitter.dataset.brandSubmit !== "1") return;
 *
 * Run: node tests/standalone/brand_form_submitter_guard.mjs
 */

/** Returns true when the submit should be allowed through to the save. */
function shouldSave(submitter) {
  if (submitter && submitter.dataset.brandSubmit !== "1") return false;
  return true;
}

/** Minimal stand-in for an element: only `dataset` is read. */
const el = (data = {}) => ({ dataset: data });

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

console.log("\n1. The intended submit button still saves");
const ourButton = el({ brandSubmit: "1" });
check("Update/Create Brand button saves", shouldSave(ourButton) === true, shouldSave(ourButton));

console.log("\n2. Stray submit buttons inside the form are ignored");
// Every one of these is a button that forgot type="button" somewhere in the
// asset manager. Before the guard, each was a silent "save and close".
const strays = {
  "Upload (reference images)": el({}),
  "From URL": el({}),
  "Upload Image (logo popover)": el({}),
  "Fetch (logo URL)": el({}),
  "remove-logo X": el({}),
  "move up / move down": el({}),
  "clear all": el({}),
  "a button carrying some OTHER data attr": el({ someOtherFlag: "1" }),
  "a button that fakes a near-miss value": el({ brandSubmit: "true" }),
  "a button with an empty value": el({ brandSubmit: "" }),
};
for (const [name, node] of Object.entries(strays)) {
  check(`blocked: ${name}`, shouldSave(node) === false, shouldSave(node));
}

console.log("\n3. Paths that must NOT regress");
// Implicit submission — Enter in a text field. Browsers report submitter === null.
check("Enter-to-save still works (submitter null)", shouldSave(null) === true, shouldSave(null));
// Browsers without SubmitEvent.submitter report undefined. Blocking here would
// make the brand impossible to save at all on those, so they stay allowed.
check("no SubmitEvent.submitter support still saves", shouldSave(undefined) === true, shouldSave(undefined));

console.log("\n4. The attribute name actually maps to the dataset key");
// data-brand-submit="1" in JSX must surface as dataset.brandSubmit, or the guard
// would reject the real button and nothing could ever be saved.
const attr = "data-brand-submit";
const camel = attr.replace(/^data-/, "").replace(/-([a-z])/g, (_, c) => c.toUpperCase());
check(`${attr} -> dataset.${camel}`, camel === "brandSubmit", camel);

console.log("\n" + "─".repeat(52));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);

/**
 * The toolbar's atom (decomposition S1, gap 325d280): one button, one
 * look — moved verbatim from SectionModal.tsx.
 */

export function ToolButton({ onClick, active, title, children }: {
  onClick: () => void; active?: boolean; title: string; children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault() /* keep the selection */}
      onClick={onClick}
      title={title}
      className={`rounded p-1 transition-colors hover:bg-slate-100 ${active ? 'bg-slate-100 text-slate-900' : 'text-slate-500'}`}
    >
      {children}
    </button>
  );
}

import { useAtom, useAtomValue, useSetAtom } from 'jotai';
import { Plus, Trash2, ChevronDown, ChevronRight } from 'lucide-react';
import {
  filteredDocumentsAtom,
  activeDocumentIdAtom,
  activeProjectIdAtom,
  removeDocumentAtom,
  addDocumentAtom,
} from '../store';
import type { WriterDocument } from '../store';
import { buildDefaultWriterFormValues } from '../writerConfig';
import { SectionLabel, StatusBadge, colors, typography } from '@/components/shared';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { trpc } from '@/lib/trpc';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface Props {
  /** Whether the queue list is collapsed (accordion behavior) */
  isCollapsed?: boolean;
  /** Toggle the queue list open/closed */
  onToggleCollapse?: () => void;
}

export function DocumentQueuePanel({ isCollapsed = false, onToggleCollapse }: Props) {
  const documents = useAtomValue(filteredDocumentsAtom);
  const projectsQuery = trpc.assets.getProjects.useQuery();
  const dbProjects = projectsQuery.data ?? [];
  const [activeDocId, setActiveDocId] = useAtom(activeDocumentIdAtom);
  const [activeProjectId, setActiveProjectId] = useAtom(activeProjectIdAtom);
  const removeDocument = useSetAtom(removeDocumentAtom);
  const addDocument = useSetAtom(addDocumentAtom);

  // Track which doc is being confirmed for deletion
  const [confirmDeleteId, setConfirmDeleteId] = useState<string | null>(null);

  /** Create a new empty document and add it to the queue.
   *  If a project filter is active, the new document inherits that project's
   *  ID and name so it appears in the filtered list immediately. */
  const handleCreateDocument = () => {
    // Resolve project context from the active filter so new docs aren't hidden
    let projectId = '';
    let projectName = '';
    if (activeProjectId) {
      const matchedProject = dbProjects.find(
        (p) => p.id.toString() === activeProjectId,
      );
      if (matchedProject) {
        projectId = activeProjectId;
        projectName = matchedProject.name;
      }
    }

    const newDoc: WriterDocument = {
      id: crypto.randomUUID(),
      title: '',
      slug: '',
      content: '',
      metaTitle: '',
      metaDescription: '',
      schemaType: 'Article',
      status: 'draft',
      featuredImage: '',
      projectId,
      projectName,
      generationSettings: { ...buildDefaultWriterFormValues() },
    };
    addDocument(newDoc);
    toast.success('New document created');
  };

  const handleDelete = (docId: string, docTitle: string) => {
    if (confirmDeleteId === docId) {
      // Second click — actually delete
      removeDocument(docId);
      setConfirmDeleteId(null);
      toast.success(`"${docTitle || 'Untitled'}" removed from queue`);
    } else {
      // First click — enter confirm state
      setConfirmDeleteId(docId);
      // Auto-reset after 3 seconds if not confirmed
      setTimeout(() => setConfirmDeleteId((prev) => (prev === docId ? null : prev)), 3000);
    }
  };

  return (
    <div
      className="h-full flex flex-col"
      style={{ background: colors.bgMuted }}
    >
      {/* ── Header & Filters ──────────────────────────────── */}
      <div
        className="px-3 py-3 border-b flex flex-col gap-3 shrink-0"
        style={{ borderColor: colors.borderLight }}
      >
        <div className="flex items-center justify-between">
          {/* Clickable header for accordion toggle */}
          <button
            onClick={onToggleCollapse}
            className="flex items-center gap-1.5 hover:opacity-80 transition-opacity"
            style={{ cursor: onToggleCollapse ? 'pointer' : 'default' }}
          >
            {onToggleCollapse && (
              isCollapsed
                ? <ChevronRight style={{ width: 14, height: 14, color: colors.textMuted }} />
                : <ChevronDown style={{ width: 14, height: 14, color: colors.textMuted }} />
            )}
            <SectionLabel count={documents.length}>Queue</SectionLabel>
          </button>
          <button
            onClick={handleCreateDocument}
            title="Create New Document"
            className="flex items-center justify-center rounded transition-colors hover:bg-black/5"
            style={{ width: 22, height: 22, color: colors.textMuted }}
          >
            <Plus style={{ width: 14, height: 14 }} />
          </button>
        </div>

        {/* Project Filter — hidden when collapsed */}
        {!isCollapsed && (
          <Select
            value={activeProjectId || 'all'}
            onValueChange={(val) => setActiveProjectId(val === 'all' ? null : val)}
          >
            <SelectTrigger className="w-full h-8 text-xs bg-white">
              <SelectValue placeholder="All Projects" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {projectsQuery.isLoading ? (
                  <div className="flex items-center gap-2">
                    <Loader2 className="w-3 h-3 animate-spin md:mr-2" /> Loading...
                  </div>
                ) : 'All Projects'}
              </SelectItem>
              {dbProjects.map((proj) => (
                <SelectItem key={proj.id} value={proj.id.toString()}>
                  {proj.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </div>

      {/* ── Document List (hidden when collapsed) ─────────── */}
      {!isCollapsed && (
        <div className="flex-1 overflow-y-auto px-2 py-2 space-y-1">
          {documents.map((doc) => {
            const isActive = doc.id === activeDocId;
            const isConfirming = confirmDeleteId === doc.id;

            return (
              <div
                key={doc.id}
                className="relative group"
              >
                <button
                  onClick={() => setActiveDocId(doc.id)}
                  className="w-full flex items-center justify-between px-2 py-2 rounded transition-colors text-left"
                  style={{
                    background: isActive ? colors.primaryLight : 'transparent',
                  }}
                >
                  <div className="flex flex-col gap-0.5 overflow-hidden pr-6">
                    <span
                      className="truncate"
                      style={{
                        fontSize: typography.sm,
                        fontWeight: isActive ? typography.medium : typography.regular,
                        color: isActive ? colors.primary : colors.text,
                      }}
                    >
                      {doc.title || 'Untitled'}
                    </span>
                    <span
                      className="truncate transition-opacity"
                      style={{
                        fontSize: typography.xs,
                        color: colors.textFaint,
                        opacity: isActive ? 0.8 : 0.6,
                      }}
                    >
                      {doc.projectName || 'No project'}
                    </span>
                  </div>
                  
                  {/* Status dot — visible when not hovering */}
                  <div className="shrink-0 transition-opacity opacity-70 group-hover:opacity-0">
                     <StatusBadge status={doc.status} variant="dot" />
                  </div>
                </button>

                {/* Delete button — visible on hover, replaces status dot */}
                <button
                  onClick={(e) => {
                    e.stopPropagation();
                    handleDelete(doc.id, doc.title);
                  }}
                  title={isConfirming ? 'Click again to confirm' : 'Remove from queue'}
                  className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded transition-all opacity-0 group-hover:opacity-100"
                  style={{
                    color: isConfirming ? colors.danger : colors.textMuted,
                    background: isConfirming ? colors.dangerLight : 'transparent',
                  }}
                >
                  <Trash2 style={{ width: 13, height: 13 }} />
                </button>
              </div>
            );
          })}

          {documents.length === 0 && (
            <div
              className="text-center py-6 px-4"
              style={{ fontSize: typography.xs, color: colors.textMuted }}
            >
              No documents in queue. Click + above or use Keywords → Send to Writer.
            </div>
          )}
        </div>
      )}
    </div>
  );
}


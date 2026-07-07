/**
 * DeliveryProjects — the "projects in this delivery" surface, extracted from
 * DeliveryDialog so the delivery card AND the Deliveries table's accordion
 * rows render the exact same thing from one source:
 *
 *   useDeliveryProjects     — data + mutations (assign / unassign / site link)
 *   AddProjectMenu          — the "assign a project" dropdown (header + empty state)
 *   DeliveryProjectsBody    — empty-state-or-table (Project | Site | Images |
 *                             Copy | Articles | Videos | ✕)
 *
 * Columns: Site is the projects.siteId connect/disconnect select; Images/Copy/
 * Videos jump straight into that project on the matching detail tab
 * (AppContext.navigateToProjectTab — videos live on the media tab alongside
 * images). Articles is intentionally disabled: articles have no project
 * relation in the data model (articles.siteId only) and Projects has no
 * articles tab — wiring it anywhere would be a lie.
 */

import { Plus, X } from 'lucide-react';
import { toast } from 'sonner';

import {
  CARD_TABLE_CELL,
  CARD_TABLE_HEAD,
  CARD_TABLE_ROW,
  CARD_TABLE_WRAPPER,
  CARD_TYPE,
} from '@/components/shared/EntityCard';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';

import type { Delivery } from './types';

export interface DeliveryProject {
  id: number;
  name: string;
  deliveryId: number | null;
  siteId: number | null;
  assetCount: number;
}

export interface DeliveryProjectsState {
  /** Projects assigned to this delivery. */
  inDelivery: DeliveryProject[];
  /** Every other project (assignable; may belong to another delivery). */
  available: DeliveryProject[];
  sites: { id: number; name: string }[];
  assignProject: (projectId: number, name: string) => Promise<void>;
  unassignProject: (projectId: number, name: string) => Promise<void>;
  setProjectSite: (project: { id: number; name: string }, siteId: number | null) => Promise<void>;
  /** True while a site connect/disconnect is in flight. */
  sitePending: boolean;
}

/** Data + mutations for one delivery's project list. */
export function useDeliveryProjects(delivery: Delivery): DeliveryProjectsState {
  const { data: projectsRaw, refetch: refetchProjects } = trpc.assets.getProjects.useQuery();
  const { data: sitesRaw } = trpc.sites.list.useQuery();

  // Number()-normalize ids — wpdb returns strings.
  const projects: DeliveryProject[] = Array.isArray(projectsRaw)
    ? projectsRaw.map((p: any) => ({
        id: Number(p.id),
        name: String(p.name ?? ''),
        deliveryId: p.deliveryId != null ? Number(p.deliveryId) : null,
        siteId: p.siteId != null ? Number(p.siteId) : null,
        assetCount: Number(p.assetCount ?? 0),
      }))
    : [];
  const sites: { id: number; name: string }[] = Array.isArray(sitesRaw)
    ? (sitesRaw as any[]).map((s) => ({ id: Number(s.id), name: String(s.name || s.url || `Site #${s.id}`) }))
    : [];

  const inDelivery = projects.filter((p) => p.deliveryId === Number(delivery.id));
  const available = projects.filter((p) => p.deliveryId !== Number(delivery.id));

  const setDeliveryMutation = trpc.assets.setProjectDelivery.useMutation() as any;
  const setSiteMutation = trpc.assets.setProjectSite.useMutation() as any;

  const assignProject = async (projectId: number, name: string) => {
    try {
      await setDeliveryMutation.mutateAsync({ id: projectId, deliveryId: Number(delivery.id) });
      toast.success(`“${name}” added to this delivery`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to add the project');
    }
  };

  const unassignProject = async (projectId: number, name: string) => {
    try {
      await setDeliveryMutation.mutateAsync({ id: projectId, deliveryId: null });
      toast.success(`“${name}” removed from this delivery`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to remove the project');
    }
  };

  const setProjectSite = async (project: { id: number; name: string }, siteId: number | null) => {
    try {
      await setSiteMutation.mutateAsync({ id: project.id, siteId });
      toast.success(siteId ? `“${project.name}” connected to site` : `“${project.name}” disconnected from site`);
      refetchProjects();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to update the site connection');
    }
  };

  return {
    inDelivery,
    available,
    sites,
    assignProject,
    unassignProject,
    setProjectSite,
    sitePending: Boolean(setSiteMutation.isPending),
  };
}

/** Shared "assign a project" menu — used by the header action AND the empty state. */
export function AddProjectMenu({
  available,
  onAssign,
  trigger,
}: {
  available: { id: number; name: string; deliveryId: number | null }[];
  onAssign: (projectId: number, name: string) => void;
  trigger: React.ReactNode;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel className="text-xs">Assign a project to this delivery</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {available.length === 0 && (
          <div className="px-2 py-1.5 text-xs text-muted-foreground">No unassigned projects</div>
        )}
        {available.map((p) => (
          <DropdownMenuItem key={p.id} className="text-xs" onSelect={() => onAssign(p.id, p.name)}>
            <span className="truncate">{p.name}</span>
            {p.deliveryId !== null && (
              <span className="ml-auto pl-2 text-[10px] text-muted-foreground shrink-0">in another delivery</span>
            )}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * The list itself: dashed add-button when empty, otherwise the feather-light
 * card table. Row actions reveal on hover; the empty state IS the add action.
 * `onNavigated` fires after a jump into a project (the card uses it to close).
 */
export function DeliveryProjectsBody({
  state,
  onNavigated,
}: {
  state: DeliveryProjectsState;
  onNavigated?: () => void;
}) {
  const { navigateToProjectTab } = useApp();
  const { inDelivery, available, sites, assignProject, unassignProject, setProjectSite, sitePending } = state;

  /** Jump straight into the project on the given detail tab. */
  const goToProject = (projectId: number, tab: 'media' | 'copy') => {
    navigateToProjectTab({ projectId, tab });
    onNavigated?.();
  };

  if (inDelivery.length === 0) {
    // The empty state IS the action — one click, same menu.
    return (
      <AddProjectMenu
        available={available}
        onAssign={(id, name) => void assignProject(id, name)}
        trigger={
          <Button
            type="button"
            variant="ghost"
            className={`h-auto w-full justify-center gap-1 rounded-md border border-dashed px-3 py-4 ${CARD_TYPE.LABEL}`}
          >
            <Plus className="h-3.5 w-3.5" /> Add a project to this delivery
          </Button>
        }
      />
    );
  }

  return (
    <div className={CARD_TABLE_WRAPPER}>
      <Table>
        <TableHeader>
          <TableRow className={CARD_TABLE_ROW}>
            <TableHead className={CARD_TABLE_HEAD}>Project</TableHead>
            <TableHead className={CARD_TABLE_HEAD}>Site</TableHead>
            <TableHead className={CARD_TABLE_HEAD}>Images</TableHead>
            <TableHead className={CARD_TABLE_HEAD}>Copy</TableHead>
            <TableHead className={CARD_TABLE_HEAD}>Articles</TableHead>
            <TableHead className={CARD_TABLE_HEAD}>Videos</TableHead>
            <TableHead className={`${CARD_TABLE_HEAD} w-10`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {inDelivery.map((p) => (
            <TableRow key={p.id} className={`group ${CARD_TABLE_ROW}`}>
              {/* Names earn emphasis from position, not weight — BODY like every value. */}
              <TableCell className={`${CARD_TABLE_CELL} px-3`}>{p.name}</TableCell>
              <TableCell className={CARD_TABLE_CELL}>
                <Select
                  value={p.siteId != null ? String(p.siteId) : 'none'}
                  onValueChange={(v) => void setProjectSite(p, v === 'none' ? null : Number(v))}
                  disabled={sitePending}
                >
                  <SelectTrigger
                    aria-label={`Site for ${p.name}`}
                    className={`h-8 w-full rounded border-none bg-transparent px-2 shadow-none hover:bg-slate-50 focus-visible:ring-1 ${CARD_TYPE.BODY} ${p.siteId == null ? 'text-muted-foreground' : ''}`}
                  >
                    <SelectValue placeholder="Not connected" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none" className="text-muted-foreground">Not connected</SelectItem>
                    {sites.map((s) => (
                      <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </TableCell>
              {/* Per-type jump cells — straight into the project on that tab. */}
              <TableCell className={CARD_TABLE_CELL}>
                <Button type="button" variant="ghost" size="sm" className={`h-7 px-2 ${CARD_TYPE.BODY} hover:text-primary`} onClick={() => goToProject(p.id, 'media')}>
                  Images
                </Button>
              </TableCell>
              <TableCell className={CARD_TABLE_CELL}>
                <Button type="button" variant="ghost" size="sm" className={`h-7 px-2 ${CARD_TYPE.BODY} hover:text-primary`} onClick={() => goToProject(p.id, 'copy')}>
                  Copy
                </Button>
              </TableCell>
              <TableCell className={CARD_TABLE_CELL}>
                {/* Disabled on purpose: articles aren't project-linked in the data model. */}
                <span className={`px-2 ${CARD_TYPE.LABEL}`} title="Articles are not linked to projects yet">—</span>
              </TableCell>
              <TableCell className={CARD_TABLE_CELL}>
                <Button type="button" variant="ghost" size="sm" className={`h-7 px-2 ${CARD_TYPE.BODY} hover:text-primary`} onClick={() => goToProject(p.id, 'media')}>
                  Videos
                </Button>
              </TableCell>
              <TableCell className={`${CARD_TABLE_CELL} text-right`}>
                {/* Revealed on row hover — quiet at rest. */}
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 hover:text-destructive"
                  title="Remove from this delivery"
                  aria-label={`Remove ${p.name} from this delivery`}
                  onClick={() => void unassignProject(p.id, p.name)}
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

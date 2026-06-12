/**
 * USERS MODULE — admin-only user management.
 *
 * Lists every WordPress user (mirrored into the plugin) with their access
 * level (admin/user — derived from WP capabilities) and lets an admin assign
 * deliveries. An assigned delivery grants the user view+use access to the
 * delivery and its linked brand/project.
 */

import { useMemo, useState } from 'react';
import { Users as UsersIcon, Loader2, Package } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';

interface ManagedUser {
  pcmId: number;
  wpUserId: number;
  name: string;
  email: string;
  avatarUrl?: string;
  role: 'admin' | 'user';
  assignedDeliveryIds: number[];
}

interface DeliveryRow {
  id: number;
  name: string;
  clientName?: string | null;
  status: string;
  brandId?: number | null;
  projectId?: number | null;
}

export function UsersModule() {
  const { data: usersRaw, isLoading, refetch } = trpc.users.list.useQuery();
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const { data: brandsRaw } = trpc.brands.list.useQuery();

  const users: ManagedUser[] = Array.isArray(usersRaw) ? usersRaw : [];
  const deliveries: DeliveryRow[] = Array.isArray(deliveriesRaw) ? deliveriesRaw : [];
  const brandNames = useMemo(() => {
    const m = new Map<number, string>();
    (Array.isArray(brandsRaw) ? brandsRaw : []).forEach((b: any) => m.set(Number(b.id), b.name));
    return m;
  }, [brandsRaw]);

  const [assignFor, setAssignFor] = useState<ManagedUser | null>(null);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-bold flex items-center gap-2">
            <UsersIcon className="w-5 h-5 text-primary" /> Users
          </h2>
          <p className="text-sm text-muted-foreground">
            Everyone with access to this WordPress site. Access level mirrors their WP role;
            assign deliveries to give a user access to that delivery&apos;s brand and project.
          </p>
        </div>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-16 text-muted-foreground">
          <Loader2 className="w-5 h-5 animate-spin mr-2" /> Loading users…
        </div>
      ) : (
        <div className="rounded-lg border bg-background">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>User</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Access level</TableHead>
                <TableHead>Assigned deliveries</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map((u) => (
                <TableRow key={u.pcmId}>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      {u.avatarUrl ? (
                        <img src={u.avatarUrl} alt="" className="w-7 h-7 rounded-full" />
                      ) : (
                        <div className="w-7 h-7 rounded-full bg-muted" />
                      )}
                      <span className="font-medium">{u.name}</span>
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{u.email}</TableCell>
                  <TableCell>
                    <Badge variant={u.role === 'admin' ? 'default' : 'secondary'}>
                      {u.role === 'admin' ? 'Admin' : 'User'}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {u.assignedDeliveryIds.length === 0 ? (
                      <span className="text-muted-foreground text-sm">None</span>
                    ) : (
                      <div className="flex flex-wrap gap-1">
                        {u.assignedDeliveryIds.map((id) => {
                          const d = deliveries.find((x) => Number(x.id) === id);
                          return (
                            <Badge key={id} variant="outline" className="gap-1">
                              <Package className="w-3 h-3" />
                              {d?.name ?? `#${id}`}
                            </Badge>
                          );
                        })}
                      </div>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    <Button size="sm" variant="outline" onClick={() => setAssignFor(u)}>
                      Assign deliveries…
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {assignFor && (
        <AssignDeliveriesDialog
          user={assignFor}
          deliveries={deliveries}
          brandNames={brandNames}
          onClose={() => setAssignFor(null)}
          onSaved={() => {
            setAssignFor(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}

function AssignDeliveriesDialog({
  user, deliveries, brandNames, onClose, onSaved,
}: {
  user: ManagedUser;
  deliveries: DeliveryRow[];
  brandNames: Map<number, string>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [selected, setSelected] = useState<number[]>(user.assignedDeliveryIds);

  const assignMutation = trpc.users.assignDeliveries.useMutation({
    onSuccess: () => {
      toast.success(`Deliveries updated for ${user.name}.`);
      onSaved();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update assignments'),
  });

  const toggle = (id: number) =>
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Assign deliveries — {user.name}</DialogTitle>
          <DialogDescription>
            The user gets view + use access to each assigned delivery and its linked brand and project.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-72 space-y-1 overflow-y-auto py-2">
          {deliveries.length === 0 && (
            <p className="text-sm text-muted-foreground">
              No deliveries yet — create one in the Deliveries module first.
            </p>
          )}
          {deliveries.map((d) => (
            <div key={d.id} className="flex items-center gap-2 rounded-md border p-2">
              <Checkbox
                id={`assign-${d.id}`}
                checked={selected.includes(Number(d.id))}
                onCheckedChange={() => toggle(Number(d.id))}
              />
              <Label htmlFor={`assign-${d.id}`} className="flex-1 cursor-pointer">
                <span className="font-medium">{d.name}</span>
                <span className="ml-2 text-xs text-muted-foreground">
                  {d.clientName ? `${d.clientName} · ` : ''}
                  {d.brandId ? `Brand: ${brandNames.get(Number(d.brandId)) ?? `#${d.brandId}`}` : 'No brand linked'}
                </span>
              </Label>
              <Badge variant="outline" className="text-[10px] uppercase">{d.status}</Badge>
            </div>
          ))}
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={assignMutation.isLoading}>
            Cancel
          </Button>
          <Button
            onClick={() => assignMutation.mutate({ pcmId: user.pcmId, deliveryIds: selected })}
            disabled={assignMutation.isLoading}
            className="gap-2"
          >
            {assignMutation.isLoading && <Loader2 className="w-4 h-4 animate-spin" />}
            Save assignments
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

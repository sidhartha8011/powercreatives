/**
 * USERS MODULE — admin-only user management.
 *
 * Two kinds of users live here:
 *  - WordPress users, mirrored into the plugin (access level derived from WP
 *    capabilities; they sign in through WordPress).
 *  - Platform users, created right here with a username + password. These are
 *    the accounts a visitor uses at the [power_creatives] shortcode gate — no
 *    WordPress account needed.
 *
 * Admins can create/delete platform users, reset their passwords, and assign
 * deliveries to any user (granting view+use access to the delivery's brand/project).
 */

import { useMemo, useState } from 'react';
import { Users as UsersIcon, Loader2, Package, UserPlus, KeyRound, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
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
  isPlatformUser: boolean;
  username: string | null;
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
  const [passwordFor, setPasswordFor] = useState<ManagedUser | null>(null);
  const [addOpen, setAddOpen] = useState(false);

  const deleteMutation = trpc.users.delete.useMutation({
    onSuccess: () => {
      toast.success('User deleted.');
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete user'),
  });

  const handleDelete = (u: ManagedUser) => {
    if (!window.confirm(`Delete platform user “${u.name}” (${u.username})? This cannot be undone.`)) return;
    deleteMutation.mutate({ pcmId: u.pcmId });
  };

  const roleMutation = trpc.users.setRole.useMutation({
    onSuccess: (_d, vars: any) => {
      toast.success(`Access level updated to ${vars.role === 'admin' ? 'Admin' : 'User'}.`);
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update access level'),
  });

  const changeRole = (u: ManagedUser, role: 'admin' | 'user') => {
    if (role === u.role) return;
    if (role === 'admin' && !window.confirm(`Make “${u.name}” an admin? Admins can see and manage every user's work, assign deliveries, and create other users.`)) return;
    roleMutation.mutate({ pcmId: u.pcmId, role });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold flex items-center gap-2">
            <UsersIcon className="w-5 h-5 text-primary" /> Users
          </h2>
          <p className="text-sm text-muted-foreground">
            WordPress users are mirrored here automatically. Add platform users with a username
            and password so they can sign in at the shortcode page directly — no WordPress account
            needed. Assign deliveries to give a user access to that delivery&apos;s brand and project.
          </p>
        </div>
        <Button className="gap-2 shrink-0" onClick={() => setAddOpen(true)}>
          <UserPlus className="w-4 h-4" /> Add user
        </Button>
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
                <TableHead>Login</TableHead>
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
                      <div className="flex flex-col">
                        <span className="font-medium leading-tight">{u.name}</span>
                        {u.email && <span className="text-xs text-muted-foreground leading-tight">{u.email}</span>}
                      </div>
                    </div>
                  </TableCell>
                  <TableCell>
                    {u.isPlatformUser ? (
                      <div className="flex items-center gap-2">
                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{u.username}</code>
                        <Badge variant="outline" className="text-[10px] uppercase">Platform</Badge>
                      </div>
                    ) : (
                      <Badge variant="secondary" className="text-[10px] uppercase">WordPress</Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    {u.isPlatformUser ? (
                      <Select
                        value={u.role}
                        onValueChange={(v) => changeRole(u, v as 'admin' | 'user')}
                        disabled={roleMutation.isLoading}
                      >
                        <SelectTrigger className="w-[104px]">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="user">User</SelectItem>
                          <SelectItem value="admin">Admin</SelectItem>
                        </SelectContent>
                      </Select>
                    ) : (
                      <Badge variant={u.role === 'admin' ? 'default' : 'secondary'}>
                        {u.role === 'admin' ? 'Admin' : 'User'}
                      </Badge>
                    )}
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
                    <div className="flex items-center justify-end gap-1">
                      <Button size="sm" variant="outline" onClick={() => setAssignFor(u)}>
                        Assign deliveries…
                      </Button>
                      {u.isPlatformUser && (
                        <>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="gap-1"
                            onClick={() => setPasswordFor(u)}
                            title="Reset password"
                          >
                            <KeyRound className="w-4 h-4" />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="gap-1 text-destructive hover:text-destructive"
                            onClick={() => handleDelete(u)}
                            title="Delete user"
                          >
                            <Trash2 className="w-4 h-4" />
                          </Button>
                        </>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {addOpen && (
        <AddUserDialog
          onClose={() => setAddOpen(false)}
          onSaved={() => {
            setAddOpen(false);
            refetch();
          }}
        />
      )}

      {passwordFor && (
        <ResetPasswordDialog
          user={passwordFor}
          onClose={() => setPasswordFor(null)}
          onSaved={() => setPasswordFor(null)}
        />
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

function AddUserDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [username, setUsername] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState<'admin' | 'user'>('user');

  const createMutation = trpc.users.create.useMutation({
    onSuccess: () => {
      toast.success(`User “${username}” created.`);
      onSaved();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to create user'),
  });

  const usernameValid = /^[a-z0-9._-]{3,191}$/.test(username);
  const canSubmit = usernameValid && password.length >= 8 && !createMutation.isLoading;

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Add platform user</DialogTitle>
          <DialogDescription>
            The user signs in at the shortcode page with this username and password.
            Give the credentials to them directly — the password is not shown again.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-2">
          <div className="space-y-1">
            <Label htmlFor="new-username">Username</Label>
            <Input
              id="new-username"
              value={username}
              autoComplete="off"
              placeholder="jane.doe"
              onChange={(e) => setUsername(e.target.value.toLowerCase())}
            />
            {username && !usernameValid && (
              <p className="text-xs text-destructive">
                3–191 chars: lowercase letters, digits, dot, underscore or hyphen.
              </p>
            )}
          </div>
          <div className="space-y-1">
            <Label htmlFor="new-name">Display name</Label>
            <Input
              id="new-name"
              value={name}
              placeholder="Jane Doe"
              onChange={(e) => setName(e.target.value)}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="new-email">Email <span className="text-muted-foreground">(optional)</span></Label>
            <Input
              id="new-email"
              type="email"
              value={email}
              placeholder="jane@example.com"
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="new-password">Password</Label>
            <Input
              id="new-password"
              type="text"
              value={password}
              autoComplete="new-password"
              placeholder="At least 8 characters"
              onChange={(e) => setPassword(e.target.value)}
            />
            {password && password.length < 8 && (
              <p className="text-xs text-destructive">Password must be at least 8 characters.</p>
            )}
          </div>
          <div className="space-y-1">
            <Label htmlFor="new-role">Access level</Label>
            <Select value={role} onValueChange={(v) => setRole(v as 'admin' | 'user')}>
              <SelectTrigger id="new-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="user">User — works in their own assigned deliveries</SelectItem>
                <SelectItem value="admin">Admin — reviews everyone &amp; manages users</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={createMutation.isLoading}>
            Cancel
          </Button>
          <Button
            className="gap-2"
            disabled={!canSubmit}
            onClick={() => createMutation.mutate({ username, name, email, password, role })}
          >
            {createMutation.isLoading && <Loader2 className="w-4 h-4 animate-spin" />}
            Create user
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ResetPasswordDialog({
  user, onClose, onSaved,
}: {
  user: ManagedUser;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [password, setPassword] = useState('');

  const mutation = trpc.users.setPassword.useMutation({
    onSuccess: () => {
      toast.success(`Password updated for ${user.name}.`);
      onSaved();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update password'),
  });

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Reset password — {user.name}</DialogTitle>
          <DialogDescription>
            Set a new password for <code className="rounded bg-muted px-1 py-0.5">{user.username}</code>.
            Share it with the user directly.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1 py-2">
          <Label htmlFor="reset-password">New password</Label>
          <Input
            id="reset-password"
            type="text"
            value={password}
            autoComplete="new-password"
            placeholder="At least 8 characters"
            onChange={(e) => setPassword(e.target.value)}
          />
          {password && password.length < 8 && (
            <p className="text-xs text-destructive">Password must be at least 8 characters.</p>
          )}
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={mutation.isLoading}>
            Cancel
          </Button>
          <Button
            className="gap-2"
            disabled={password.length < 8 || mutation.isLoading}
            onClick={() => mutation.mutate({ pcmId: user.pcmId, password })}
          >
            {mutation.isLoading && <Loader2 className="w-4 h-4 animate-spin" />}
            Update password
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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

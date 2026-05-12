import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';

export function useProjectActions() {
  const utils = trpc.useUtils();

  // Invalidate the generic projects list cache whenever a project is mutated
  const invalidateProjects = () => {
    utils.assets.getProjects.invalidate();
  };

  const deleteMutation = trpc.assets.deleteProject.useMutation({
    onSuccess: () => {
      toast.success('Project deleted');
      invalidateProjects();
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to delete project');
    },
  });

  const renameMutation = trpc.assets.renameProject.useMutation({
    onSuccess: () => {
      toast.success('Project renamed successfully');
      invalidateProjects();
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to rename project');
    },
  });

  const duplicateMutation = trpc.assets.duplicateProject.useMutation({
    onSuccess: () => {
      toast.success('Project duplicated');
      invalidateProjects();
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to duplicate project');
    },
  });

  return {
    deleteProject: deleteMutation.mutate,
    isDeleting: deleteMutation.isPending,
    renameProject: renameMutation.mutate,
    isRenaming: renameMutation.isPending,
    duplicateProject: duplicateMutation.mutate,
    isDuplicating: duplicateMutation.isPending,
  };
}

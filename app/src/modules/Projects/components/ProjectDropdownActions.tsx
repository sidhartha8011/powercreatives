import { MoreHorizontal, Pencil, Copy, Trash, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

interface ProjectDropdownActionsProps {
  onRename: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
  isDuplicating?: boolean;
  isDeleting?: boolean;
}

export function ProjectDropdownActions({
  onRename,
  onDuplicate,
  onDelete,
  isDuplicating,
  isDeleting,
}: ProjectDropdownActionsProps) {
  return (
    <div onClick={(e) => e.stopPropagation()}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-slate-500 hover:text-slate-800 transition-colors"
          >
            {isDuplicating || isDeleting ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <MoreHorizontal className="w-4 h-4" />
            )}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-48 bg-white border border-slate-200">
          <DropdownMenuItem onSelect={onRename} className="gap-2 cursor-pointer hover:bg-slate-50">
            <Pencil className="w-4 h-4" />
            <span>Rename Project</span>
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={onDuplicate} className="gap-2 cursor-pointer hover:bg-slate-50" disabled={isDuplicating}>
            <Copy className="w-4 h-4" />
            <span>Duplicate</span>
          </DropdownMenuItem>
          
          <DropdownMenuSeparator className="bg-slate-100" />
          
          <DropdownMenuItem onSelect={onDelete} className="gap-2 cursor-pointer text-red-600 hover:bg-red-50 hover:text-red-700" disabled={isDeleting}>
            <Trash className="w-4 h-4" />
            <span>Delete Project</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

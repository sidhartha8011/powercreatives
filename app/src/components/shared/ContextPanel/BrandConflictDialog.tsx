import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import type { ScrapedBusinessData } from "./types";

interface Props {
  pendingBrandData: {
    scrapedData: ScrapedBusinessData;
    existingBrandId: number;
    existingBrandName: string;
    normalizedUrl: string;
  } | null;
  setPendingBrandData: (data: any) => void;
  handleCreateNew: () => void;
  handleUpdateExisting: () => void;
}

export function BrandConflictDialog({
  pendingBrandData,
  setPendingBrandData,
  handleCreateNew,
  handleUpdateExisting,
}: Props) {
  return (
    <AlertDialog
      open={!!pendingBrandData}
      onOpenChange={(open) => {
        if (!open) setPendingBrandData(null);
      }}
    >
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Brand Already Exists</AlertDialogTitle>
          <AlertDialogDescription>
            A brand named &ldquo;{pendingBrandData?.existingBrandName}&rdquo;
            already exists for this URL. Would you like to update it with the
            new data, or create a separate brand?
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={() => setPendingBrandData(null)}>
            Skip
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={handleCreateNew}
            className="bg-secondary text-secondary-foreground hover:bg-secondary/80"
          >
            Create New
          </AlertDialogAction>
          <AlertDialogAction onClick={handleUpdateExisting}>
            Update Existing
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

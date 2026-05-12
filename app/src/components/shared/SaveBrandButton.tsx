/**
 * SAVE BRAND BUTTON — Save sidebar form fields to a brand profile
 *
 * Renders a save button in the Copy module sidebar.
 * Uses FORM_TO_BRAND_MAP to convert form field values → brand columns.
 *
 * Behavior:
 *   - If a brand is currently selected → "Update [Name]" with dropdown option "Save as new"
 *   - If no brand selected → "Save as new brand"
 *   - Hidden when business_name is empty
 *   - After save: callback to invalidate brand cache + toast confirmation
 *
 * Uses existing UI components: Button, DropdownMenu (shadcn/Radix).
 *
 * @package PowerCreatives
 */

import { useState, useCallback } from 'react';
import { Save, ChevronDown } from 'lucide-react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';
import { FORM_TO_BRAND_MAP } from '@shared/brandTypes';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// ============================================
// Types
// ============================================

interface SaveBrandButtonProps {
    /** Current sidebar form values (keyed by field ID) */
    formValues: Record<string, string | number | undefined>;
    /** Currently selected brand ID (from ContextPanel), undefined if none */
    selectedBrandId?: number;
    /** Currently selected brand name for display */
    selectedBrandName?: string;
    /** Callback after a brand is saved/updated — for cache invalidation */
    onBrandSaved?: (brandId: number) => void;
}

// ============================================
// Helpers
// ============================================

/**
 * Convert form field values to brand column format using FORM_TO_BRAND_MAP.
 * Only includes fields that have non-empty values.
 */
function formValuesToBrandData(
    formValues: Record<string, string | number | undefined>
): Record<string, string> {
    const brandData: Record<string, string> = {};
    for (const [formKey, brandCol] of Object.entries(FORM_TO_BRAND_MAP)) {
        const value = formValues[formKey];
        if (value !== undefined && value !== null && String(value).trim() !== '') {
            brandData[brandCol] = String(value);
        }
    }
    return brandData;
}

// ============================================
// Component
// ============================================

export function SaveBrandButton({
    formValues,
    selectedBrandId,
    selectedBrandName,
    onBrandSaved,
}: SaveBrandButtonProps) {
    const [isSaving, setIsSaving] = useState(false);

    // tRPC mutations + query utils for cache invalidation
    const createMutation = trpc.brands.create.useMutation();
    const updateMutation = trpc.brands.update.useMutation();
    const utils = trpc.useUtils();

    // Derive state
    const businessName = String(formValues.business_name ?? '').trim();
    const canSave = businessName.length > 0 && !isSaving;
    const hasBrand = selectedBrandId !== undefined && selectedBrandId > 0;

    /** Save form fields as a NEW brand */
    const handleSaveNew = useCallback(async () => {
        if (!canSave) return;
        setIsSaving(true);
        try {
            const brandData = formValuesToBrandData(formValues);
            const result = await createMutation.mutateAsync(brandData);
            const newId = (result as any)?.id ?? (result as any)?.brand?.id;
            // Invalidate brand list cache so dropdowns refresh
            await utils.brands.list.invalidate();
            toast.success(`Brand "${businessName}" created`);
            if (newId && onBrandSaved) onBrandSaved(newId);
        } catch (err: any) {
            toast.error(`Failed to save brand: ${err.message}`);
        } finally {
            setIsSaving(false);
        }
    }, [canSave, formValues, businessName, createMutation, utils, onBrandSaved]);

    /** Update the currently selected brand with form field values */
    const handleUpdate = useCallback(async () => {
        if (!canSave || !selectedBrandId) return;
        setIsSaving(true);
        try {
            const brandData = formValuesToBrandData(formValues);
            await updateMutation.mutateAsync({ id: selectedBrandId, ...brandData });
            // Invalidate brand list cache so dropdowns refresh
            await utils.brands.list.invalidate();
            toast.success(`Brand "${selectedBrandName}" updated`);
            if (onBrandSaved) onBrandSaved(selectedBrandId);
        } catch (err: any) {
            toast.error(`Failed to update brand: ${err.message}`);
        } finally {
            setIsSaving(false);
        }
    }, [canSave, selectedBrandId, selectedBrandName, formValues, updateMutation, utils, onBrandSaved]);

    // Don't render if no business name is entered
    if (!canSave && !isSaving) return null;

    // ── No brand selected → simple "Save as new" button ──
    if (!hasBrand) {
        return (
            <Button variant="outline" size="sm" onClick={handleSaveNew} disabled={isSaving}>
                <Save className="w-3.5 h-3.5" />
                {isSaving ? 'Saving…' : 'Save as new brand'}
            </Button>
        );
    }

    // ── Brand selected → split: "Update" primary + dropdown for "Save as new" ──
    return (
        <div className="flex items-center gap-0">
            <Button
                variant="outline"
                size="sm"
                onClick={handleUpdate}
                disabled={isSaving}
                className="rounded-r-none border-r-0"
            >
                <Save className="w-3.5 h-3.5" />
                {isSaving ? 'Saving…' : `Update "${selectedBrandName}"`}
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="sm" disabled={isSaving} className="rounded-l-none px-1.5">
                        <ChevronDown className="w-3.5 h-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuItem onClick={handleSaveNew}>
                        Save as new brand
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

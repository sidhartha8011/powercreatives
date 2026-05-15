/**
 * BrandContextAccordion — Unified brand context panel for Image module
 *
 * Renders all brand data fields in a collapsible accordion with per-field
 * toggle switches. Each toggle controls whether that data point is injected
 * into the AI generation prompt.
 *
 * UI pattern: Mirrors the ContextPanel's brand identity display but adds
 * granular injection control and edit/upload capabilities.
 *
 * All logic lives in useBrandContext hook — this component is purely
 * presentational ("dumb UI" per architecture.md).
 */

import { useRef } from 'react';
import { Switch } from '@/components/ui/switch';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { BrandColorSwatches } from '@/components/shared/BrandColorSwatches';
import {
    Palette, FileText, Building2, Tag, MapPin, Phone, Globe,
    Languages, Droplets, ImageIcon, Camera, ShieldCheck,
    ChevronDown, X, Info,
} from 'lucide-react';
import type { BrandAsset } from '@shared/brandTypes';
import type { UseBrandContextReturn, BrandContextField } from '../hooks/useBrandContext';

// ============================================================================
// Props
// ============================================================================

interface BrandContextAccordionProps {
    /** All state/handlers from useBrandContext */
    ctx: UseBrandContextReturn;
    /** File upload handler for logo/subject/cert (from useImageAssets) */
    onFileUpload: (type: 'logo' | 'subject' | 'cert', e: React.ChangeEvent<HTMLInputElement>) => void;
}

// ============================================================================
// Inline brand asset picker — simplified popover for picking from brand assets
// ============================================================================

function BrandAssetPickerInline({
    assets,
    onPick,
    label = 'From Brand',
}: {
    assets: BrandAsset[];
    onPick: (base64: string) => void;
    label?: string;
}) {
    if (assets.length === 0) return null;

    /** Fetch remote URL → base64 data URI */
    const pickAsset = async (url: string) => {
        try {
            const res = await fetch(url);
            const blob = await res.blob();
            const reader = new FileReader();
            reader.onloadend = () => {
                if (typeof reader.result === 'string') onPick(reader.result);
            };
            reader.readAsDataURL(blob);
        } catch {
            /* Non-fatal — user can still upload manually */
        }
    };

    return (
        <button
            onClick={() => {
                // Pick first asset for simplicity — could be extended to popover
                if (assets.length === 1) {
                    pickAsset(assets[0].url);
                } else {
                    // For multiple assets, pick first (future: show picker popover)
                    pickAsset(assets[0].url);
                }
            }}
            className="text-[10px] text-muted-foreground hover:text-primary transition-colors"
        >
            {label}
        </button>
    );
}

// ============================================================================
// Field row component — single reusable row with toggle + icon + label + value
// ============================================================================

function FieldRow({
    field,
    icon: Icon,
    label,
    value,
    isOn,
    onToggle,
    actions,
    children,
}: {
    field: BrandContextField;
    icon: React.ElementType;
    label: string;
    value?: string;
    isOn: boolean;
    onToggle: (field: BrandContextField, v: boolean) => void;
    actions?: React.ReactNode;
    children?: React.ReactNode;
}) {
    return (
        <div
            className={`flex items-center gap-2 px-3 py-[6px] border-b border-border/30 last:border-b-0 transition-opacity ${
                !isOn ? 'opacity-35' : ''
            }`}
        >
            {/* Toggle — compact 24×14 equivalent via scale */}
            <Switch
                checked={isOn}
                onCheckedChange={(v) => onToggle(field, v)}
                className="h-3.5 w-6 data-[state=checked]:bg-green-600 data-[state=unchecked]:bg-border [&>span]:h-2.5 [&>span]:w-2.5"
            />
            {/* Body */}
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-1 text-[11px] font-medium text-muted-foreground">
                    <Icon className="w-[11px] h-[11px] text-muted-foreground/50" />
                    {label}
                </div>
                {value && (
                    <p className="text-[10px] text-muted-foreground/70 leading-snug mt-0.5 line-clamp-2">
                        {value}
                    </p>
                )}
                {children}
            </div>
            {/* Right-side actions */}
            {actions && <div className="flex items-center gap-1.5 shrink-0">{actions}</div>}
        </div>
    );
}

// ============================================================================
// Main Component
// ============================================================================

export function BrandContextAccordion({ ctx, onFileUpload }: BrandContextAccordionProps) {
    const logoInputRef = useRef<HTMLInputElement>(null);
    const subjectInputRef = useRef<HTMLInputElement>(null);
    const badgeInputRef = useRef<HTMLInputElement>(null);

    // Don't render if no brand selected
    if (!ctx.hasBrand) return null;

    return (
        <Collapsible defaultOpen={true} className="group/brand-ctx">
            {/* ── Header ── */}
            <CollapsibleTrigger className="flex items-center justify-between w-full px-3 py-2 bg-muted/40 border border-border rounded-t-lg group-data-[state=closed]/brand-ctx:rounded-b-lg text-[10px] font-semibold uppercase tracking-wider text-muted-foreground hover:text-foreground transition-colors">
                <span className="flex items-center gap-1.5">
                    <Palette className="w-3 h-3" />
                    Brand Context
                </span>
                <div className="flex items-center gap-1.5">
                    {/* Master toggle — stop propagation to prevent accordion toggle */}
                    <div onClick={(e) => e.stopPropagation()}>
                        <Switch
                            checked={ctx.masterToggle}
                            onCheckedChange={ctx.setMasterToggle}
                            className="h-3.5 w-6 data-[state=checked]:bg-green-600 data-[state=unchecked]:bg-border [&>span]:h-2.5 [&>span]:w-2.5"
                        />
                    </div>
                    <ChevronDown className="w-3 h-3 transition-transform group-data-[state=open]/brand-ctx:rotate-180" />
                </div>
            </CollapsibleTrigger>

            {/* ── Content ── */}
            <CollapsibleContent>
                <div className="border border-t-0 border-border rounded-b-lg overflow-hidden">

                    {/* Business Name */}
                    <FieldRow
                        field="business_name"
                        icon={Building2}
                        label="Business Name"
                        value={ctx.brandFields.business_name}
                        isOn={ctx.toggles.business_name}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Niche */}
                    <FieldRow
                        field="niche"
                        icon={Tag}
                        label="Niche"
                        value={ctx.brandFields.niche}
                        isOn={ctx.toggles.niche}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Summary */}
                    <FieldRow
                        field="business_summary"
                        icon={FileText}
                        label="Summary"
                        value={ctx.brandFields.business_summary}
                        isOn={ctx.toggles.business_summary}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Location */}
                    <FieldRow
                        field="location"
                        icon={MapPin}
                        label="Location"
                        value={ctx.brandFields.location}
                        isOn={ctx.toggles.location}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Phone */}
                    <FieldRow
                        field="phone"
                        icon={Phone}
                        label="Phone"
                        value={ctx.brandFields.phone}
                        isOn={ctx.toggles.phone}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Website */}
                    <FieldRow
                        field="website"
                        icon={Globe}
                        label="Website"
                        value={ctx.brandFields.website}
                        isOn={ctx.toggles.website}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Language */}
                    <FieldRow
                        field="language"
                        icon={Languages}
                        label="Language"
                        value={ctx.brandFields.language}
                        isOn={ctx.toggles.language}
                        onToggle={ctx.setFieldToggle}
                    />

                    {/* Colors — swatches + add */}
                    <FieldRow
                        field="colors"
                        icon={Droplets}
                        label="Colors"
                        isOn={ctx.toggles.colors}
                        onToggle={ctx.setFieldToggle}
                        actions={
                            <button className="text-[10px] text-muted-foreground hover:text-primary transition-colors">
                                Edit
                            </button>
                        }
                    >
                        {ctx.brandColors.length > 0 && (
                            <div className="mt-1">
                                <BrandColorSwatches colors={ctx.brandColors} size="md" />
                            </div>
                        )}
                    </FieldRow>

                    {/* Logo — thumbnail + From Brand / Upload */}
                    <FieldRow
                        field="logo"
                        icon={ImageIcon}
                        label="Logo"
                        isOn={ctx.toggles.logo}
                        onToggle={ctx.setFieldToggle}
                        actions={
                            <>
                                <BrandAssetPickerInline
                                    assets={ctx.brandAssets}
                                    onPick={(b64) => ctx.setLogoBase64(b64)}
                                />
                                <button
                                    onClick={() => logoInputRef.current?.click()}
                                    className="text-[10px] text-muted-foreground hover:text-primary transition-colors"
                                >
                                    Upload
                                </button>
                                <input
                                    ref={logoInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (!file) return;
                                        const reader = new FileReader();
                                        reader.onloadend = () => {
                                            if (typeof reader.result === 'string') ctx.setLogoBase64(reader.result);
                                        };
                                        reader.readAsDataURL(file);
                                        e.target.value = '';
                                    }}
                                />
                            </>
                        }
                    >
                        {ctx.logoBase64 && (
                            <div className="flex items-center gap-2 mt-1">
                                <img
                                    src={ctx.logoBase64}
                                    alt="Logo"
                                    className="w-5 h-5 object-contain rounded border border-border"
                                />
                            </div>
                        )}
                    </FieldRow>

                    {/* Subject — From Brand / Upload */}
                    <FieldRow
                        field="subject"
                        icon={Camera}
                        label="Subject"
                        isOn={ctx.toggles.subject}
                        onToggle={ctx.setFieldToggle}
                        actions={
                            <>
                                <BrandAssetPickerInline
                                    assets={ctx.brandAssets}
                                    onPick={(b64) => ctx.setSubjectBase64(b64)}
                                />
                                <button
                                    onClick={() => subjectInputRef.current?.click()}
                                    className="text-[10px] text-muted-foreground hover:text-primary transition-colors"
                                >
                                    Upload
                                </button>
                                <input
                                    ref={subjectInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (!file) return;
                                        const reader = new FileReader();
                                        reader.onloadend = () => {
                                            if (typeof reader.result === 'string') ctx.setSubjectBase64(reader.result);
                                        };
                                        reader.readAsDataURL(file);
                                        e.target.value = '';
                                    }}
                                />
                            </>
                        }
                    >
                        {ctx.subjectBase64 && (
                            <div className="flex items-center gap-2 mt-1">
                                <img
                                    src={ctx.subjectBase64}
                                    alt="Subject"
                                    className="w-5 h-5 object-contain rounded border border-border"
                                />
                            </div>
                        )}
                    </FieldRow>

                    {/* Badges — From Brand / Add + thumbnails */}
                    <FieldRow
                        field="badges"
                        icon={ShieldCheck}
                        label="Badges"
                        isOn={ctx.toggles.badges}
                        onToggle={ctx.setFieldToggle}
                        actions={
                            <>
                                <BrandAssetPickerInline
                                    assets={ctx.brandAssets}
                                    onPick={(b64) => ctx.addBadge(b64)}
                                />
                                <button
                                    onClick={() => badgeInputRef.current?.click()}
                                    className="text-[10px] text-muted-foreground hover:text-primary transition-colors"
                                >
                                    Add
                                </button>
                                <input
                                    ref={badgeInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (!file) return;
                                        const reader = new FileReader();
                                        reader.onloadend = () => {
                                            if (typeof reader.result === 'string') ctx.addBadge(reader.result);
                                        };
                                        reader.readAsDataURL(file);
                                        e.target.value = '';
                                    }}
                                />
                            </>
                        }
                    >
                        {ctx.badgeImages.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 mt-1">
                                {ctx.badgeImages.map((badge) => (
                                    <div key={badge.id} className="relative group">
                                        <img
                                            src={badge.base64}
                                            alt="Badge"
                                            className="w-6 h-6 object-contain rounded border border-border"
                                        />
                                        <button
                                            onClick={() => ctx.removeBadge(badge.id)}
                                            className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-destructive text-destructive-foreground rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                                        >
                                            <X className="w-2 h-2" />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </FieldRow>

                    {/* Footer — injection indicator */}
                    <div className="flex items-center gap-1 px-3 py-1.5 bg-muted/30 text-[9px] text-muted-foreground/50">
                        <Info className="w-[9px] h-[9px]" />
                        Injected into prompt
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

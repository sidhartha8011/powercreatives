/**
 * USE SELECTION HOOK
 *
 * Generic multi-level selection state manager for copy results.
 * Supports cascade selection: type → audiences → cards.
 *
 * Design:
 *   - Selection is stored as a flat Set<string> of card IDs (source of truth)
 *   - Type and audience "selected" states are derived from card selection
 *   - Checking a type selects all cards under all audiences of that type
 *   - Checking an audience selects all cards under that audience
 *   - Unchecking a type/audience removes all its child cards
 *
 * This hook is intentionally decoupled from Copy-specific types.
 * It works with any data that has { id, groupKey, subGroupKey } shape.
 */

import { useState, useCallback, useMemo } from 'react';
import type { CopyVariation, CopyResults, CopyType } from './types';

export interface UseSelectionReturn {
  /** Set of selected card IDs */
  selectedCards: Set<string>;
  /** Number of selected cards */
  selectedCount: number;
  /** Whether any cards are selected */
  hasSelection: boolean;

  // ── Card-level ──
  /** Check if a specific card is selected */
  isCardSelected: (cardId: string) => boolean;
  /** Toggle a single card's selection */
  toggleCard: (cardId: string) => void;

  // ── Audience-level ──
  /** Check if all cards in an audience are selected */
  isAudienceSelected: (type: CopyType, audienceId: string) => boolean;
  /** Check if some (but not all) cards in an audience are selected */
  isAudienceIndeterminate: (type: CopyType, audienceId: string) => boolean;
  /** Toggle all cards in an audience */
  toggleAudience: (type: CopyType, audienceId: string) => void;

  // ── Type-level ──
  /** Check if all cards in a type are selected */
  isTypeSelected: (type: CopyType) => boolean;
  /** Check if some (but not all) cards in a type are selected */
  isTypeIndeterminate: (type: CopyType) => boolean;
  /** Toggle all cards in a type */
  toggleType: (type: CopyType) => void;

  // ── Bulk ──
  /** Select all cards across all types */
  selectAll: () => void;
  /** Clear all selections */
  clearSelection: () => void;

  // ── Derived data ──
  /** Get all selected variation objects */
  getSelectedVariations: () => CopyVariation[];
  /** Get selected card IDs as an array */
  getSelectedIds: () => string[];
  /** Get unique audience IDs that have at least one selected card */
  getSelectedAudienceIds: () => string[];
}

export function useSelection(results: CopyResults): UseSelectionReturn {
  const [selectedCards, setSelectedCards] = useState<Set<string>>(new Set());

  // ── Flat list of all variations for lookups ──
  const allVariations = useMemo(() => {
    const flat: CopyVariation[] = [];
    for (const type of Object.keys(results) as CopyType[]) {
      const typeResults = results[type];
      if (typeResults) flat.push(...typeResults);
    }
    return flat;
  }, [results]);

  // ── Index: audienceId → card IDs per type ──
  const audienceCardIndex = useMemo(() => {
    const index = new Map<string, Set<string>>(); // key = `${type}::${audienceId}`
    for (const v of allVariations) {
      const key = `${v.copyType}::${v.audienceId}`;
      if (!index.has(key)) index.set(key, new Set());
      index.get(key)!.add(v.id);
    }
    return index;
  }, [allVariations]);

  // ── Index: type → card IDs ──
  const typeCardIndex = useMemo(() => {
    const index = new Map<CopyType, Set<string>>();
    for (const v of allVariations) {
      if (!index.has(v.copyType)) index.set(v.copyType, new Set());
      index.get(v.copyType)!.add(v.id);
    }
    return index;
  }, [allVariations]);

  const selectedCount = selectedCards.size;
  const hasSelection = selectedCount > 0;

  // ── Card-level ──
  const isCardSelected = useCallback(
    (cardId: string) => selectedCards.has(cardId),
    [selectedCards],
  );

  const toggleCard = useCallback((cardId: string) => {
    setSelectedCards((prev) => {
      const next = new Set(prev);
      if (next.has(cardId)) {
        next.delete(cardId);
      } else {
        next.add(cardId);
      }
      return next;
    });
  }, []);

  // ── Audience-level ──
  const isAudienceSelected = useCallback(
    (type: CopyType, audienceId: string) => {
      const key = `${type}::${audienceId}`;
      const cardIds = audienceCardIndex.get(key);
      if (!cardIds || cardIds.size === 0) return false;
      return Array.from(cardIds).every((id) => selectedCards.has(id));
    },
    [selectedCards, audienceCardIndex],
  );

  const isAudienceIndeterminate = useCallback(
    (type: CopyType, audienceId: string) => {
      const key = `${type}::${audienceId}`;
      const cardIds = audienceCardIndex.get(key);
      if (!cardIds || cardIds.size === 0) return false;
      const arr = Array.from(cardIds);
      const someSelected = arr.some((id) => selectedCards.has(id));
      const allSelected = arr.every((id) => selectedCards.has(id));
      return someSelected && !allSelected;
    },
    [selectedCards, audienceCardIndex],
  );

  const toggleAudience = useCallback(
    (type: CopyType, audienceId: string) => {
      const key = `${type}::${audienceId}`;
      const cardIds = audienceCardIndex.get(key);
      if (!cardIds) return;

      setSelectedCards((prev) => {
        const next = new Set(prev);
        const cardIdArr = Array.from(cardIds);
        const allSelected = cardIdArr.every((id) => next.has(id));
        if (allSelected) {
          cardIdArr.forEach((id) => next.delete(id));
        } else {
          cardIdArr.forEach((id) => next.add(id));
        }
        return next;
      });
    },
    [audienceCardIndex],
  );

  // ── Type-level ──
  const isTypeSelected = useCallback(
    (type: CopyType) => {
      const cardIds = typeCardIndex.get(type);
      if (!cardIds || cardIds.size === 0) return false;
      return Array.from(cardIds).every((id) => selectedCards.has(id));
    },
    [selectedCards, typeCardIndex],
  );

  const isTypeIndeterminate = useCallback(
    (type: CopyType) => {
      const cardIds = typeCardIndex.get(type);
      if (!cardIds || cardIds.size === 0) return false;
      const arr = Array.from(cardIds);
      const someSelected = arr.some((id) => selectedCards.has(id));
      const allSelected = arr.every((id) => selectedCards.has(id));
      return someSelected && !allSelected;
    },
    [selectedCards, typeCardIndex],
  );

  const toggleType = useCallback(
    (type: CopyType) => {
      const cardIds = typeCardIndex.get(type);
      if (!cardIds) return;

      setSelectedCards((prev) => {
        const next = new Set(prev);
        const cardIdArr = Array.from(cardIds);
        const allSelected = cardIdArr.every((id) => next.has(id));
        if (allSelected) {
          cardIdArr.forEach((id) => next.delete(id));
        } else {
          cardIdArr.forEach((id) => next.add(id));
        }
        return next;
      });
    },
    [typeCardIndex],
  );

  // ── Bulk ──
  const selectAll = useCallback(() => {
    setSelectedCards(new Set(allVariations.map((v) => v.id)));
  }, [allVariations]);

  const clearSelection = useCallback(() => {
    setSelectedCards(new Set());
  }, []);

  // ── Derived data ──
  const getSelectedVariations = useCallback(() => {
    return allVariations.filter((v) => selectedCards.has(v.id));
  }, [allVariations, selectedCards]);

  const getSelectedIds = useCallback(() => {
    return Array.from(selectedCards);
  }, [selectedCards]);

  const getSelectedAudienceIds = useCallback(() => {
    const audienceIds = new Set<string>();
    for (const v of allVariations) {
      if (selectedCards.has(v.id)) {
        audienceIds.add(v.audienceId);
      }
    }
    return Array.from(audienceIds);
  }, [allVariations, selectedCards]);

  return {
    selectedCards,
    selectedCount,
    hasSelection,
    isCardSelected,
    toggleCard,
    isAudienceSelected,
    isAudienceIndeterminate,
    toggleAudience,
    isTypeSelected,
    isTypeIndeterminate,
    toggleType,
    selectAll,
    clearSelection,
    getSelectedVariations,
    getSelectedIds,
    getSelectedAudienceIds,
  };
}

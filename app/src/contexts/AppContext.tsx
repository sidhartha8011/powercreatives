/**
 * CREATIVE MACHINE - App Context
 * Central state management for the modular architecture.
 *
 * Responsibilities:
 * - App-wide settings (theme, language, defaults)
 * - Navigation (active module)
 * - Projects (local project state)
 * - Cross-module data transfer (Image → Video)
 * - Event system for module communication
 *
 * IMPORTANT: Integrations and models are NO LONGER managed here.
 * They live in the database and are accessed via tRPC hooks:
 *   - Integrations: `trpc.integrations.list` (Integration Center)
 *   - Models:       `useImageModelsForGeneration()`, `useTextModels()`, etc.
 */

import React, { createContext, useContext, useReducer, useCallback, useEffect } from 'react';
import type {
  AppSettings,
  ModuleId,
  AppEvent,
  AppEventType,
} from '@/types';

// ============================================
// State Types
// ============================================

/** Cross-module data transfer (e.g., Image → Video) */
export interface PendingVideoData {
  prompt: string;
  imageUrl: string;
  sourceModule: 'image';
}

/** A single document to create in the Writer queue */
export interface PendingWriterDocument {
  primaryKeyword: string;
  supportingKeywords: string[];
}

/** Cross-module data transfer (Keywords → Writer). Supports 1-to-N documents. */
export interface PendingWriterData {
  documents: PendingWriterDocument[];
  brandId?: number;
  siteId?: number;
  siteUrl?: string;
  templateId?: number;
  sourceModule: 'keywords';
}

interface AppState {
  /** App-wide settings */
  settings: AppSettings;

  /** UI navigation */
  activeModule: ModuleId;
  isLoading: boolean;

  /** Cross-module data transfer */
  pendingVideoData: PendingVideoData | null;
  pendingWriterData: PendingWriterData | null;

  /** Event subscribers */
  eventListeners: Map<AppEventType, Set<(event: AppEvent) => void>>;
}

// ============================================
// Actions
// ============================================

type AppAction =
  | { type: 'SET_SETTINGS'; payload: Partial<AppSettings> }
  | { type: 'SET_ACTIVE_MODULE'; payload: ModuleId }
  | { type: 'SET_LOADING'; payload: boolean }
  | { type: 'SET_PENDING_VIDEO_DATA'; payload: PendingVideoData | null }
  | { type: 'SET_PENDING_WRITER_DATA'; payload: PendingWriterData | null };

// ============================================
// Initial State
// ============================================

const initialSettings: AppSettings = {
  theme: 'light',
  language: 'en',
  defaultImageModels: [],
  defaultVideoModel: null,
  defaultTextModel: null,
  defaultCopyMenuIntelligence: null,
  defaultCopyResearchModel: null,
  defaultVideoTextModel: null,
  defaultImageTextModel: null,
  defaultWriterModel: null,
  autoSave: true,
  notifications: true,
  token_budget_audience: 8192,
  token_budget_angle: 8192,
  token_budget_copy: 16384,
  token_budget_video: 4096,
  token_budget_writer: 16384,
};

const initialState: AppState = {
  settings: initialSettings,
  activeModule: 'projects',
  isLoading: false,
  pendingVideoData: null,
  pendingWriterData: null,
  eventListeners: new Map(),
};

// ============================================
// Reducer
// ============================================

function appReducer(state: AppState, action: AppAction): AppState {
  switch (action.type) {
    case 'SET_SETTINGS':
      return {
        ...state,
        settings: { ...state.settings, ...action.payload },
      };



    case 'SET_ACTIVE_MODULE':
      return {
        ...state,
        activeModule: action.payload,
      };

    case 'SET_LOADING':
      return {
        ...state,
        isLoading: action.payload,
      };

    case 'SET_PENDING_VIDEO_DATA':
      return {
        ...state,
        pendingVideoData: action.payload,
      };

    case 'SET_PENDING_WRITER_DATA':
      return {
        ...state,
        pendingWriterData: action.payload,
      };

    default:
      return state;
  }
}

// ============================================
// Context
// ============================================

interface AppContextValue {
  state: AppState;
  dispatch: React.Dispatch<AppAction>;

  /** Settings actions */
  updateSettings: (settings: Partial<AppSettings>) => void;

  /** Navigation */
  setActiveModule: (moduleId: ModuleId) => void;

  /** Cross-module data transfer */
  navigateToVideoWithImage: (prompt: string, imageUrl: string) => void;
  consumePendingVideoData: () => PendingVideoData | null;
  navigateToWriterWithKeywords: (data: Omit<PendingWriterData, 'sourceModule'>) => void;
  consumePendingWriterData: () => PendingWriterData | null;

  /** Event system */
  emit: (event: AppEvent) => void;
  subscribe: (eventType: AppEventType, callback: (event: AppEvent) => void) => () => void;
}

const AppContext = createContext<AppContextValue | null>(null);

// ============================================
// Provider
// ============================================

export function AppProvider({ children }: { children: React.ReactNode }) {
  const [state, dispatch] = useReducer(appReducer, initialState);

  // Settings actions
  const updateSettings = useCallback((settings: Partial<AppSettings>) => {
    dispatch({ type: 'SET_SETTINGS', payload: settings });
  }, []);



  // Navigation
  const setActiveModule = useCallback((moduleId: ModuleId) => {
    dispatch({ type: 'SET_ACTIVE_MODULE', payload: moduleId });
  }, []);

  // Cross-module data transfer: Image → Video
  const navigateToVideoWithImage = useCallback((prompt: string, imageUrl: string) => {
    dispatch({ type: 'SET_PENDING_VIDEO_DATA', payload: { prompt, imageUrl, sourceModule: 'image' } });
    dispatch({ type: 'SET_ACTIVE_MODULE', payload: 'video' });
  }, []);

  const consumePendingVideoData = useCallback((): PendingVideoData | null => {
    const data = state.pendingVideoData;
    if (data) {
      dispatch({ type: 'SET_PENDING_VIDEO_DATA', payload: null });
    }
    return data;
  }, [state.pendingVideoData]);

  // Cross-module data transfer: Keywords → Writer
  const navigateToWriterWithKeywords = useCallback((data: Omit<PendingWriterData, 'sourceModule'>) => {
    dispatch({ type: 'SET_PENDING_WRITER_DATA', payload: { ...data, sourceModule: 'keywords' } });
    dispatch({ type: 'SET_ACTIVE_MODULE', payload: 'writer' });
  }, []);

  const consumePendingWriterData = useCallback((): PendingWriterData | null => {
    const data = state.pendingWriterData;
    if (data) {
      dispatch({ type: 'SET_PENDING_WRITER_DATA', payload: null });
    }
    return data;
  }, [state.pendingWriterData]);

  // Event system
  const emit = useCallback(
    (event: AppEvent) => {
      const listeners = state.eventListeners.get(event.type);
      if (listeners) {
        listeners.forEach((callback) => callback(event));
      }
    },
    [state.eventListeners],
  );

  const subscribe = useCallback(
    (eventType: AppEventType, callback: (event: AppEvent) => void) => {
      const listeners = state.eventListeners.get(eventType) || new Set();
      listeners.add(callback);
      state.eventListeners.set(eventType, listeners);

      return () => {
        listeners.delete(callback);
      };
    },
    [state.eventListeners],
  );

  // Load saved settings from localStorage
  useEffect(() => {
    const savedSettings = localStorage.getItem('creative-machine-settings');
    if (savedSettings) {
      try {
        const parsed = JSON.parse(savedSettings);
        dispatch({ type: 'SET_SETTINGS', payload: parsed });
      } catch (e) {
        console.error('Failed to load settings:', e);
      }
    }
  }, []);

  // Save settings to localStorage when they change
  useEffect(() => {
    localStorage.setItem('creative-machine-settings', JSON.stringify(state.settings));
  }, [state.settings]);

  const value: AppContextValue = {
    state,
    dispatch,
    updateSettings,
    setActiveModule,
    navigateToVideoWithImage,
    consumePendingVideoData,
    navigateToWriterWithKeywords,
    consumePendingWriterData,
    emit,
    subscribe,
  };

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

// ============================================
// Hooks
// ============================================

export function useApp() {
  const context = useContext(AppContext);
  if (!context) {
    throw new Error('useApp must be used within an AppProvider');
  }
  return context;
}

/** Convenience hook for settings */
export function useSettings() {
  const { state, updateSettings } = useApp();
  return { settings: state.settings, updateSettings };
}

// NOTE: useProjects() removed — projects now live in DB accessed via tRPC.
// Use trpc.assets.getProjects.useQuery() and trpc.assets.createProject.useMutation() directly.

/**
 * ImageAnnotator — lightweight, ZERO-DEPENDENCY image annotation.
 *
 * Draws freehand strokes on a transparent <canvas> overlaid on the chosen image
 * (the "lighter alternative" to tldraw). On insert it FLATTENS the image + strokes
 * onto an offscreen canvas at the image's natural resolution and returns a single
 * PNG data-URL — lightweight to render anywhere (no drawing runtime on the review
 * page) and safe to store (the approval_sets.snapshot column is LONGTEXT).
 *
 * Rendered as a plain fixed portal tagged `data-pcm-annotator` so the host Radix
 * dialog's outside-click guard keeps the create dialog open while annotating.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Undo2, Eraser, Trash2, X, Check, Loader2 } from 'lucide-react';

interface Point { x: number; y: number; }
interface Stroke { color: string; size: number; erase: boolean; points: Point[]; }

const COLORS = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#111827', '#ffffff'];
const SIZES = [3, 6, 12];

interface ImageAnnotatorProps {
  open: boolean;
  imageUrl: string | null;
  onCancel: () => void;
  /** Flattened PNG data-URL (image + annotations). */
  onInsert: (dataUrl: string) => void;
}

export function ImageAnnotator({ open, imageUrl, onCancel, onInsert }: ImageAnnotatorProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const imgRef = useRef<HTMLImageElement | null>(null);
  const strokesRef = useRef<Stroke[]>([]);
  const drawingRef = useRef<Stroke | null>(null);

  const [ready, setReady] = useState(false);
  const [color, setColor] = useState(COLORS[0]);
  const [size, setSize] = useState(SIZES[1]);
  const [erase, setErase] = useState(false);

  // (Re)draw every stroke onto the overlay canvas.
  const redraw = useCallback(() => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    for (const s of strokesRef.current) {
      if (s.points.length === 0) continue;
      ctx.globalCompositeOperation = s.erase ? 'destination-out' : 'source-over';
      ctx.strokeStyle = s.color;
      ctx.lineWidth = s.size;
      ctx.beginPath();
      ctx.moveTo(s.points[0].x, s.points[0].y);
      for (let i = 1; i < s.points.length; i++) ctx.lineTo(s.points[i].x, s.points[i].y);
      // A single tap → render a dot.
      if (s.points.length === 1) ctx.lineTo(s.points[0].x + 0.1, s.points[0].y + 0.1);
      ctx.stroke();
    }
    ctx.globalCompositeOperation = 'source-over';
  }, []);

  // Size the canvas to the image's natural resolution once it loads.
  const handleImageLoad = useCallback(() => {
    const img = imgRef.current;
    const canvas = canvasRef.current;
    if (!img || !canvas) return;
    canvas.width = img.naturalWidth || img.width;
    canvas.height = img.naturalHeight || img.height;
    strokesRef.current = [];
    redraw();
    setReady(true);
  }, [redraw]);

  // Reset when the target image changes / closes.
  useEffect(() => {
    if (!open) { setReady(false); strokesRef.current = []; drawingRef.current = null; }
  }, [open, imageUrl]);

  const toCanvasPoint = (e: React.PointerEvent): Point => {
    const canvas = canvasRef.current!;
    const rect = canvas.getBoundingClientRect();
    return {
      x: (e.clientX - rect.left) * (canvas.width / rect.width),
      y: (e.clientY - rect.top) * (canvas.height / rect.height),
    };
  };

  const onPointerDown = (e: React.PointerEvent) => {
    if (!ready) return;
    (e.target as HTMLCanvasElement).setPointerCapture(e.pointerId);
    drawingRef.current = { color, size, erase, points: [toCanvasPoint(e)] };
    strokesRef.current.push(drawingRef.current);
    redraw();
  };
  const onPointerMove = (e: React.PointerEvent) => {
    if (!drawingRef.current) return;
    drawingRef.current.points.push(toCanvasPoint(e));
    redraw();
  };
  const onPointerUp = () => { drawingRef.current = null; };

  const undo = () => { strokesRef.current.pop(); redraw(); };
  const clear = () => { strokesRef.current = []; redraw(); };

  const insert = () => {
    const img = imgRef.current;
    const canvas = canvasRef.current;
    if (!img || !canvas) return;
    const out = document.createElement('canvas');
    out.width = canvas.width;
    out.height = canvas.height;
    const ctx = out.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(img, 0, 0, out.width, out.height);   // base image
    ctx.drawImage(canvas, 0, 0);                         // annotation layer (already natural-res)
    onInsert(out.toDataURL('image/png'));
  };

  if (!open || !imageUrl) return null;

  return createPortal(
    <div
      data-pcm-annotator
      // pointer-events-auto: a parent Radix modal dialog sets `body { pointer-events:
      // none }` and only re-enables its own content. This portal lives on <body> above
      // that dialog, so it must re-enable pointer events for its whole subtree or the
      // toolbar + drawing canvas are dead.
      className="fixed inset-0 z-[100000] flex flex-col bg-black/80 pointer-events-auto"
      role="dialog"
      aria-label="Annotate image"
    >
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2 border-b border-white/10 bg-neutral-900 px-3 py-2">
        <span className="mr-1 text-xs font-medium text-white/70">Annotate</span>
        {COLORS.map((c) => (
          <button
            key={c}
            type="button"
            onClick={() => { setColor(c); setErase(false); }}
            title={c}
            className={`h-6 w-6 rounded-full border ${color === c && !erase ? 'ring-2 ring-white ring-offset-1 ring-offset-neutral-900' : 'border-white/30'}`}
            style={{ background: c }}
          />
        ))}
        <span className="mx-1 h-5 w-px bg-white/15" />
        {SIZES.map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => setSize(s)}
            title={`${s}px`}
            className={`flex h-7 w-7 items-center justify-center rounded ${size === s ? 'bg-white/20' : 'hover:bg-white/10'}`}
          >
            <span className="rounded-full bg-white" style={{ width: s, height: s }} />
          </button>
        ))}
        <span className="mx-1 h-5 w-px bg-white/15" />
        <button type="button" onClick={() => setErase((v) => !v)} title="Eraser" className={`flex h-7 items-center gap-1 rounded px-2 text-xs ${erase ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10'}`}><Eraser className="h-4 w-4" /> Erase</button>
        <button type="button" onClick={undo} title="Undo" className="flex h-7 items-center gap-1 rounded px-2 text-xs text-white/70 hover:bg-white/10"><Undo2 className="h-4 w-4" /> Undo</button>
        <button type="button" onClick={clear} title="Clear" className="flex h-7 items-center gap-1 rounded px-2 text-xs text-white/70 hover:bg-white/10"><Trash2 className="h-4 w-4" /> Clear</button>
        <div className="ml-auto flex items-center gap-2">
          <button type="button" onClick={onCancel} className="flex h-8 items-center gap-1 rounded-md px-3 text-sm text-white/80 hover:bg-white/10"><X className="h-4 w-4" /> Cancel</button>
          <button type="button" onClick={insert} disabled={!ready} className="flex h-8 items-center gap-1 rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"><Check className="h-4 w-4" /> Insert</button>
        </div>
      </div>

      {/* Canvas stage */}
      <div className="relative flex flex-1 items-center justify-center overflow-auto p-4">
        {!ready && <Loader2 className="absolute h-6 w-6 animate-spin text-white/70" />}
        <div className="relative inline-block" style={{ maxWidth: '100%', maxHeight: '100%' }}>
          <img
            ref={imgRef}
            src={imageUrl}
            crossOrigin="anonymous"
            onLoad={handleImageLoad}
            alt="To annotate"
            className="block max-h-[78vh] max-w-full select-none"
            draggable={false}
          />
          <canvas
            ref={canvasRef}
            className="absolute inset-0 h-full w-full touch-none"
            style={{ cursor: 'crosshair' }}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerLeave={onPointerUp}
          />
        </div>
      </div>
    </div>,
    document.body,
  );
}

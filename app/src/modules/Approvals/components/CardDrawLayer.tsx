/**
 * CardDrawLayer — freehand draw layer over an ENTIRE card region (text + images).
 *
 * Zero-dependency: draws strokes on a transparent <canvas> sized to the host content box,
 * then exports a transparent PNG (strokes only) that is stored with the card and rendered
 * as an absolute overlay on top of the content — both while authoring and on the client
 * review page. Unlike ImageAnnotator (which flattens strokes onto a single image), this
 * never rasterizes the underlying text/images, so you can draw over the whole card.
 *
 * The host element MUST be position:relative and pass the content box's pixel width/height.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { Undo2, Eraser, Trash2, X, Check } from 'lucide-react';

interface Point { x: number; y: number; }
interface Stroke { color: string; size: number; erase: boolean; points: Point[]; }

const COLORS = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#111827', '#ffffff'];
const SIZES = [3, 6, 12];

interface CardDrawLayerProps {
  width: number;
  height: number;
  /** Existing overlay PNG to keep drawing on top of. */
  initial?: string | null;
  /** Live overlay update on every stroke — saves the drawing without needing "Done". */
  onChange: (dataUrl: string | null) => void;
  /** Close the draw layer (the drawing is already saved via onChange). */
  onDone: () => void;
  /** Close + discard this session's changes. */
  onCancel: () => void;
}

export function CardDrawLayer({ width, height, initial, onChange, onDone, onCancel }: CardDrawLayerProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const strokesRef = useRef<Stroke[]>([]);
  const drawingRef = useRef<Stroke | null>(null);
  const baseRef = useRef<HTMLImageElement | null>(null); // prior overlay raster (non-undoable)

  const [color, setColor] = useState(COLORS[0]);
  const [size, setSize] = useState(SIZES[1]);
  const [erase, setErase] = useState(false);

  const redraw = useCallback(() => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (baseRef.current) ctx.drawImage(baseRef.current, 0, 0, canvas.width, canvas.height);
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
      if (s.points.length === 1) ctx.lineTo(s.points[0].x + 0.1, s.points[0].y + 0.1); // single tap → dot
      ctx.stroke();
    }
    ctx.globalCompositeOperation = 'source-over';
  }, []);

  // Load any existing overlay as the starting raster, so re-drawing builds on it.
  useEffect(() => {
    strokesRef.current = [];
    drawingRef.current = null;
    if (initial) {
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => { baseRef.current = img; redraw(); };
      img.src = initial;
    } else {
      baseRef.current = null;
      redraw();
    }
  }, [initial, redraw, width, height]);

  const toPoint = (e: React.PointerEvent): Point => {
    const canvas = canvasRef.current!;
    const rect = canvas.getBoundingClientRect();
    return {
      x: (e.clientX - rect.left) * (canvas.width / rect.width),
      y: (e.clientY - rect.top) * (canvas.height / rect.height),
    };
  };
  const onPointerDown = (e: React.PointerEvent) => {
    (e.target as HTMLCanvasElement).setPointerCapture(e.pointerId);
    drawingRef.current = { color, size, erase, points: [toPoint(e)] };
    strokesRef.current.push(drawingRef.current);
    redraw();
  };
  const onPointerMove = (e: React.PointerEvent) => {
    if (!drawingRef.current) return;
    drawingRef.current.points.push(toPoint(e));
    redraw();
  };
  // Push the current canvas (strokes only) up to the parent — null when empty.
  const emit = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const empty = strokesRef.current.length === 0 && !baseRef.current;
    onChange(empty ? null : canvas.toDataURL('image/png'));
  };
  const onPointerUp = () => {
    const was = !!drawingRef.current;
    drawingRef.current = null;
    if (was) emit(); // save after every stroke, so "Done" is optional
  };

  const undo = () => { strokesRef.current.pop(); redraw(); emit(); };
  const clearAll = () => { strokesRef.current = []; baseRef.current = null; redraw(); emit(); };

  return (
    <div className="absolute inset-0 z-20">
      {/* Floating toolbar */}
      <div className="absolute left-1/2 top-2 z-10 flex -translate-x-1/2 flex-wrap items-center gap-1.5 rounded-lg border border-white/10 bg-neutral-900/95 px-2 py-1.5 shadow-lg">
        {COLORS.map((c) => (
          <button
            key={c}
            type="button"
            onClick={() => { setColor(c); setErase(false); }}
            title={c}
            className={`h-5 w-5 rounded-full border ${color === c && !erase ? 'ring-2 ring-white ring-offset-1 ring-offset-neutral-900' : 'border-white/30'}`}
            style={{ background: c }}
          />
        ))}
        <span className="mx-0.5 h-4 w-px bg-white/15" />
        {SIZES.map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => setSize(s)}
            title={`${s}px`}
            className={`flex h-6 w-6 items-center justify-center rounded ${size === s ? 'bg-white/20' : 'hover:bg-white/10'}`}
          >
            <span className="rounded-full bg-white" style={{ width: s, height: s }} />
          </button>
        ))}
        <span className="mx-0.5 h-4 w-px bg-white/15" />
        <button type="button" onClick={() => setErase((v) => !v)} title="Eraser" className={`flex h-6 items-center rounded px-1.5 text-xs ${erase ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10'}`}><Eraser className="h-3.5 w-3.5" /></button>
        <button type="button" onClick={undo} title="Undo" className="flex h-6 items-center rounded px-1.5 text-xs text-white/70 hover:bg-white/10"><Undo2 className="h-3.5 w-3.5" /></button>
        <button type="button" onClick={clearAll} title="Clear" className="flex h-6 items-center rounded px-1.5 text-xs text-white/70 hover:bg-white/10"><Trash2 className="h-3.5 w-3.5" /></button>
        <span className="mx-0.5 h-4 w-px bg-white/15" />
        <button type="button" onClick={onCancel} title="Cancel" className="flex h-6 items-center gap-1 rounded px-2 text-xs text-white/80 hover:bg-white/10"><X className="h-3.5 w-3.5" /></button>
        <button type="button" onClick={onDone} title="Done" className="flex h-6 items-center gap-1 rounded bg-blue-600 px-2 text-xs font-medium text-white hover:bg-blue-700"><Check className="h-3.5 w-3.5" /> Done</button>
      </div>
      <canvas
        ref={canvasRef}
        width={Math.max(1, Math.round(width))}
        height={Math.max(1, Math.round(height))}
        className="absolute inset-0 h-full w-full touch-none"
        style={{ cursor: 'crosshair' }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerLeave={onPointerUp}
      />
    </div>
  );
}

# Templates Module — Architecture Analysis

## Existing Patterns (to follow exactly)

### Navigation
- `client/src/types/index.ts` → `ModuleId` type union
- `client/src/components/layout/Sidebar.tsx` → `mainNavItems[]` + `configNavItems[]`
- `client/src/components/layout/Shell.tsx` → `moduleRegistry` maps ModuleId → Component

### Module Structure
- Each module lives in `client/src/modules/{Name}/index.tsx`
- Exports a single component: `{Name}Module`
- Uses `ModuleHeader` from `@/components/shared/ModuleHeader`
- Uses `card-ambient` CSS class for sections

### Backend Pattern
- Schema: `drizzle/schema.ts` — table + type exports
- DB helpers: `server/db.ts` — CRUD functions
- Router: `server/routers/{name}.ts` — tRPC procedures
- Registration: `server/routers/index.ts` — add to appRouter

## Templates Module Design

### Database: 1 table with JSON fields
```
templates:
  id          int PK auto
  userId      int FK (owner)
  name        varchar(256)
  module      enum: copy, image, video
  type        varchar(64) — subtype within module (social_ads, social_organic, generation, editing)
  fields      json — key-value pairs of form field defaults
  isDefault   boolean — auto-select in dropdown
  createdAt   timestamp
  updatedAt   timestamp
```

### Touch Points (7)
1. Schema: `drizzle/schema.ts` — add `templates` table
2. Migration: `pnpm db:push`
3. DB helpers: `server/db.ts` — CRUD for templates
4. Router: `server/routers/templates.ts` — tRPC procedures
5. Router registration: `server/routers/index.ts`
6. Frontend module: `client/src/modules/Templates/index.tsx`
7. Navigation: Sidebar.tsx + Shell.tsx + types/index.ts

### tRPC Procedures
- `templates.list` (protected) — get all templates for user, optional module filter
- `templates.getById` (protected) — get single template
- `templates.create` (protected) — create new template
- `templates.update` (protected) — update template fields
- `templates.delete` (protected) — delete template
- `templates.setDefault` (protected) — toggle isDefault (only one per module+type)

### Frontend UI
- Table view (Airtable-style, minimalistic)
- Columns: Name, Module, Type, Fields (count), Default (toggle), Actions
- Click row → expand/edit inline
- "New Template" button → dialog or inline row
- Filter tabs by module: All | Copy | Image | Video

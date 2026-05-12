# Ad Card Layout Restructure — Architecture Analysis

## Summary
Move heading from top to bottom of ad card, add new `description` field below heading.

## Dependency Map

### 1. Database Schema (`drizzle/schema.ts`)
- `copyResults` table needs new column: `description text("description")`
- Migration required via `pnpm db:push`

### 2. LLM JSON Schema (`server/routers/copyPrompts.ts`)
- `COPY_RESULT_SCHEMA` needs new `description` property
- System prompt needs instruction for description generation
- For ads: description = short link description (like Facebook's "Learn more about...")
- For organic: description can be empty string

### 3. Backend Router (`server/routers/copy.ts`)
- `CopyOutput` interface: add `description: string`
- `insertCopyResult` calls (2 places): add `description` field
- `formatResultForClient`: add `description` to output
- `updateResult` procedure: add `description` to input schema
- `regenerateCard`: add `description` to parsed output + insert

### 4. Database Helpers (`server/db.ts`)
- `insertCopyResult`: already accepts full schema, no change needed (Drizzle infers)
- `updateCopyResult`: add `description` to updates type + logic

### 5. Frontend Types (`client/src/modules/Copy/types.ts`)
- `CopyVariation` interface: add `description?: string`

### 6. Frontend Mapping (`client/src/modules/Copy/useCopyGeneration.ts`)
- `toVariation()`: add `description` mapping
- `updateCardText`: add `description` to updates type

### 7. Frontend Card (`client/src/modules/Copy/components/InlineEditableCard.tsx`)
- **MOVE** headline rendering from top (line ~199) to bottom (after body/CTA)
- **ADD** description rendering below headline
- **ADD** description edit state + input
- Update `handleCopy` to include description
- Update `handleSave` to include description

### 8. Tests (`server/copy.test.ts`)
- Add tests for description field in COPY_RESULT_SCHEMA
- Add tests for description in formatResultForClient
- Verify existing tests still pass with new field

## Execution Order
1. Schema + migration (foundation)
2. LLM prompt + JSON schema (generation)
3. Backend router + db helpers (data flow)
4. Frontend types + hook (state)
5. Frontend card component (UI)
6. Tests (verification)

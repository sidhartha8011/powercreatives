/**
 * Custom section bodies — the extension socket the rail consults for
 * every teacher. MOST teachers render the generic catalog item list and
 * need no entry here (that is the add-on law working); a teacher whose
 * section needs its own UI (the keywords drawer's teacher, increment I2)
 * registers a component against its id.
 */

import type { ComponentType } from 'react';
import type { TeacherRun } from './types';

export interface TeacherSectionBodyProps {
  run: TeacherRun;
  siteId: number;
  postId: number;
}

export const teacherSectionBodies: Partial<Record<string, ComponentType<TeacherSectionBodyProps>>> = {};

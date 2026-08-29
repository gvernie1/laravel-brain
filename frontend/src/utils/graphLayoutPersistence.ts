import type { Point } from './graphSelection'

export const GRAPH_LAYOUT_SCHEMA_VERSION = 1
export const GRAPH_LAYOUT_STORAGE_PREFIX = 'laravel-brain:manual-layout'
export const GRAPH_LAYOUT_SAVE_DEBOUNCE_MS = 250

export interface GraphLayoutIdentity {
  projectId: string
  graphId: string
  locationId: string
  layout: string
  rankDir: 'LR' | 'TB'
  compact: boolean
}

export interface GraphLayoutStorage {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export interface GraphLayoutAutosave {
  schedule(key: string, positions: ReadonlyMap<string, Point>): void
  flush(): boolean
  cancel(): void
}

interface StoredGraphLayout {
  version: typeof GRAPH_LAYOUT_SCHEMA_VERSION
  positions: Record<string, Point>
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isValidPoint(value: unknown): value is Point {
  if (!isRecord(value)) return false
  return typeof value.x === 'number'
    && Number.isFinite(value.x)
    && typeof value.y === 'number'
    && Number.isFinite(value.y)
}

function encodeKeyPart(value: string): string {
  return encodeURIComponent(value.trim() || 'unknown')
}

/** Versioned, view-specific key that is safe to evolve independently of data. */
export function buildGraphLayoutStorageKey(identity: GraphLayoutIdentity): string {
  const view = `${identity.layout}:${identity.rankDir}:${identity.compact ? 'compact' : 'normal'}`
  return [
    GRAPH_LAYOUT_STORAGE_PREFIX,
    `v${GRAPH_LAYOUT_SCHEMA_VERSION}`,
    encodeKeyPart(identity.locationId),
    encodeKeyPart(identity.projectId),
    encodeKeyPart(identity.graphId),
    encodeKeyPart(view),
  ].join(':')
}

/** Return browser storage without allowing privacy/security errors to escape. */
export function getBrowserGraphLayoutStorage(): GraphLayoutStorage | null {
  if (typeof window === 'undefined') return null
  try {
    return window.localStorage
  } catch {
    return null
  }
}

/** Validate a parsed schema and retain only finite model-space positions. */
export function sanitizeGraphLayout(value: unknown): Map<string, Point> | null {
  if (!isRecord(value) || value.version !== GRAPH_LAYOUT_SCHEMA_VERSION) return null
  if (!isRecord(value.positions)) return null

  const positions = new Map<string, Point>()
  for (const [nodeId, point] of Object.entries(value.positions)) {
    if (!nodeId || !isValidPoint(point)) continue
    positions.set(nodeId, { x: point.x, y: point.y })
  }
  return positions
}

export function serializeGraphLayout(positions: ReadonlyMap<string, Point>): string {
  const sortedPositions = [...positions.entries()]
    .filter(([, point]) => isValidPoint(point))
    .sort(([leftId], [rightId]) => leftId < rightId ? -1 : leftId > rightId ? 1 : 0)
  const stored: StoredGraphLayout = {
    version: GRAPH_LAYOUT_SCHEMA_VERSION,
    positions: Object.fromEntries(
      sortedPositions.map(([nodeId, point]) => [nodeId, { x: point.x, y: point.y }]),
    ),
  }
  return JSON.stringify(stored)
}

export function loadGraphLayout(
  storage: GraphLayoutStorage | null,
  key: string,
): Map<string, Point> {
  if (!storage) return new Map()
  try {
    const serialized = storage.getItem(key)
    if (serialized == null) return new Map()
    return sanitizeGraphLayout(JSON.parse(serialized)) ?? new Map()
  } catch {
    return new Map()
  }
}

export function saveGraphLayout(
  storage: GraphLayoutStorage | null,
  key: string,
  positions: ReadonlyMap<string, Point>,
): boolean {
  if (!storage) return false
  try {
    storage.setItem(key, serializeGraphLayout(positions))
    return true
  } catch {
    return false
  }
}

export function clearGraphLayout(storage: GraphLayoutStorage | null, key: string): boolean {
  if (!storage) return false
  try {
    storage.removeItem(key)
    return true
  } catch {
    return false
  }
}

/** Merge a drag into the complete restored/manual map without dropping peers. */
export function mergeGraphPositions(
  current: ReadonlyMap<string, Point>,
  updates: ReadonlyMap<string, Point>,
): Map<string, Point> {
  const merged = new Map(current)
  for (const [nodeId, point] of updates) {
    if (nodeId && isValidPoint(point)) merged.set(nodeId, { x: point.x, y: point.y })
  }
  return merged
}

/** Apply only positions whose nodes still exist, leaving new nodes on auto layout. */
export function positionsForExistingNodes(
  positions: ReadonlyMap<string, Point>,
  existingNodeIds: ReadonlySet<string>,
): Map<string, Point> {
  return new Map(
    [...positions].filter(([nodeId]) => existingNodeIds.has(nodeId)),
  )
}

/**
 * Debounced saver that snapshots the latest complete map. A later schedule
 * replaces both the pending key and data, preventing stale closure writes.
 */
export function createGraphLayoutAutosave(
  storage: () => GraphLayoutStorage | null,
  delay = GRAPH_LAYOUT_SAVE_DEBOUNCE_MS,
): GraphLayoutAutosave {
  let timeout: ReturnType<typeof setTimeout> | null = null
  let pending: { key: string; positions: Map<string, Point> } | null = null

  const clearTimer = (): void => {
    if (timeout !== null) clearTimeout(timeout)
    timeout = null
  }

  const flush = (): boolean => {
    clearTimer()
    const current = pending
    pending = null
    return current ? saveGraphLayout(storage(), current.key, current.positions) : false
  }

  return {
    schedule(key, positions) {
      if (pending && pending.key !== key) flush()
      clearTimer()
      pending = { key, positions: new Map(positions) }
      timeout = setTimeout(flush, delay)
    },
    flush,
    cancel() {
      clearTimer()
      pending = null
    },
  }
}

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  GRAPH_LAYOUT_SAVE_DEBOUNCE_MS,
  GRAPH_LAYOUT_SCHEMA_VERSION,
  buildGraphLayoutStorageKey,
  clearGraphLayout,
  createGraphLayoutAutosave,
  loadGraphLayout,
  mergeGraphPositions,
  positionsForExistingNodes,
  sanitizeGraphLayout,
  saveGraphLayout,
  serializeGraphLayout,
  type GraphLayoutIdentity,
  type GraphLayoutStorage,
} from '../src/utils/graphLayoutPersistence'
import { moveNodePositions, type Point } from '../src/utils/graphSelection'

class MemoryStorage implements GraphLayoutStorage {
  readonly values = new Map<string, string>()
  writes = 0

  getItem(key: string): string | null {
    return this.values.get(key) ?? null
  }

  setItem(key: string, value: string): void {
    this.writes++
    this.values.set(key, value)
  }

  removeItem(key: string): void {
    this.values.delete(key)
  }
}

class ThrowingStorage implements GraphLayoutStorage {
  getItem(): string | null {
    throw new Error('blocked')
  }

  setItem(): void {
    throw new Error('quota')
  }

  removeItem(): void {
    throw new Error('blocked')
  }
}

const baseIdentity: GraphLayoutIdentity = {
  projectId: 'Apex Portal',
  graphId: 'route-post-webhooks-shopify',
  locationId: 'http://localhost:8000/_laravel-brain/',
  layout: 'dagre',
  rankDir: 'TB',
  compact: false,
}

function key(overrides: Partial<GraphLayoutIdentity> = {}): string {
  return buildGraphLayoutStorageKey({ ...baseIdentity, ...overrides })
}

function positions(entries: Record<string, Point>): Map<string, Point> {
  return new Map(Object.entries(entries))
}

afterEach(() => {
  vi.useRealTimers()
})

describe('graph layout persistence schema', () => {
  it('serializes finite model-space positions in the versioned schema', () => {
    const serialized = serializeGraphLayout(positions({
      b: { x: 30, y: 40 },
      a: { x: 10, y: 20 },
    }))

    expect(JSON.parse(serialized)).toEqual({
      version: GRAPH_LAYOUT_SCHEMA_VERSION,
      positions: {
        a: { x: 10, y: 20 },
        b: { x: 30, y: 40 },
      },
    })
  })

  it('restores saved positions by node ID', () => {
    const storage = new MemoryStorage()
    saveGraphLayout(storage, key(), positions({ a: { x: 10, y: 20 }, b: { x: 30, y: 40 } }))

    expect([...loadGraphLayout(storage, key())]).toEqual([
      ['a', { x: 10, y: 20 }],
      ['b', { x: 30, y: 40 }],
    ])
  })

  it('falls back safely for malformed JSON and unsupported versions', () => {
    const storage = new MemoryStorage()
    storage.values.set(key({ graphId: 'malformed' }), '{not-json')
    storage.values.set(key({ graphId: 'future' }), JSON.stringify({
      version: GRAPH_LAYOUT_SCHEMA_VERSION + 1,
      positions: { a: { x: 1, y: 2 } },
    }))

    expect(loadGraphLayout(storage, key({ graphId: 'malformed' })).size).toBe(0)
    expect(loadGraphLayout(storage, key({ graphId: 'future' })).size).toBe(0)
  })

  it('sanitizes invalid coordinates without rejecting valid peers', () => {
    const sanitized = sanitizeGraphLayout({
      version: GRAPH_LAYOUT_SCHEMA_VERSION,
      positions: {
        valid: { x: -12.5, y: 44 },
        infinite: { x: Number.POSITIVE_INFINITY, y: 2 },
        wrongType: { x: '3', y: 4 },
        missing: { x: 5 },
      },
    })

    expect([...sanitized!]).toEqual([['valid', { x: -12.5, y: 44 }]])
    expect(sanitizeGraphLayout({ version: 1, positions: [] })).toBeNull()
  })
})

describe('repeated editable layout lifecycle', () => {
  it('updates one restored node without dropping previously saved peers', () => {
    const storage = new MemoryStorage()
    const storageKey = key()
    saveGraphLayout(storage, storageKey, positions({
      a: { x: 100, y: 100 },
      b: { x: 200, y: 100 },
      c: { x: 300, y: 100 },
    }))

    const restored = loadGraphLayout(storage, storageKey)
    const edited = mergeGraphPositions(restored, positions({ b: { x: 220, y: 180 } }))
    saveGraphLayout(storage, storageKey, edited)

    expect(Object.fromEntries(loadGraphLayout(storage, storageKey))).toEqual({
      a: { x: 100, y: 100 },
      b: { x: 220, y: 180 },
      c: { x: 300, y: 100 },
    })
  })

  it('leaves a new node on auto layout until its first manual move', () => {
    const storage = new MemoryStorage()
    const storageKey = key()
    saveGraphLayout(storage, storageKey, positions({
      a: { x: 10, y: 20 },
      b: { x: 30, y: 40 },
    }))

    const restored = positionsForExistingNodes(
      loadGraphLayout(storage, storageKey),
      new Set(['a', 'b', 'c']),
    )
    expect(restored.has('c')).toBe(false)

    const edited = mergeGraphPositions(restored, positions({ c: { x: 50, y: 60 } }))
    saveGraphLayout(storage, storageKey, edited)
    expect(Object.fromEntries(loadGraphLayout(storage, storageKey))).toEqual({
      a: { x: 10, y: 20 },
      b: { x: 30, y: 40 },
      c: { x: 50, y: 60 },
    })
  })

  it('ignores removed nodes while retaining surviving saved positions', () => {
    const saved = positions({
      a: { x: 10, y: 20 },
      b: { x: 30, y: 40 },
      c: { x: 50, y: 60 },
    })

    expect([...positionsForExistingNodes(saved, new Set(['a', 'c']))]).toEqual([
      ['a', { x: 10, y: 20 }],
      ['c', { x: 50, y: 60 }],
    ])
  })

  it('persists every group-drag position and retains unselected saved nodes', () => {
    const storage = new MemoryStorage()
    const storageKey = key()
    const restored = positions({
      a: { x: 10, y: 20 },
      b: { x: 70, y: 80 },
      c: { x: 140, y: 160 },
    })
    const movedGroup = moveNodePositions(new Map([
      ['a', restored.get('a')!],
      ['b', restored.get('b')!],
    ]), 25, -15)

    saveGraphLayout(storage, storageKey, mergeGraphPositions(restored, movedGroup))

    expect(Object.fromEntries(loadGraphLayout(storage, storageKey))).toEqual({
      a: { x: 35, y: 5 },
      b: { x: 95, y: 65 },
      c: { x: 140, y: 160 },
    })
  })

  it('simulates reloads and rescans while keeping stable IDs editable', () => {
    const storage = new MemoryStorage()
    const storageKey = key()
    saveGraphLayout(storage, storageKey, positions({
      a: { x: 10, y: 20 },
      b: { x: 30, y: 40 },
    }))

    const afterReload = positionsForExistingNodes(
      loadGraphLayout(storage, storageKey),
      new Set(['a', 'b']),
    )
    const afterRescan = positionsForExistingNodes(afterReload, new Set(['a', 'c']))
    const afterEdit = mergeGraphPositions(afterRescan, positions({
      a: { x: 15, y: 25 },
      c: { x: 70, y: 80 },
    }))
    saveGraphLayout(storage, storageKey, afterEdit)

    expect(Object.fromEntries(loadGraphLayout(storage, storageKey))).toEqual({
      a: { x: 15, y: 25 },
      c: { x: 70, y: 80 },
    })
  })

  it('does not prune a temporarily hidden node from the complete map', () => {
    const saved = positions({
      visible: { x: 10, y: 20 },
      hiddenByFilter: { x: 30, y: 40 },
      hiddenByCollapse: { x: 50, y: 60 },
    })

    const restored = positionsForExistingNodes(
      saved,
      new Set(['visible', 'hiddenByFilter', 'hiddenByCollapse']),
    )

    expect(restored).toEqual(saved)
  })
})

describe('layout identity, reset, and failure handling', () => {
  it('isolates identical graph IDs across projects and browser locations', () => {
    expect(key()).not.toBe(key({ projectId: 'Other Project' }))
    expect(key()).not.toBe(key({ locationId: 'http://localhost:9000/_laravel-brain/' }))
  })

  it('isolates route, job, and other graph IDs in one project', () => {
    expect(key({ graphId: 'route-a' })).not.toBe(key({ graphId: 'route-b' }))
    expect(key({ graphId: 'route-a' })).not.toBe(key({ graphId: 'job-a' }))
  })

  it('keeps manual arrangements separate by layout, orientation, and compact mode', () => {
    expect(key()).not.toBe(key({ layout: 'circle' }))
    expect(key()).not.toBe(key({ rankDir: 'LR' }))
    expect(key()).not.toBe(key({ compact: true }))
  })

  it('resets only the current graph/view key', () => {
    const storage = new MemoryStorage()
    const currentKey = key({ graphId: 'route-a' })
    const otherKey = key({ graphId: 'job-a' })
    saveGraphLayout(storage, currentKey, positions({ a: { x: 1, y: 2 } }))
    saveGraphLayout(storage, otherKey, positions({ b: { x: 3, y: 4 } }))

    expect(clearGraphLayout(storage, currentKey)).toBe(true)
    expect(loadGraphLayout(storage, currentKey).size).toBe(0)
    expect(loadGraphLayout(storage, otherKey).get('b')).toEqual({ x: 3, y: 4 })
  })

  it('keeps in-memory editing usable when storage reads or writes throw', () => {
    const storage = new ThrowingStorage()
    const edited = mergeGraphPositions(new Map(), positions({ a: { x: 10, y: 20 } }))

    expect(loadGraphLayout(storage, key()).size).toBe(0)
    expect(saveGraphLayout(storage, key(), edited)).toBe(false)
    expect(clearGraphLayout(storage, key())).toBe(false)
    expect(edited.get('a')).toEqual({ x: 10, y: 20 })
  })

  it('debounces rapid updates and writes the latest complete snapshot', () => {
    vi.useFakeTimers()
    const storage = new MemoryStorage()
    const autosave = createGraphLayoutAutosave(() => storage)
    const storageKey = key()
    autosave.schedule(storageKey, positions({ a: { x: 1, y: 2 } }))
    autosave.schedule(storageKey, positions({
      a: { x: 5, y: 6 },
      b: { x: 7, y: 8 },
    }))

    vi.advanceTimersByTime(GRAPH_LAYOUT_SAVE_DEBOUNCE_MS - 1)
    expect(storage.writes).toBe(0)
    vi.advanceTimersByTime(1)

    expect(storage.writes).toBe(1)
    expect(Object.fromEntries(loadGraphLayout(storage, storageKey))).toEqual({
      a: { x: 5, y: 6 },
      b: { x: 7, y: 8 },
    })
  })

  it('flushes a pending graph before scheduling another graph key', () => {
    vi.useFakeTimers()
    const storage = new MemoryStorage()
    const autosave = createGraphLayoutAutosave(() => storage)
    const firstKey = key({ graphId: 'route-a' })
    const secondKey = key({ graphId: 'job-a' })

    autosave.schedule(firstKey, positions({ a: { x: 1, y: 2 } }))
    autosave.schedule(secondKey, positions({ b: { x: 3, y: 4 } }))

    expect(loadGraphLayout(storage, firstKey).get('a')).toEqual({ x: 1, y: 2 })
    expect(loadGraphLayout(storage, secondKey).size).toBe(0)
    autosave.flush()
    expect(loadGraphLayout(storage, secondKey).get('b')).toEqual({ x: 3, y: 4 })
  })
})

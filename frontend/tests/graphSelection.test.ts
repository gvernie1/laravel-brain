import { describe, expect, it } from 'vitest'
import {
  backgroundGesture,
  clearSelection,
  incidentEdgeIds,
  moveNodePositions,
  nodesIntersectingMarquee,
  prepareNodeDrag,
  retainVisibleSelection,
  selectNode,
  type SelectionState,
} from '../src/utils/graphSelection'

function selection(...selectedIds: string[]): SelectionState {
  return {
    selectedIds: new Set(selectedIds),
    primaryId: selectedIds[selectedIds.length - 1] ?? null,
  }
}

describe('node selection', () => {
  it('normal click selects one node and replaces a multi-selection', () => {
    const next = selectNode(selection('first', 'second'), 'third', false)

    expect([...next.selectedIds]).toEqual(['third'])
    expect(next.primaryId).toBe('third')
  })

  it('Shift-click adds a node and makes it primary', () => {
    const next = selectNode(selection('first'), 'second', true)

    expect([...next.selectedIds]).toEqual(['first', 'second'])
    expect(next.primaryId).toBe('second')
  })

  it('Shift-click removes a node and chooses a remaining primary', () => {
    const next = selectNode(selection('first', 'second'), 'second', true)

    expect([...next.selectedIds]).toEqual(['first'])
    expect(next.primaryId).toBe('first')
  })

  it('clears every selected node and the primary inspector node', () => {
    const next = clearSelection()

    expect(next.selectedIds.size).toBe(0)
    expect(next.primaryId).toBeNull()
  })

  it('removes selections that filtering or collapse makes invisible', () => {
    const next = retainVisibleSelection(
      selection('first', 'second'),
      new Set(['first']),
    )

    expect([...next.selectedIds]).toEqual(['first'])
    expect(next.primaryId).toBe('first')
  })
})

describe('background gestures and marquee selection', () => {
  const nodes = [
    { id: 'first', x: 0, y: 0, width: 100, height: 80 },
    { id: 'second', x: 160, y: 0, width: 120, height: 80 },
    { id: 'hidden', x: 80, y: 0, width: 100, height: 80 },
  ]

  it('reserves Shift-background drag for marquee while normal drag remains pan', () => {
    expect(backgroundGesture(0, false)).toBe('pan')
    expect(backgroundGesture(0, true)).toBe('marquee')
    expect(backgroundGesture(2, true)).toBe('ignore')
  })

  it('selects intersecting visible nodes and excludes hidden nodes', () => {
    const selected = nodesIntersectingMarquee(
      nodes,
      new Set(['first', 'second']),
      { x: -60, y: -50 },
      { x: 230, y: 50 },
      { x: 0, y: 0, k: 1 },
    )

    expect([...selected]).toEqual(['first', 'second'])
  })

  it('converts the marquee through non-identity zoom and pan', () => {
    const transform = { x: 300, y: 120, k: 2 }
    const selected = nodesIntersectingMarquee(
      nodes,
      new Set(nodes.map((node) => node.id)),
      { x: 190, y: 20 },
      { x: 750, y: 220 },
      transform,
    )

    expect([...selected]).toEqual(['first', 'second', 'hidden'])
  })
})

describe('group dragging and edge highlighting', () => {
  it('prepares every selected node when dragging a selected group member', () => {
    const prepared = prepareNodeDrag(selection('first', 'second'), 'first', false)

    expect([...prepared.selection.selectedIds]).toEqual(['first', 'second'])
    expect([...prepared.draggedIds]).toEqual(['first', 'second'])
  })

  it('moves every selected node by one model-space delta and preserves offsets', () => {
    const original = new Map([
      ['first', { x: 10, y: 20 }],
      ['second', { x: 70, y: 95 }],
    ])
    const moved = moveNodePositions(original, 25, -15)

    expect(moved.get('first')).toEqual({ x: 35, y: 5 })
    expect(moved.get('second')).toEqual({ x: 95, y: 80 })
    expect(moved.get('second')!.x - moved.get('first')!.x).toBe(60)
    expect(moved.get('second')!.y - moved.get('first')!.y).toBe(75)
  })

  it('selects and drags only an unselected node on a normal drag start', () => {
    const prepared = prepareNodeDrag(selection('first', 'second'), 'third', false)

    expect([...prepared.selection.selectedIds]).toEqual(['third'])
    expect([...prepared.draggedIds]).toEqual(['third'])
    expect(prepared.selection.primaryId).toBe('third')
  })

  it('highlights the union of visible incident edges without duplicates', () => {
    const edgeIds = incidentEdgeIds([
      { id: 'a-b', source: 'a', target: 'b' },
      { id: 'b-c', source: 'b', target: 'c' },
      { id: 'c-d', source: 'c', target: 'd' },
      { id: 'hidden', source: 'a', target: 'z' },
    ], new Set(['a', 'c']), (edge) => edge.id !== 'hidden')

    expect([...edgeIds]).toEqual(['a-b', 'b-c', 'c-d'])
  })
})

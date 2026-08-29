import { describe, expect, it } from 'vitest'
import {
  buildBreadthFirstLayers,
  countAdjacentRankCrossings,
  orderLayersByBarycenter,
  sweepLayerOrder,
  type LayerEdge,
} from '../src/utils/graphLayerOrdering'

function edge(source: string, target: string): LayerEdge {
  return { source, target }
}

describe('breadth-first layer ordering', () => {
  it('reorders a simple crossed layer around its parents', () => {
    const baseline = [
      ['parent-a', 'parent-b'],
      ['a-child-for-b', 'z-child-for-a'],
    ]
    const edges = [
      edge('parent-a', 'z-child-for-a'),
      edge('parent-b', 'a-child-for-b'),
    ]

    const optimized = orderLayersByBarycenter(baseline, edges)

    expect(optimized[1]).toEqual(['z-child-for-a', 'a-child-for-b'])
    expect(countAdjacentRankCrossings(baseline, edges)).toBe(1)
    expect(countAdjacentRankCrossings(optimized, edges)).toBe(0)
  })

  it('uses the barycenter of multiple parent positions', () => {
    const baseline = [
      ['parent-a', 'parent-b', 'parent-c'],
      ['a-right', 'm-middle', 'z-left'],
    ]
    const edges = [
      edge('parent-a', 'z-left'),
      edge('parent-a', 'm-middle'),
      edge('parent-c', 'm-middle'),
      edge('parent-c', 'a-right'),
    ]

    const swept = sweepLayerOrder(baseline, edges, 'down')

    expect(swept[1]).toEqual(['z-left', 'm-middle', 'a-right'])
  })

  it('uses upward sweeps when a downward-only pass is insufficient', () => {
    const baseline = [
      ['a', 'b', 'c'],
      ['d', 'e', 'f'],
      ['g', 'h', 'i'],
    ]
    const edges = [
      edge('a', 'd'),
      edge('a', 'e'),
      edge('a', 'f'),
      edge('d', 'g'),
      edge('d', 'i'),
      edge('e', 'h'),
      edge('f', 'g'),
    ]

    const downwardOnly = sweepLayerOrder(baseline, edges, 'down')
    const optimized = orderLayersByBarycenter(baseline, edges)

    expect(countAdjacentRankCrossings(baseline, edges)).toBe(3)
    expect(countAdjacentRankCrossings(downwardOnly, edges)).toBe(1)
    expect(countAdjacentRankCrossings(optimized, edges)).toBe(0)
    expect(optimized[1]).toEqual(['d', 'f', 'e'])
  })

  it('retains deterministic previous order for equal barycenters', () => {
    const baseline = [
      ['parent-a', 'parent-b'],
      ['tie-b', 'tie-a'],
    ]
    const edges = [
      edge('parent-a', 'tie-a'),
      edge('parent-a', 'tie-b'),
      edge('parent-b', 'tie-a'),
      edge('parent-b', 'tie-b'),
    ]

    expect(orderLayersByBarycenter(baseline, edges)).toEqual(baseline)
    expect(orderLayersByBarycenter(baseline, edges)).toEqual(baseline)
  })

  it('keeps disconnected nodes in stable relative order', () => {
    const baseline = [
      ['parent-left', 'parent-right'],
      ['a-connected-right', 'm-disconnected-a', 'n-disconnected-b', 'z-connected-left'],
    ]
    const edges = [
      edge('parent-left', 'z-connected-left'),
      edge('parent-right', 'a-connected-right'),
    ]

    const optimized = orderLayersByBarycenter(baseline, edges)
    const disconnectedA = optimized[1].indexOf('m-disconnected-a')
    const disconnectedB = optimized[1].indexOf('n-disconnected-b')

    expect(optimized[1]).toEqual([
      'z-connected-left',
      'm-disconnected-a',
      'n-disconnected-b',
      'a-connected-right',
    ])
    expect(disconnectedA).toBeLessThan(disconnectedB)
    expect(orderLayersByBarycenter(baseline, edges)).toEqual(optimized)
  })

  it('prefers the closest rank when long edges conflict with adjacent relationships', () => {
    const baseline = [
      ['root-a', 'root-b'],
      ['middle-a', 'middle-b'],
      ['a-leaf-for-b', 'z-leaf-for-a'],
    ]
    const edges = [
      edge('root-a', 'middle-a'),
      edge('root-b', 'middle-b'),
      edge('middle-a', 'z-leaf-for-a'),
      edge('root-b', 'z-leaf-for-a'),
      edge('middle-b', 'a-leaf-for-b'),
      edge('root-a', 'a-leaf-for-b'),
    ]

    expect(orderLayersByBarycenter(baseline, edges)[2]).toEqual([
      'z-leaf-for-a',
      'a-leaf-for-b',
    ])
  })

  it('preserves longest-path rank assignment before ordering', () => {
    const layers = buildBreadthFirstLayers(
      ['job', 'route', 'isolated', 'controller'],
      [
        edge('route', 'controller'),
        edge('controller', 'job'),
        edge('route', 'job'),
      ],
    )

    expect(layers).toEqual([
      ['isolated', 'route'],
      ['controller'],
      ['job'],
    ])
  })
})

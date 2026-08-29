import { describe, expect, it } from 'vitest'
import { LARGE_GRAPH_THRESHOLD } from '../src/utils/graphConstants'
import {
  BREADTH_FIRST_NODE_GAP,
  BREADTH_FIRST_RANK_GAP,
  layoutBreadthFirst,
  pickLayoutKind,
  type LayoutEdge,
  type LayoutNode,
} from '../src/utils/graphLayoutD3'

function node(id: string, width: number, height: number): LayoutNode {
  return {
    id,
    x: 0,
    y: 0,
    width,
    height,
    lines: [id],
    data: { id },
  }
}

function edge(source: string, target: string): LayoutEdge {
  return {
    id: `${source}-${target}`,
    source,
    target,
    data: { id: `${source}-${target}`, source, target },
  }
}

describe('layoutBreadthFirst', () => {
  it('uses the same crossing-minimized logical order in top-down and left-right modes', () => {
    const createNodes = () => [
      node('parent-a', 185, 50),
      node('parent-b', 270, 95),
      node('a-child-for-b', 240, 120),
      node('z-child-for-a', 195, 65),
    ]
    const edges = [
      edge('parent-a', 'z-child-for-a'),
      edge('parent-b', 'a-child-for-b'),
    ]
    const topDownNodes = createNodes()
    const leftRightNodes = createNodes()

    layoutBreadthFirst(topDownNodes, edges, 'TB')
    layoutBreadthFirst(leftRightNodes, edges, 'LR')

    const topDownChildOrder = topDownNodes
      .filter((candidate) => candidate.id.includes('child'))
      .sort((a, b) => a.x - b.x)
      .map((candidate) => candidate.id)
    const leftRightChildOrder = leftRightNodes
      .filter((candidate) => candidate.id.includes('child'))
      .sort((a, b) => a.y - b.y)
      .map((candidate) => candidate.id)

    expect(topDownChildOrder).toEqual(['z-child-for-a', 'a-child-for-b'])
    expect(leftRightChildOrder).toEqual(topDownChildOrder)
  })

  it('preserves card-aware gaps after reordering mixed-width nodes', () => {
    const nodes = [
      node('parent-a', 185, 70),
      node('parent-b', 270, 90),
      node('a-child-for-b', 260, 110),
      node('z-child-for-a', 190, 80),
    ]
    const edges = [
      edge('parent-a', 'z-child-for-a'),
      edge('parent-b', 'a-child-for-b'),
    ]

    layoutBreadthFirst(nodes, edges, 'TB')

    const [leftChild, rightChild] = nodes
      .filter((candidate) => candidate.id.includes('child'))
      .sort((a, b) => a.x - b.x)
    const horizontalGap = rightChild.x - rightChild.width / 2
      - (leftChild.x + leftChild.width / 2)

    expect([leftChild.id, rightChild.id]).toEqual(['z-child-for-a', 'a-child-for-b'])
    expect(horizontalGap).toBeGreaterThanOrEqual(BREADTH_FIRST_NODE_GAP)
  })

  it('uses mixed card widths and centers each top-down layer', () => {
    const nodes = [
      node('root', 210, 70),
      node('child-a', 185, 90),
      node('child-b', 270, 120),
    ]

    layoutBreadthFirst(nodes, [edge('root', 'child-a'), edge('root', 'child-b')], 'TB')

    const childA = nodes[1]
    const childB = nodes[2]
    const layerLeft = Math.min(childA.x - childA.width / 2, childB.x - childB.width / 2)
    const layerRight = Math.max(childA.x + childA.width / 2, childB.x + childB.width / 2)

    expect(childB.x - childB.width / 2 - (childA.x + childA.width / 2))
      .toBeGreaterThanOrEqual(BREADTH_FIRST_NODE_GAP)
    expect((layerLeft + layerRight) / 2).toBeCloseTo(0)
  })

  it('uses mixed card heights and centers each left-right layer', () => {
    const nodes = [
      node('root', 180, 55),
      node('child-a', 200, 40),
      node('child-b', 260, 130),
    ]

    layoutBreadthFirst(nodes, [edge('root', 'child-a'), edge('root', 'child-b')], 'LR')

    const childA = nodes[1]
    const childB = nodes[2]
    const layerTop = Math.min(childA.y - childA.height / 2, childB.y - childB.height / 2)
    const layerBottom = Math.max(childA.y + childA.height / 2, childB.y + childB.height / 2)
    const childRankLeft = Math.min(
      childA.x - childA.width / 2,
      childB.x - childB.width / 2,
    )

    expect(childB.y - childB.height / 2 - (childA.y + childA.height / 2))
      .toBeGreaterThanOrEqual(BREADTH_FIRST_NODE_GAP)
    expect(childRankLeft - (nodes[0].x + nodes[0].width / 2))
      .toBeGreaterThanOrEqual(BREADTH_FIRST_RANK_GAP)
    expect((layerTop + layerBottom) / 2).toBeCloseTo(0)
  })

  it('keeps dimension-aware gaps between ranks and centers the graph bounds', () => {
    const nodes = [
      node('root', 160, 50),
      node('middle', 270, 140),
      node('leaf', 190, 75),
    ]

    layoutBreadthFirst(nodes, [edge('root', 'middle'), edge('middle', 'leaf')], 'TB')

    const [root, middle, leaf] = nodes
    const rootToMiddle = middle.y - middle.height / 2 - (root.y + root.height / 2)
    const middleToLeaf = leaf.y - leaf.height / 2 - (middle.y + middle.height / 2)
    const graphTop = root.y - root.height / 2
    const graphBottom = leaf.y + leaf.height / 2

    expect(rootToMiddle).toBeGreaterThanOrEqual(BREADTH_FIRST_RANK_GAP)
    expect(middleToLeaf).toBeGreaterThanOrEqual(BREADTH_FIRST_RANK_GAP)
    expect((graphTop + graphBottom) / 2).toBeCloseTo(0)
  })
})

describe('pickLayoutKind', () => {
  it('keeps Dagre through the large-graph threshold', () => {
    expect(pickLayoutKind('dagre', LARGE_GRAPH_THRESHOLD, LARGE_GRAPH_THRESHOLD)).toBe('dagre')
  })

  it('uses breadth-first above the large-graph threshold', () => {
    expect(pickLayoutKind('dagre', LARGE_GRAPH_THRESHOLD + 1, LARGE_GRAPH_THRESHOLD))
      .toBe('breadthfirst')
  })

  it('preserves explicit layout mappings', () => {
    expect(pickLayoutKind('breadthfirst', 1, LARGE_GRAPH_THRESHOLD)).toBe('breadthfirst')
    expect(pickLayoutKind('cose-bilkent', 1, LARGE_GRAPH_THRESHOLD)).toBe('force')
    expect(pickLayoutKind('circle', 1, LARGE_GRAPH_THRESHOLD)).toBe('circle')
    expect(pickLayoutKind('grid', 1, LARGE_GRAPH_THRESHOLD)).toBe('grid')
  })
})

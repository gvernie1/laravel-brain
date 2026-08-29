import { describe, expect, it } from 'vitest'
import {
  EDGE_PORT_CORNER_PADDING,
  buildEdgeRouting,
  orthogonalPathFromWaypoints,
  type RoutableEdge,
  type RoutableNode,
} from '../src/utils/graphEdgeRouting'

function node(
  id: string,
  x: number,
  y: number,
  width = 100,
  height = 60,
): RoutableNode {
  return { id, x, y, width, height }
}

function edge(id: string, source: string, target: string): RoutableEdge {
  return { id, source, target }
}

describe('edge port assignment', () => {
  it('gives sibling outgoing edges distinct source ports ordered by destination', () => {
    const nodes = [
      node('source', 0, 0),
      node('left', -100, 240),
      node('right', 100, 240),
    ]
    const routes = buildEdgeRouting(nodes, [
      edge('to-right', 'source', 'right'),
      edge('to-left', 'source', 'left'),
    ])
    const left = routes.get('to-left')!
    const right = routes.get('to-right')!

    expect(left.sourceSide).toBe('bottom')
    expect(left.sourcePort.x).toBeLessThan(right.sourcePort.x)
    expect(left.sourcePort).not.toEqual(right.sourcePort)
  })

  it('gives sibling incoming edges distinct target ports ordered by source', () => {
    const nodes = [
      node('left', -100, 0),
      node('right', 100, 0),
      node('target', 0, 240),
    ]
    const routes = buildEdgeRouting(nodes, [
      edge('from-right', 'right', 'target'),
      edge('from-left', 'left', 'target'),
    ])
    const left = routes.get('from-left')!
    const right = routes.get('from-right')!

    expect(left.targetSide).toBe('top')
    expect(left.targetPort.x).toBeLessThan(right.targetPort.x)
    expect(left.targetPort).not.toEqual(right.targetPort)
  })

  it('is deterministic regardless of visible edge input order', () => {
    const nodes = [
      node('source', 0, 0),
      node('left', -100, 240),
      node('right', 100, 240),
    ]
    const edges = [
      edge('to-right', 'source', 'right'),
      edge('to-left', 'source', 'left'),
    ]

    const forward = buildEdgeRouting(nodes, edges)
    const reversed = buildEdgeRouting(nodes, [...edges].reverse())

    expect([...forward.entries()]).toEqual([...reversed.entries()])
  })

  it('keeps a single edge centered on its node sides', () => {
    const nodes = [node('source', 25, 0), node('target', 25, 240)]
    const route = buildEdgeRouting(nodes, [edge('only', 'source', 'target')]).get('only')!

    expect(route.sourcePort.x).toBe(25)
    expect(route.targetPort.x).toBe(25)
    expect(route.laneOffset).toBe(0)
  })

  it('keeps large fan-out ports inside real card bounds and away from corners', () => {
    const source = node('source', 0, 0, 100, 60)
    const targets = Array.from({ length: 9 }, (_, index) =>
      node(`target-${index}`, (index - 4) * 45, 400, 90, 60))
    const edges = targets.map((target, index) => edge(`edge-${index}`, source.id, target.id))
    const routes = buildEdgeRouting([source, ...targets], edges)
    const sourcePorts = [...routes.values()].map((route) => route.sourcePort.x)

    expect(Math.min(...sourcePorts)).toBeGreaterThanOrEqual(
      source.x - source.width / 2 + EDGE_PORT_CORNER_PADDING,
    )
    expect(Math.max(...sourcePorts)).toBeLessThanOrEqual(
      source.x + source.width / 2 - EDGE_PORT_CORNER_PADDING,
    )
    expect(new Set(sourcePorts).size).toBe(edges.length)
  })

  it('uses bounded, distinct side ports for compact left-right nodes', () => {
    const source = node('source', 0, 0, 120, 40)
    const targets = [
      node('top', 400, -60, 120, 40),
      node('middle', 400, 0, 120, 40),
      node('bottom', 400, 60, 120, 40),
    ]
    const routes = buildEdgeRouting([source, ...targets], [
      edge('to-bottom', 'source', 'bottom'),
      edge('to-middle', 'source', 'middle'),
      edge('to-top', 'source', 'top'),
    ])
    const orderedPorts = ['to-top', 'to-middle', 'to-bottom']
      .map((id) => routes.get(id)!.sourcePort.y)

    expect(routes.get('to-top')!.sourceSide).toBe('right')
    expect(orderedPorts[0]).toBeLessThan(orderedPorts[1])
    expect(orderedPorts[1]).toBeLessThan(orderedPorts[2])
    expect(orderedPorts[0]).toBeGreaterThanOrEqual(-8)
    expect(orderedPorts[2]).toBeLessThanOrEqual(8)
  })

  it('adapts sibling ordering after nodes move across the perpendicular axis', () => {
    const source = node('source', 0, 0)
    const initialTargets = [node('a', -100, 240), node('b', 100, 240)]
    const edges = [edge('to-a', 'source', 'a'), edge('to-b', 'source', 'b')]
    const initial = buildEdgeRouting([source, ...initialTargets], edges)
    const moved = buildEdgeRouting([
      source,
      node('a', 140, 240),
      node('b', -140, 240),
    ], edges)

    expect(initial.get('to-a')!.sourcePort.x).toBeLessThan(initial.get('to-b')!.sourcePort.x)
    expect(moved.get('to-a')!.sourcePort.x).toBeGreaterThan(moved.get('to-b')!.sourcePort.x)
  })

  it('allocates ports only from the supplied visible edge set', () => {
    const source = node('source', 0, 0)
    const nodes = [
      source,
      node('left', -120, 240),
      node('hidden-middle', 0, 240),
      node('right', 120, 240),
    ]
    const visibleEdges = [
      edge('left-edge', 'source', 'left'),
      edge('right-edge', 'source', 'right'),
    ]
    const visibleRoutes = buildEdgeRouting(nodes, visibleEdges)
    const allRoutes = buildEdgeRouting(nodes, [
      ...visibleEdges,
      edge('hidden-edge', 'source', 'hidden-middle'),
    ])

    expect(visibleRoutes.has('hidden-edge')).toBe(false)
    expect(visibleRoutes.get('left-edge')!.sourcePort.x).toBe(-7)
    expect(visibleRoutes.get('right-edge')!.sourcePort.x).toBe(7)
    expect(allRoutes.get('left-edge')!.sourcePort.x).toBe(-14)
    expect(allRoutes.get('right-edge')!.sourcePort.x).toBe(14)
  })
})

describe('parallel edge lanes', () => {
  it('separates overlapping horizontal trunk segments', () => {
    const nodes = [
      node('source-a', -200, 0, 80, 40),
      node('source-b', -50, 0, 80, 40),
      node('target-a', 50, 300, 80, 40),
      node('target-b', 200, 300, 80, 40),
    ]
    const routes = buildEdgeRouting(nodes, [
      edge('edge-a', 'source-a', 'target-a'),
      edge('edge-b', 'source-b', 'target-b'),
    ])
    const first = routes.get('edge-a')!
    const second = routes.get('edge-b')!

    expect(first.waypoints).toHaveLength(4)
    expect(second.waypoints).toHaveLength(4)
    expect(first.waypoints[1].y).not.toBe(second.waypoints[1].y)
    expect(first.laneOffset).not.toBe(0)
    expect(second.laneOffset).not.toBe(0)
  })

  it('separates overlapping straight segments without leaving card sides', () => {
    const nodes = [
      node('source-a', 0, 0, 80, 40),
      node('target-a', 0, 300, 80, 40),
      node('source-b', 0, 100, 80, 40),
      node('target-b', 0, 400, 80, 40),
    ]
    const routes = buildEdgeRouting(nodes, [
      edge('edge-a', 'source-a', 'target-a'),
      edge('edge-b', 'source-b', 'target-b'),
    ])
    const first = routes.get('edge-a')!
    const second = routes.get('edge-b')!

    expect(first.waypoints[0].x).not.toBe(second.waypoints[0].x)
    expect(first.sourcePort.x).toBeGreaterThanOrEqual(-28)
    expect(second.sourcePort.x).toBeLessThanOrEqual(28)
  })

  it('does not shift unrelated non-overlapping edge trunks', () => {
    const nodes = [
      node('source-a', -300, 0, 80, 40),
      node('target-a', -180, 300, 80, 40),
      node('source-b', 180, 0, 80, 40),
      node('target-b', 300, 300, 80, 40),
    ]
    const routes = buildEdgeRouting(nodes, [
      edge('edge-a', 'source-a', 'target-a'),
      edge('edge-b', 'source-b', 'target-b'),
    ])

    expect(routes.get('edge-a')!.laneOffset).toBe(0)
    expect(routes.get('edge-b')!.laneOffset).toBe(0)
  })

  it('derives visible SVG geometry from the packet waypoints', () => {
    const route = buildEdgeRouting(
      [node('source', -100, 0), node('target', 100, 240)],
      [edge('edge', 'source', 'target')],
    ).get('edge')!

    expect(route.waypoints[0]).toEqual(route.sourcePort)
    expect(route.waypoints[route.waypoints.length - 1]).toEqual(route.targetPort)
    expect(route.path).toBe(orthogonalPathFromWaypoints(route.waypoints))
  })
})

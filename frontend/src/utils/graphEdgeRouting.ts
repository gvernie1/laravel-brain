export interface RoutableNode {
  id: string
  x: number
  y: number
  width: number
  height: number
}

export interface RoutableEdge {
  id: string
  source: string
  target: string
}

export interface RoutingPoint {
  x: number
  y: number
}

export type PortSide = 'top' | 'right' | 'bottom' | 'left'
export type RoutingOrientation = 'vertical' | 'horizontal'

export interface EdgeRouting {
  edgeId: string
  sourceSide: PortSide
  targetSide: PortSide
  sourcePort: RoutingPoint
  targetPort: RoutingPoint
  orientation: RoutingOrientation
  laneOffset: number
  waypoints: RoutingPoint[]
  path: string
  labelPoint: RoutingPoint
}

export const EDGE_PORT_CORNER_PADDING = 12
export const EDGE_PORT_SLOT_GAP = 14
export const PARALLEL_LANE_GAP = 8
export const MAX_PARALLEL_LANE_SPAN = 48

const PARALLEL_LANE_TOLERANCE = 3
const MIN_PARALLEL_SEGMENT_LENGTH = 20
const ORTHOGONAL_CORNER_RADIUS = 7

interface RoutingSeed {
  edge: RoutableEdge
  source: RoutableNode
  target: RoutableNode
  sourceSide: PortSide
  targetSide: PortSide
  orientation: RoutingOrientation
  sourcePort?: RoutingPoint
  targetPort?: RoutingPoint
}

interface MainSegment {
  edgeId: string
  axis: RoutingOrientation
  lane: number
  start: number
  end: number
  kind: 'central' | 'straight'
}

function compareIds(a: string, b: string): number {
  if (a < b) return -1
  if (a > b) return 1
  return 0
}

function clamp(value: number, minimum: number, maximum: number): number {
  return Math.max(minimum, Math.min(maximum, value))
}

function sideAxis(side: PortSide): 'x' | 'y' {
  return side === 'top' || side === 'bottom' ? 'x' : 'y'
}

function sideLength(node: RoutableNode, side: PortSide): number {
  return sideAxis(side) === 'x' ? node.width : node.height
}

function sideCoordinate(node: RoutableNode, side: PortSide): number {
  return sideAxis(side) === 'x' ? node.x : node.y
}

function usableSideBounds(node: RoutableNode, side: PortSide): [number, number] {
  const length = sideLength(node, side)
  const padding = Math.min(EDGE_PORT_CORNER_PADDING, length / 2)
  const center = sideCoordinate(node, side)
  return [center - length / 2 + padding, center + length / 2 - padding]
}

function portPoint(node: RoutableNode, side: PortSide, offset: number): RoutingPoint {
  const [minimum, maximum] = usableSideBounds(node, side)
  const coordinate = clamp(sideCoordinate(node, side) + offset, minimum, maximum)

  switch (side) {
    case 'top':
      return { x: coordinate, y: node.y - node.height / 2 }
    case 'right':
      return { x: node.x + node.width / 2, y: coordinate }
    case 'bottom':
      return { x: coordinate, y: node.y + node.height / 2 }
    case 'left':
      return { x: node.x - node.width / 2, y: coordinate }
  }
}

function portOffset(slot: number, slotCount: number, length: number): number {
  if (slotCount <= 1) return 0
  const usableSpan = Math.max(0, length - EDGE_PORT_CORNER_PADDING * 2)
  const spread = Math.min(usableSpan, EDGE_PORT_SLOT_GAP * (slotCount - 1))
  return -spread / 2 + spread * slot / (slotCount - 1)
}

function pickPortSides(
  source: RoutableNode,
  target: RoutableNode,
): Pick<RoutingSeed, 'sourceSide' | 'targetSide' | 'orientation'> {
  const deltaX = target.x - source.x
  const deltaY = target.y - source.y
  const horizontalGap = Math.abs(deltaX) - (source.width + target.width) / 2
  const verticalGap = Math.abs(deltaY) - (source.height + target.height) / 2

  if (verticalGap >= horizontalGap) {
    return deltaY >= 0
      ? { sourceSide: 'bottom', targetSide: 'top', orientation: 'vertical' }
      : { sourceSide: 'top', targetSide: 'bottom', orientation: 'vertical' }
  }

  return deltaX >= 0
    ? { sourceSide: 'right', targetSide: 'left', orientation: 'horizontal' }
    : { sourceSide: 'left', targetSide: 'right', orientation: 'horizontal' }
}

function assignPortSlots(seeds: RoutingSeed[], endpoint: 'source' | 'target'): void {
  const groups = new Map<string, RoutingSeed[]>()
  for (const seed of seeds) {
    const node = endpoint === 'source' ? seed.source : seed.target
    const side = endpoint === 'source' ? seed.sourceSide : seed.targetSide
    const key = `${node.id}\0${side}`
    const group = groups.get(key) ?? []
    group.push(seed)
    groups.set(key, group)
  }

  for (const group of groups.values()) {
    const side = endpoint === 'source' ? group[0].sourceSide : group[0].targetSide
    const node = endpoint === 'source' ? group[0].source : group[0].target
    const perpendicularAxis = sideAxis(side)

    group.sort((left, right) => {
      const leftNeighbor = endpoint === 'source' ? left.target : left.source
      const rightNeighbor = endpoint === 'source' ? right.target : right.source
      const coordinateDifference = perpendicularAxis === 'x'
        ? leftNeighbor.x - rightNeighbor.x
        : leftNeighbor.y - rightNeighbor.y
      return coordinateDifference || compareIds(left.edge.id, right.edge.id)
    })

    group.forEach((seed, slot) => {
      const offset = portOffset(slot, group.length, sideLength(node, side))
      const point = portPoint(node, side, offset)
      if (endpoint === 'source') seed.sourcePort = point
      else seed.targetPort = point
    })
  }
}

function isDirectRoute(seed: RoutingSeed): boolean {
  const sourcePort = seed.sourcePort!
  const targetPort = seed.targetPort!
  if (seed.orientation === 'vertical') {
    return Math.abs(sourcePort.x - targetPort.x) < 3
      || Math.abs(targetPort.y - sourcePort.y) <= 8
  }
  return Math.abs(sourcePort.y - targetPort.y) < 3
    || Math.abs(targetPort.x - sourcePort.x) <= 8
}

function mainSegment(seed: RoutingSeed): MainSegment | null {
  const sourcePort = seed.sourcePort!
  const targetPort = seed.targetPort!

  if (isDirectRoute(seed)) {
    if (seed.orientation === 'vertical' && Math.abs(sourcePort.x - targetPort.x) < 3) {
      const start = Math.min(sourcePort.y, targetPort.y)
      const end = Math.max(sourcePort.y, targetPort.y)
      if (end - start < MIN_PARALLEL_SEGMENT_LENGTH) return null
      return {
        edgeId: seed.edge.id,
        axis: 'vertical',
        lane: (sourcePort.x + targetPort.x) / 2,
        start,
        end,
        kind: 'straight',
      }
    }
    if (seed.orientation === 'horizontal' && Math.abs(sourcePort.y - targetPort.y) < 3) {
      const start = Math.min(sourcePort.x, targetPort.x)
      const end = Math.max(sourcePort.x, targetPort.x)
      if (end - start < MIN_PARALLEL_SEGMENT_LENGTH) return null
      return {
        edgeId: seed.edge.id,
        axis: 'horizontal',
        lane: (sourcePort.y + targetPort.y) / 2,
        start,
        end,
        kind: 'straight',
      }
    }
    return null
  }

  if (seed.orientation === 'vertical') {
    const start = Math.min(sourcePort.x, targetPort.x)
    const end = Math.max(sourcePort.x, targetPort.x)
    if (end - start < MIN_PARALLEL_SEGMENT_LENGTH) return null
    return {
      edgeId: seed.edge.id,
      axis: 'horizontal',
      lane: (sourcePort.y + targetPort.y) / 2,
      start,
      end,
      kind: 'central',
    }
  }

  const start = Math.min(sourcePort.y, targetPort.y)
  const end = Math.max(sourcePort.y, targetPort.y)
  if (end - start < MIN_PARALLEL_SEGMENT_LENGTH) return null
  return {
    edgeId: seed.edge.id,
    axis: 'vertical',
    lane: (sourcePort.x + targetPort.x) / 2,
    start,
    end,
    kind: 'central',
  }
}

function segmentsConflict(left: MainSegment, right: MainSegment): boolean {
  if (left.axis !== right.axis) return false
  if (Math.abs(left.lane - right.lane) > PARALLEL_LANE_TOLERANCE) return false
  return Math.min(left.end, right.end) - Math.max(left.start, right.start)
    >= MIN_PARALLEL_SEGMENT_LENGTH
}

function assignParallelLanes(segments: MainSegment[]): Map<string, number> {
  const laneByEdge = new Map(segments.map((segment) => [segment.edgeId, segment.lane]))
  const orderedSegments = [...segments].sort((left, right) => compareIds(left.edgeId, right.edgeId))
  const visited = new Set<string>()

  for (const startingSegment of orderedSegments) {
    if (visited.has(startingSegment.edgeId)) continue
    const component: MainSegment[] = []
    const queue = [startingSegment]
    visited.add(startingSegment.edgeId)

    while (queue.length) {
      const segment = queue.shift()!
      component.push(segment)
      for (const candidate of orderedSegments) {
        if (visited.has(candidate.edgeId) || !segmentsConflict(segment, candidate)) continue
        visited.add(candidate.edgeId)
        queue.push(candidate)
      }
    }

    if (component.length < 2) continue
    component.sort((left, right) => left.lane - right.lane || compareIds(left.edgeId, right.edgeId))
    const center = component.reduce((sum, segment) => sum + segment.lane, 0) / component.length
    const span = Math.min(
      MAX_PARALLEL_LANE_SPAN,
      PARALLEL_LANE_GAP * (component.length - 1),
    )
    component.forEach((segment, slot) => {
      laneByEdge.set(
        segment.edgeId,
        center - span / 2 + span * slot / (component.length - 1),
      )
    })
  }

  return laneByEdge
}

function clampCentralLane(seed: RoutingSeed, lane: number): number {
  const sourcePort = seed.sourcePort!
  const targetPort = seed.targetPort!
  const sourceCoordinate = seed.orientation === 'vertical' ? sourcePort.y : sourcePort.x
  const targetCoordinate = seed.orientation === 'vertical' ? targetPort.y : targetPort.x
  const minimum = Math.min(sourceCoordinate, targetCoordinate) + 2
  const maximum = Math.max(sourceCoordinate, targetCoordinate) - 2
  return minimum <= maximum ? clamp(lane, minimum, maximum) : (sourceCoordinate + targetCoordinate) / 2
}

function moveStraightPortsToLane(seed: RoutingSeed, lane: number): number {
  const sourceBounds = usableSideBounds(seed.source, seed.sourceSide)
  const targetBounds = usableSideBounds(seed.target, seed.targetSide)
  const minimum = Math.max(sourceBounds[0], targetBounds[0])
  const maximum = Math.min(sourceBounds[1], targetBounds[1])
  if (minimum > maximum) return seed.orientation === 'vertical'
    ? seed.sourcePort!.x
    : seed.sourcePort!.y

  const coordinate = clamp(lane, minimum, maximum)
  if (seed.orientation === 'vertical') {
    seed.sourcePort = { ...seed.sourcePort!, x: coordinate }
    seed.targetPort = { ...seed.targetPort!, x: coordinate }
  } else {
    seed.sourcePort = { ...seed.sourcePort!, y: coordinate }
    seed.targetPort = { ...seed.targetPort!, y: coordinate }
  }
  return coordinate
}

function waypointsForSeed(seed: RoutingSeed, centralLane: number): RoutingPoint[] {
  const sourcePort = seed.sourcePort!
  const targetPort = seed.targetPort!
  if (isDirectRoute(seed)) return [sourcePort, targetPort]

  return seed.orientation === 'vertical'
    ? [
        sourcePort,
        { x: sourcePort.x, y: centralLane },
        { x: targetPort.x, y: centralLane },
        targetPort,
      ]
    : [
        sourcePort,
        { x: centralLane, y: sourcePort.y },
        { x: centralLane, y: targetPort.y },
        targetPort,
      ]
}

function clampCornerRadius(...distances: number[]): number {
  return Math.max(
    0,
    Math.min(ORTHOGONAL_CORNER_RADIUS, ...distances.map((distance) => distance - 1)),
  )
}

/** Build the visible rounded SVG path from the same waypoints used by packets. */
export function orthogonalPathFromWaypoints(waypoints: readonly RoutingPoint[]): string {
  if (!waypoints.length) return ''
  const first = waypoints[0]
  if (waypoints.length < 4) {
    const last = waypoints[waypoints.length - 1]
    return `M${first.x},${first.y} L${last.x},${last.y}`
  }

  const firstBend = waypoints[1]
  const secondBend = waypoints[2]
  const last = waypoints[3]
  const startsVertically = first.x === firstBend.x

  if (startsVertically) {
    const direction = last.y > first.y ? 1 : -1
    const radius = clampCornerRadius(
      Math.abs(firstBend.y - first.y),
      Math.abs(last.y - secondBend.y),
      Math.abs(secondBend.x - firstBend.x) / 2,
    )
    const horizontalRadius = secondBend.x > firstBend.x ? radius : -radius
    return radius > 0
      ? `M${first.x},${first.y} V${firstBend.y - radius * direction} Q${firstBend.x},${firstBend.y} ${firstBend.x + horizontalRadius},${firstBend.y} H${secondBend.x - horizontalRadius} Q${secondBend.x},${secondBend.y} ${secondBend.x},${secondBend.y + radius * direction} V${last.y}`
      : `M${first.x},${first.y} V${firstBend.y} H${secondBend.x} V${last.y}`
  }

  const direction = last.x > first.x ? 1 : -1
  const radius = clampCornerRadius(
    Math.abs(firstBend.x - first.x),
    Math.abs(last.x - secondBend.x),
    Math.abs(secondBend.y - firstBend.y) / 2,
  )
  const verticalRadius = secondBend.y > firstBend.y ? radius : -radius
  return radius > 0
    ? `M${first.x},${first.y} H${firstBend.x - radius * direction} Q${firstBend.x},${firstBend.y} ${firstBend.x},${firstBend.y + verticalRadius} V${secondBend.y - verticalRadius} Q${secondBend.x},${secondBend.y} ${secondBend.x + radius * direction},${secondBend.y} H${last.x}`
    : `M${first.x},${first.y} H${firstBend.x} V${secondBend.y} H${last.x}`
}

function labelPointForRoute(seed: RoutingSeed, waypoints: RoutingPoint[]): RoutingPoint {
  const sourcePort = seed.sourcePort!
  const targetPort = seed.targetPort!
  if (waypoints.length < 4) {
    return seed.orientation === 'vertical'
      ? { x: sourcePort.x + 6, y: (sourcePort.y + targetPort.y) / 2 }
      : { x: (sourcePort.x + targetPort.x) / 2, y: sourcePort.y - 10 }
  }

  if (seed.orientation === 'vertical') {
    const direction = targetPort.y > sourcePort.y ? 1 : -1
    return {
      x: (sourcePort.x + targetPort.x) / 2,
      y: waypoints[1].y - 14 * direction,
    }
  }

  const direction = targetPort.x > sourcePort.x ? 1 : -1
  return {
    x: waypoints[1].x + 6 * direction,
    y: (sourcePort.y + targetPort.y) / 2,
  }
}

/**
 * Route only the supplied edges. Callers control visibility by passing their
 * currently interactive edge set, so hidden edges never reserve ports/lanes.
 */
export function buildEdgeRouting(
  nodes: readonly RoutableNode[],
  edges: readonly RoutableEdge[],
): Map<string, EdgeRouting> {
  const nodeById = new Map(nodes.map((node) => [node.id, node]))
  const seeds = edges
    .map((edge): RoutingSeed | null => {
      const source = nodeById.get(edge.source)
      const target = nodeById.get(edge.target)
      if (!source || !target) return null
      return { edge, source, target, ...pickPortSides(source, target) }
    })
    .filter((seed): seed is RoutingSeed => seed !== null)
    .sort((left, right) => compareIds(left.edge.id, right.edge.id))

  assignPortSlots(seeds, 'source')
  assignPortSlots(seeds, 'target')

  const segments = seeds
    .map(mainSegment)
    .filter((segment): segment is MainSegment => segment !== null)
  const segmentByEdge = new Map(segments.map((segment) => [segment.edgeId, segment]))
  const laneByEdge = assignParallelLanes(segments)
  const routing = new Map<string, EdgeRouting>()

  for (const seed of seeds) {
    const segment = segmentByEdge.get(seed.edge.id)
    const baselineLane = segment?.lane ?? (seed.orientation === 'vertical'
      ? (seed.sourcePort!.y + seed.targetPort!.y) / 2
      : (seed.sourcePort!.x + seed.targetPort!.x) / 2)
    let assignedLane = laneByEdge.get(seed.edge.id) ?? baselineLane

    if (segment?.kind === 'straight') {
      assignedLane = moveStraightPortsToLane(seed, assignedLane)
    } else {
      assignedLane = clampCentralLane(seed, assignedLane)
    }

    const waypoints = waypointsForSeed(seed, assignedLane)
    routing.set(seed.edge.id, {
      edgeId: seed.edge.id,
      sourceSide: seed.sourceSide,
      targetSide: seed.targetSide,
      sourcePort: seed.sourcePort!,
      targetPort: seed.targetPort!,
      orientation: seed.orientation,
      laneOffset: assignedLane - baselineLane,
      waypoints,
      path: orthogonalPathFromWaypoints(waypoints),
      labelPoint: labelPointForRoute(seed, waypoints),
    })
  }

  return routing
}

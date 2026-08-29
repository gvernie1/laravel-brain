export interface LayerEdge {
  source: string
  target: string
}

export type LayerSweepDirection = 'down' | 'up'

export const DEFAULT_LAYER_ORDERING_ITERATIONS = 6

interface LayerPositions {
  rankByNode: Map<string, number>
  positionByNode: Map<string, number>
}

interface NeighborMaps {
  parents: Map<string, Set<string>>
  children: Map<string, Set<string>>
}

function copyLayers(layers: readonly (readonly string[])[]): string[][] {
  return layers.map((layer) => [...layer])
}

function normalizedPosition(index: number, layerSize: number): number {
  return layerSize <= 1 ? 0.5 : index / (layerSize - 1)
}

function compareNodeIds(a: string, b: string): number {
  if (a < b) return -1
  if (a > b) return 1
  return 0
}

function buildLayerPositions(layers: readonly (readonly string[])[]): LayerPositions {
  const rankByNode = new Map<string, number>()
  const positionByNode = new Map<string, number>()

  layers.forEach((layer, rank) => {
    layer.forEach((nodeId, position) => {
      rankByNode.set(nodeId, rank)
      positionByNode.set(nodeId, normalizedPosition(position, layer.length))
    })
  })

  return { rankByNode, positionByNode }
}

function buildNeighborMaps(
  nodeIds: ReadonlySet<string>,
  edges: readonly LayerEdge[],
): NeighborMaps {
  const parents = new Map<string, Set<string>>()
  const children = new Map<string, Set<string>>()

  for (const edge of edges) {
    if (!nodeIds.has(edge.source) || !nodeIds.has(edge.target)) continue

    if (!parents.has(edge.target)) parents.set(edge.target, new Set())
    if (!children.has(edge.source)) children.set(edge.source, new Set())
    parents.get(edge.target)!.add(edge.source)
    children.get(edge.source)!.add(edge.target)
  }

  return { parents, children }
}

function closestNeighborBarycenter(
  nodeId: string,
  rank: number,
  direction: LayerSweepDirection,
  positions: LayerPositions,
  neighbors: NeighborMaps,
): number | undefined {
  const candidateIds = direction === 'down'
    ? neighbors.parents.get(nodeId)
    : neighbors.children.get(nodeId)
  if (!candidateIds?.size) return undefined

  let closestDistance = Number.POSITIVE_INFINITY
  const closestPositions: number[] = []

  for (const candidateId of candidateIds) {
    const candidateRank = positions.rankByNode.get(candidateId)
    const candidatePosition = positions.positionByNode.get(candidateId)
    if (candidateRank == null || candidatePosition == null) continue

    const distance = direction === 'down'
      ? rank - candidateRank
      : candidateRank - rank
    if (distance <= 0 || distance > closestDistance) continue

    if (distance < closestDistance) {
      closestDistance = distance
      closestPositions.length = 0
    }
    closestPositions.push(candidatePosition)
  }

  if (!closestPositions.length) return undefined
  return closestPositions.reduce((sum, position) => sum + position, 0) / closestPositions.length
}

function orderLayer(
  layers: string[][],
  rank: number,
  direction: LayerSweepDirection,
  neighbors: NeighborMaps,
): void {
  const layer = layers[rank]
  if (layer.length < 2) return

  const positions = buildLayerPositions(layers)
  const entries = layer.map((nodeId, previousIndex) => {
    const barycenter = closestNeighborBarycenter(
      nodeId,
      rank,
      direction,
      positions,
      neighbors,
    )

    return {
      nodeId,
      previousIndex,
      // An unconnected node remains anchored to its previous relative
      // position instead of receiving an arbitrary extreme value.
      score: barycenter ?? normalizedPosition(previousIndex, layer.length),
    }
  })

  entries.sort((a, b) => {
    const scoreDifference = a.score - b.score
    if (Math.abs(scoreDifference) > Number.EPSILON) return scoreDifference

    const previousOrder = a.previousIndex - b.previousIndex
    if (previousOrder !== 0) return previousOrder
    return compareNodeIds(a.nodeId, b.nodeId)
  })

  layers[rank] = entries.map((entry) => entry.nodeId)
}

function totalNormalizedEdgeLength(
  layers: readonly (readonly string[])[],
  edges: readonly LayerEdge[],
): number {
  const positions = buildLayerPositions(layers)
  const seen = new Set<string>()
  let length = 0

  for (const edge of edges) {
    const sourceRank = positions.rankByNode.get(edge.source)
    const targetRank = positions.rankByNode.get(edge.target)
    const sourcePosition = positions.positionByNode.get(edge.source)
    const targetPosition = positions.positionByNode.get(edge.target)
    if (
      sourceRank == null
      || targetRank == null
      || sourcePosition == null
      || targetPosition == null
      || sourceRank === targetRank
    ) continue

    const edgeKey = `${edge.source}\0${edge.target}`
    if (seen.has(edgeKey)) continue
    seen.add(edgeKey)

    // Long edges are useful as a deterministic tie-breaker, but their
    // influence decreases with each skipped rank so they cannot dominate
    // the adjacent-rank crossing objective.
    length += Math.abs(sourcePosition - targetPosition) / Math.abs(sourceRank - targetRank)
  }

  return length
}

/**
 * Build the same longest-path breadth-first ranks used by the graph layout.
 * Returned layers have a deterministic node-ID baseline order.
 */
export function buildBreadthFirstLayers(
  nodeIds: readonly string[],
  edges: readonly LayerEdge[],
): string[][] {
  const ids = new Set(nodeIds)
  const adjacency = new Map<string, string[]>()
  const indegree = new Map<string, number>()
  for (const nodeId of nodeIds) {
    adjacency.set(nodeId, [])
    indegree.set(nodeId, 0)
  }
  for (const edge of edges) {
    if (!ids.has(edge.source) || !ids.has(edge.target)) continue
    adjacency.get(edge.source)!.push(edge.target)
    indegree.set(edge.target, (indegree.get(edge.target) ?? 0) + 1)
  }

  const roots = nodeIds.filter((nodeId) => indegree.get(nodeId) === 0)
  const level = new Map<string, number>()
  const queue = [...roots]
  for (const root of roots) level.set(root, 0)

  while (queue.length) {
    const nodeId = queue.shift()!
    const nodeLevel = level.get(nodeId)!
    for (const childId of adjacency.get(nodeId) ?? []) {
      const childLevel = nodeLevel + 1
      if (!level.has(childId) || level.get(childId)! < childLevel) {
        level.set(childId, childLevel)
        queue.push(childId)
      }
    }
  }

  for (const nodeId of nodeIds) {
    if (!level.has(nodeId)) level.set(nodeId, 0)
  }

  const layers = new Map<number, string[]>()
  for (const nodeId of nodeIds) {
    const rank = level.get(nodeId)!
    if (!layers.has(rank)) layers.set(rank, [])
    layers.get(rank)!.push(nodeId)
  }

  return [...layers.entries()]
    .sort(([rankA], [rankB]) => rankA - rankB)
    .map(([, layer]) => layer.sort(compareNodeIds))
}

/** Perform one deterministic barycenter sweep without changing rank membership. */
export function sweepLayerOrder(
  inputLayers: readonly (readonly string[])[],
  edges: readonly LayerEdge[],
  direction: LayerSweepDirection,
): string[][] {
  const layers = copyLayers(inputLayers)
  const nodeIds = new Set(layers.flat())
  const neighbors = buildNeighborMaps(nodeIds, edges)

  if (direction === 'down') {
    for (let rank = 1; rank < layers.length; rank++) {
      orderLayer(layers, rank, direction, neighbors)
    }
  } else {
    for (let rank = layers.length - 2; rank >= 0; rank--) {
      orderLayer(layers, rank, direction, neighbors)
    }
  }

  return layers
}

/** Count geometric crossings formed by unique edges between adjacent ranks. */
export function countAdjacentRankCrossings(
  layers: readonly (readonly string[])[],
  edges: readonly LayerEdge[],
): number {
  const positions = buildLayerPositions(layers)
  const edgesByRank = new Map<number, Array<{ upper: number; lower: number }>>()
  const seen = new Set<string>()

  for (const edge of edges) {
    const sourceRank = positions.rankByNode.get(edge.source)
    const targetRank = positions.rankByNode.get(edge.target)
    const sourcePosition = positions.positionByNode.get(edge.source)
    const targetPosition = positions.positionByNode.get(edge.target)
    if (
      sourceRank == null
      || targetRank == null
      || sourcePosition == null
      || targetPosition == null
      || Math.abs(sourceRank - targetRank) !== 1
    ) continue

    const upperId = sourceRank < targetRank ? edge.source : edge.target
    const lowerId = sourceRank < targetRank ? edge.target : edge.source
    const edgeKey = `${upperId}\0${lowerId}`
    if (seen.has(edgeKey)) continue
    seen.add(edgeKey)

    const upperRank = Math.min(sourceRank, targetRank)
    const rankEdges = edgesByRank.get(upperRank) ?? []
    rankEdges.push(sourceRank < targetRank
      ? { upper: sourcePosition, lower: targetPosition }
      : { upper: targetPosition, lower: sourcePosition })
    edgesByRank.set(upperRank, rankEdges)
  }

  let crossings = 0
  for (const rankEdges of edgesByRank.values()) {
    for (let first = 0; first < rankEdges.length; first++) {
      for (let second = first + 1; second < rankEdges.length; second++) {
        const upperDifference = rankEdges[first].upper - rankEdges[second].upper
        const lowerDifference = rankEdges[first].lower - rankEdges[second].lower
        if (upperDifference * lowerDifference < 0) crossings++
      }
    }
  }

  return crossings
}

/**
 * Repeated downward/upward barycenter sweeps, retaining the best crossing
 * count encountered. Edge length is only used to break equal-count ties.
 */
export function orderLayersByBarycenter(
  inputLayers: readonly (readonly string[])[],
  edges: readonly LayerEdge[],
  iterations = DEFAULT_LAYER_ORDERING_ITERATIONS,
): string[][] {
  let current = copyLayers(inputLayers)
  let best = copyLayers(current)
  let bestCrossings = countAdjacentRankCrossings(best, edges)
  let bestEdgeLength = totalNormalizedEdgeLength(best, edges)

  const retainIfBetter = (): void => {
    const crossings = countAdjacentRankCrossings(current, edges)
    const edgeLength = totalNormalizedEdgeLength(current, edges)
    if (
      crossings < bestCrossings
      || (crossings === bestCrossings && edgeLength < bestEdgeLength - Number.EPSILON)
    ) {
      best = copyLayers(current)
      bestCrossings = crossings
      bestEdgeLength = edgeLength
    }
  }

  for (let iteration = 0; iteration < iterations; iteration++) {
    current = sweepLayerOrder(current, edges, 'down')
    retainIfBetter()
    current = sweepLayerOrder(current, edges, 'up')
    retainIfBetter()
  }

  return best
}

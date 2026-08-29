export interface SelectionState {
  selectedIds: Set<string>
  primaryId: string | null
}

export interface SelectableNode {
  id: string
  x: number
  y: number
  width: number
  height: number
}

export interface SelectableEdge {
  id: string
  source: string
  target: string
}

export interface Point {
  x: number
  y: number
}

export interface TransformLike {
  x: number
  y: number
  k: number
}

export type BackgroundGesture = 'ignore' | 'marquee' | 'pan'

function lastSelected(selectedIds: Set<string>): string | null {
  const ids = [...selectedIds]
  return ids[ids.length - 1] ?? null
}

export function selectNode(
  state: SelectionState,
  nodeId: string,
  additive: boolean,
): SelectionState {
  if (!additive) {
    return { selectedIds: new Set([nodeId]), primaryId: nodeId }
  }

  const selectedIds = new Set(state.selectedIds)
  if (selectedIds.has(nodeId)) {
    selectedIds.delete(nodeId)
    return {
      selectedIds,
      primaryId: state.primaryId === nodeId
        ? lastSelected(selectedIds)
        : state.primaryId,
    }
  }

  selectedIds.add(nodeId)
  return { selectedIds, primaryId: nodeId }
}

export function clearSelection(): SelectionState {
  return { selectedIds: new Set(), primaryId: null }
}

export function retainVisibleSelection(
  state: SelectionState,
  visibleIds: ReadonlySet<string>,
): SelectionState {
  const selectedIds = new Set(
    [...state.selectedIds].filter((id) => visibleIds.has(id)),
  )
  const primaryId = state.primaryId && selectedIds.has(state.primaryId)
    ? state.primaryId
    : lastSelected(selectedIds)

  return { selectedIds, primaryId }
}

export function prepareNodeDrag(
  state: SelectionState,
  nodeId: string,
  additive: boolean,
): { selection: SelectionState; draggedIds: Set<string> } {
  if (state.selectedIds.has(nodeId)) {
    return {
      selection: state,
      draggedIds: new Set(state.selectedIds),
    }
  }

  if (additive) {
    return {
      selection: state,
      draggedIds: new Set([nodeId]),
    }
  }

  const selection = selectNode(state, nodeId, false)
  return { selection, draggedIds: new Set([nodeId]) }
}

export function moveNodePositions(
  originalPositions: ReadonlyMap<string, Point>,
  dx: number,
  dy: number,
): Map<string, Point> {
  return new Map(
    [...originalPositions].map(([id, point]) => [
      id,
      { x: point.x + dx, y: point.y + dy },
    ]),
  )
}

export function incidentEdgeIds(
  edges: SelectableEdge[],
  selectedIds: ReadonlySet<string>,
  isVisible: (edge: SelectableEdge) => boolean = () => true,
): Set<string> {
  const edgeIds = new Set<string>()
  for (const edge of edges) {
    if (!isVisible(edge)) continue
    if (selectedIds.has(edge.source) || selectedIds.has(edge.target)) {
      edgeIds.add(edge.id)
    }
  }
  return edgeIds
}

export function backgroundGesture(button: number, shiftKey: boolean): BackgroundGesture {
  if (button !== 0) return 'ignore'
  return shiftKey ? 'marquee' : 'pan'
}

export function nodesIntersectingMarquee(
  nodes: SelectableNode[],
  visibleIds: ReadonlySet<string>,
  start: Point,
  end: Point,
  transform: TransformLike,
): Set<string> {
  const startX = (start.x - transform.x) / transform.k
  const startY = (start.y - transform.y) / transform.k
  const endX = (end.x - transform.x) / transform.k
  const endY = (end.y - transform.y) / transform.k
  const left = Math.min(startX, endX)
  const right = Math.max(startX, endX)
  const top = Math.min(startY, endY)
  const bottom = Math.max(startY, endY)
  const selectedIds = new Set<string>()

  for (const node of nodes) {
    if (!visibleIds.has(node.id)) continue
    const nodeLeft = node.x - node.width / 2
    const nodeRight = node.x + node.width / 2
    const nodeTop = node.y - node.height / 2
    const nodeBottom = node.y + node.height / 2
    if (
      nodeRight >= left &&
      nodeLeft <= right &&
      nodeBottom >= top &&
      nodeTop <= bottom
    ) {
      selectedIds.add(node.id)
    }
  }

  return selectedIds
}

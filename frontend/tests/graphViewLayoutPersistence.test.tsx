import { createRef } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { GraphView } from '../src/components/GraphView'
import type { GraphElement, GraphViewportRef } from '../src/types/graph'
import {
  buildGraphLayoutStorageKey,
  serializeGraphLayout,
  type GraphLayoutStorage,
} from '../src/utils/graphLayoutPersistence'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('GraphView persisted layout restore', () => {
  it('restores model-space positions on every remount and keeps reset available', () => {
    const values = new Map<string, string>()
    const storage: GraphLayoutStorage = {
      getItem: (key) => values.get(key) ?? null,
      setItem: (key, value) => { values.set(key, value) },
      removeItem: (key) => { values.delete(key) },
    }
    const origin = 'http://brain.test'
    vi.stubGlobal('window', {
      location: { origin },
      localStorage: storage,
    })

    const projectId = 'Test Project'
    const graphId = 'route-orders-store'
    const storageKey = buildGraphLayoutStorageKey({
      projectId,
      graphId,
      locationId: `${origin}${import.meta.env.BASE_URL}`,
      layout: 'breadthfirst',
      rankDir: 'TB',
      compact: false,
    })
    values.set(storageKey, serializeGraphLayout(new Map([
      ['controller', { x: 321, y: 654 }],
    ])))
    const elements: GraphElement[] = [
      { data: { id: 'controller', label: 'Controller@store', type: 'controller' } },
    ]

    const renderGraph = () => renderToStaticMarkup(
      <GraphView
        elements={elements}
        projectId={projectId}
        graphId={graphId}
        layout="breadthfirst"
        rankDir="TB"
        searchQuery=""
        visibleTypes={new Set(['controller'])}
        theme="dark"
        onNodeSelect={() => undefined}
        graphRef={createRef<GraphViewportRef>()}
        complexityOverlay={false}
        onLayoutChange={() => undefined}
        onRankDirChange={() => undefined}
        onToggleComplexityOverlay={() => undefined}
        onToggleSecurityOverlay={() => undefined}
        onToggleCompact={() => undefined}
      />,
    )

    const firstMount = renderGraph()
    const secondMount = renderGraph()
    const resetButton = firstMount.match(/<button[^>]*>Reset layout<\/button>/)?.[0]

    expect(firstMount).toContain('transform="translate(321,654)"')
    expect(secondMount).toContain('transform="translate(321,654)"')
    expect(resetButton).toBeDefined()
    expect(resetButton).not.toContain('disabled')
  })
})

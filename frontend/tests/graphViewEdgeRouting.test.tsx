import { createRef } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { GraphView } from '../src/components/GraphView'
import type { GraphElement, GraphViewportRef } from '../src/types/graph'

function attribute(tag: string, name: string): string | undefined {
  return tag.match(new RegExp(`${name}="([^"]*)"`))?.[1]
}

describe('GraphView edge geometry', () => {
  it('uses the routed SVG path for both the visible edge and its wide hit target', () => {
    const elements: GraphElement[] = [
      { data: { id: 'controller', label: 'Controller@store', type: 'controller' } },
      { data: { id: 'service', label: 'Service@execute', type: 'service' } },
      {
        data: {
          id: 'controller-service',
          source: 'controller',
          target: 'service',
          label: 'calls',
        },
      },
    ]
    const graphRef = createRef<GraphViewportRef>()

    const markup = renderToStaticMarkup(
      <GraphView
        elements={elements}
        projectId="Test Project"
        graphId="test-route"
        layout="breadthfirst"
        rankDir="TB"
        searchQuery=""
        visibleTypes={new Set(['controller', 'service'])}
        theme="dark"
        onNodeSelect={() => undefined}
        graphRef={graphRef}
        complexityOverlay={false}
        onLayoutChange={() => undefined}
        onRankDirChange={() => undefined}
        onToggleComplexityOverlay={() => undefined}
        onToggleSecurityOverlay={() => undefined}
        onToggleCompact={() => undefined}
      />,
    )
    const pathTags = markup.match(/<path\b[^>]*>/g) ?? []
    const hitTarget = pathTags.find((tag) => tag.includes('data-edge-hit-target="true"'))
    const visibleEdge = pathTags.find((tag) => tag.includes('marker-end="url(#arrow-def)"'))

    expect(hitTarget).toBeDefined()
    expect(visibleEdge).toBeDefined()
    expect(attribute(hitTarget!, 'd')).toBe(attribute(visibleEdge!, 'd'))
    expect(attribute(hitTarget!, 'stroke')).toBe('transparent')
  })
})

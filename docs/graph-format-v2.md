# Laravel Brain graph format v2

This document defines the persisted factual-graph contract for machine consumers. The manifest's `graphFormatVersion` field is authoritative; the current value is `2`.

## Canonical and presentation graphs

Every completed scan publishes:

- one authoritative canonical graph with manifest identity `full`;
- one manifest describing the snapshot and selectable tabs;
- route, command, job, schedule, channel, Filament, and ERD presentation graphs.

The filesystem driver stores the canonical graph as `.graph-full.json`. The database driver stores it under the private `__full__` key. Consumers should use the `GraphStore::getFullGraph()` operation rather than depend on either backend representation.

The manifest advertises the artifact without exposing backend details:

```json
{
  "graphFormatVersion": 2,
  "canonicalGraph": {
    "available": true,
    "identity": "full"
  },
  "totalNodes": 123,
  "totalEdges": 456
}
```

`totalNodes` and `totalEdges` describe the canonical graph. Tab graphs are UI projections; their union is not guaranteed to reproduce it. The ERD is a specialized model/schema projection and may contain ERD-only metadata.

## Identity and vocabulary

Node IDs are deterministic factual identities, not labels:

- `action::<controller FQCN>::<method>` is reserved for an actual discovered route action.
- Other method nodes use a normalized FQCN plus `::<method>`.
- Commands use `command::<canonical command name>`.
- Schedules use `schedule::<stable content hash>`.
- Middleware identities distinguish aliases, groups, and classes.

`node.type` and `ownerKind` use `snake_case`. Relationship roles in `edge.type` use kebab-case, commonly `<source-role>-to-<target-role>`. Those are separate vocabularies; `abstract_class` and `abstract-class-to-service` are intentional.

Repeated edges can represent repeated call occurrences and must not be blindly deduplicated. Edge IDs are content-addressed with stable occurrence suffixes.

## Ownership and provenance

Class/artifact nodes use:

- `fqcn`: represented class when one exists;
- `ownerKind`: strongest known semantic role;
- `sourceScope`: scope of the represented artifact or definition;
- `file` / `relativeFile`: primary navigation source.

Method nodes additionally distinguish receiver and declaration:

- `receiverFqcn`, `receiverScope`, `receiverFile`, `relativeReceiverFile`;
- `declaringFqcn`, `declaringScope`, `declaringFile`, `relativeDeclaringFile`.

For method-backed nodes, `sourceScope` equals declaration scope. `file` remains the primary navigable source for the represented node and does not necessarily correspond to `sourceScope`; use receiver/declaring fields for precise method provenance.

Scopes are `application`, `framework`, `vendor`, `runtime`, or `unknown`. Semantic ownership is orthogonal to provenance: a vendor abstract class method has `ownerKind: abstract_class` and `receiverScope: vendor`.

Schedule roots describe definition and target separately:

- `sourceScope` and `definitionScope`: scope of the schedule declaration;
- `targetScope`: scope of the resolved target;
- `targetResolution`: `local` or `unresolved`.

Closure commands have `ownerKind: command` but no fabricated implementation FQCN or declaring-method fields.

## Unknown facts

Missing, `null`, `unknown`, or `unresolved` means Brain could not prove that fact statically. It is not an instruction for downstream consumers to guess. Dynamic container bindings, arbitrary runtime values, and similar PHP behavior may legitimately remain unresolved.

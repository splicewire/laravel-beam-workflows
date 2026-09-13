# 0001 — Required reactions share the transition transaction

Status: accepted
Date: 2026-09-13

## Context

A committed publication can require distribution or a later expiry. Process-local after-commit
events can be lost on exit. Display activity is intentionally lossy. Calendar intents/attempts and
host Circuit handoffs already supply durable downstream boundaries.

## Decision

The reusable workflows package records required obligations alongside transition facts and marking,
on the subject's connection. Configuration freezes the prepared destination and trusted principal;
capture freezes the source artifact through a host snapshot port. Delivery converges on neutral
calendar action identity. Host adapters own Composition snapshots and Circuit handoffs; the generic
workflow model depends on neither.

Display and notifications remain best effort after commit. They neither authorize execution nor
stand in for required work. Downstream execution rechecks current policy. Publication and
distribution outcomes stay distinct. Binding paths stop causal re-entry. A durable per-binding
ordinal orders legitimate same-time publication cycles for relative replacement.

## Consequences

Hosts converge tables before capture, bind real policy/context/snapshot ports and sweep in the
correct tenant. A committed obligation survives a crash before dispatch; repeated delivery finds
one local intent. Terminal history remains and failed work is explicitly recoverable. External
side effects need their own idempotency/reconciliation contract. No general event bus is introduced.

Operational contracts and examples: [required reactions](../required-reactions.md).
